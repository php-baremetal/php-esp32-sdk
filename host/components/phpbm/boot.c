#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <unistd.h>

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "esp_app_desc.h"
#include "esp_event.h"
#include "esp_heap_caps.h"
#include "esp_idf_version.h"
#include "esp_log.h"
#include "esp_pm.h"
#include "esp_netif.h"
#include "esp_sleep.h"
#include "esp_system.h"
#include "driver/rtc_io.h"
#include "sdkconfig.h"

#include "php.h"
#include "php_embed.h"
#include "phpbm.h"
#include "phpbm_options.h"

#define PHPBM_STACK_MARGIN (16 * 1024)
/* PHP's minimum zend.reserved_stack_size here: ZEND_ALLOCA_MAX_SIZE + 16 KB. */
#define PHPBM_STACK_RESERVED_MIN (48 * 1024)
#define PHPBM_TASK_CORE 1
#define PHPBM_TASK_PRIORITY 5

extern const char *const phpbm_env[];
extern const size_t phpbm_env_count;

const char *phpbm_project = PHPBM_PROJECT_NAME;
const char *phpbm_board = PHPBM_BOARD_NAME;
const char *phpbm_version = "";
const char *phpbm_idf_version = "";

static const char *TAG = "phpbm";
static char s_entry[256];
static const char *s_source_dir = "";
static StaticTask_t s_task;
static TaskHandle_t s_main;

const char *phpbm_network_ip(void)
{
    static char ip[16];
    esp_netif_ip_info_t info;
    esp_netif_t *netif = esp_netif_get_default_netif();
    if (netif == NULL || esp_netif_get_ip_info(netif, &info) != ESP_OK || info.ip.addr == 0) {
        return "";
    }
    esp_ip4addr_ntoa(&info.ip, ip, sizeof(ip));
    return ip;
}

const char *phpbm_source_dir(void)
{
    return s_source_dir;
}

/* lwIP and the default event loop are always up, so PHP sockets never find them off. */
static void phpbm_network_start(void)
{
    ESP_ERROR_CHECK(esp_netif_init());
    esp_err_t err = esp_event_loop_create_default();
    if (err != ESP_OK && err != ESP_ERR_INVALID_STATE) {
        ESP_ERROR_CHECK(err);
    }
#if PHPBM_NETWORK_COMPANION
    /* The Wi-Fi companion's link only, with or without the network option: ext-wifi needs it. */
    phpbm_network_up(NULL, 0);
#endif
}

/* Host-managed networks (w5500, emac) come up after the storage, so the sync window does not wait on DHCP. */
static void phpbm_network_connect(void)
{
#if PHPBM_NETWORK_UP
    char ip[16];
    if (phpbm_network_up(ip, sizeof(ip))) {
        printf("phpbm: network %s %s\n", PHPBM_NETWORK_ACTIVE, ip);
    } else {
        printf("phpbm: network %s without an address\n", PHPBM_NETWORK_ACTIVE);
    }
#elif PHPBM_NETWORK_ENABLED
    printf("phpbm: network %s (connect from PHP with ext-wifi)\n", PHPBM_NETWORK_ACTIVE);
#endif
}

static size_t phpbm_ub_write(const char *str, size_t len)
{
    return fwrite(str, 1, len, stdout);
}

void phpbm_ini_set(HashTable *ini, const char *name, const char *value)
{
    zval zv;
    ZVAL_NEW_STR(&zv, zend_string_init(value, strlen(value), 1));
    zend_hash_str_update(ini, name, strlen(name), &zv);
}

#if PHPBM_PHP_EXT_OPCACHE
/* OPcache in PSRAM (patches 0006/0007/0012): the cache is opcache-memory KB, with an interned strings
 * buffer from 4 MB up and room for about one script per 4 KB. */
static void phpbm_opcache_ini(HashTable *ini)
{
    char value[16];
    int kb = PHPBM_OPTION_OPCACHE_MEMORY;
    int files = kb / 4 < 200 ? 200 : kb / 4;
    if (phpbm_memory_opcache() == 0) {
        /* Small-PSRAM board: phpbm_memory_init() reserved nothing for it. */
        phpbm_ini_set(ini, "opcache.enable", "0");
        phpbm_ini_set(ini, "opcache.enable_cli", "0");
        return;
    }
    phpbm_ini_set(ini, "opcache.enable", "1");
    phpbm_ini_set(ini, "opcache.enable_cli", "1");
    snprintf(value, sizeof(value), "%dK", kb);
    phpbm_ini_set(ini, "opcache.memory_consumption", value);
    snprintf(value, sizeof(value), "%d", kb / 4096);
    phpbm_ini_set(ini, "opcache.interned_strings_buffer", value);
    snprintf(value, sizeof(value), "%d", files);
    phpbm_ini_set(ini, "opcache.max_accelerated_files", value);
#if PHPBM_STORAGE_MICROSD
    phpbm_ini_set(ini, "opcache.validate_timestamps", PHPBM_OPTION_OPCACHE_VALIDATE ? "1" : "0");
#else
    /* The embedded image changes only with a flash. */
    phpbm_ini_set(ini, "opcache.validate_timestamps", "0");
#endif
    /* The clock may be unset (1970), which would make every script look too new to cache. */
    phpbm_ini_set(ini, "opcache.file_update_protection", "0");
}
#endif

static void phpbm_ini_defaults(HashTable *ini)
{
    char size[16];
    size_t stack = phpbm_php_stack_size();
    size_t reserved = stack / 4 > PHPBM_STACK_RESERVED_MIN ? stack / 4 : PHPBM_STACK_RESERVED_MIN;
    snprintf(size, sizeof(size), "%zu", stack - PHPBM_STACK_MARGIN);
    phpbm_ini_set(ini, "zend.max_allowed_stack_size", size);
    snprintf(size, sizeof(size), "%zu", reserved);
    phpbm_ini_set(ini, "zend.reserved_stack_size", size);
    snprintf(size, sizeof(size), "%zu", phpbm_memory_limit());
    phpbm_ini_set(ini, "memory_limit", size);
    phpbm_ini_set(ini, "display_errors", "1");
    phpbm_ini_set(ini, "log_errors", "0");
    phpbm_ini_set(ini, "expose_php", "0");
#if PHPBM_PHP_EXT_SESSION
    phpbm_session_setup(ini);
#endif
#if PHPBM_PHP_EXT_OPCACHE
    phpbm_opcache_ini(ini);
#endif
}

#define STOP_HOOKS 4
static void (*s_stop_hooks[STOP_HOOKS])(void);

void phpbm_at_stop(void (*hook)(void))
{
    for (int i = 0; i < STOP_HOOKS; i++) {
        if (s_stop_hooks[i] == hook) {
            return;
        }
        if (s_stop_hooks[i] == NULL) {
            s_stop_hooks[i] = hook;
            return;
        }
    }
}

int phpbm_request_timeout(void)
{
    return PHPBM_OPTION_REQUEST_TIMEOUT;
}

/* The embed SAPI forces max_execution_time 0 through its ini entries, which come after ini_defaults, so
 * the startup value is replaced here: a request's own ini_set() or set_time_limit() is undone when it
 * ends. The request open now (the init script's) was started with 0: it gets the same limit here, as
 * ini_get() reports it. */
void phpbm_startup_time_limit(int seconds)
{
    zend_ini_entry *entry = zend_hash_str_find_ptr(EG(ini_directives), ZEND_STRL("max_execution_time"));
    if (entry == NULL || entry->modified) {
        return;
    }
    char value[16];
    int len = snprintf(value, sizeof(value), "%d", seconds);
    if (entry->value != NULL) {
        zend_string_release(entry->value);
    }
    entry->value = zend_string_init(value, (size_t) len, 1);
    zend_set_timeout(seconds, 0);
}

static const char *phpbm_boot(const char *root)
{
    s_source_dir = root;
    snprintf(s_entry, sizeof(s_entry), "%s/%s", root, PHPBM_APP_ENTRY);
    if (access(s_entry, R_OK) != 0) {
        ESP_LOGE(TAG, "entry script %s not found", s_entry);
        return NULL;
    }

    for (size_t i = 0; i < phpbm_env_count; i++) {
        setenv(phpbm_env[2 * i], phpbm_env[2 * i + 1], 1);
    }
    phpbm_version = esp_app_get_description()->version;
    phpbm_idf_version = esp_get_idf_version();

    phpbm_memory_init();
    if (phpbm_memory_limit() == 0) {
        ESP_LOGE(TAG, "no PSRAM for the PHP memory pool");
        return NULL;
    }
    php_embed_module.ub_write = phpbm_ub_write;
    php_embed_module.ini_defaults = phpbm_ini_defaults;
    if (php_embed_init(0, NULL) == FAILURE) {
        ESP_LOGE(TAG, "the PHP engine failed to start");
        return NULL;
    }
    /* The embed SAPI marks the headers as sent; there are none here (no_headers), and the flag
     * would make session_start(), header() and setcookie() refuse in the first request. */
    SG(headers_sent) = 0;
    printf("%s %s: PHP %s on %s (%s), ESP-IDF %s, memory_limit %zu KB, core %d\n", phpbm_project, phpbm_version, PHP_VERSION,
           PHPBM_BOARD_NAME, CONFIG_IDF_TARGET, phpbm_idf_version, (size_t) (zend_ini_long(ZEND_STRL("memory_limit"), 0) / 1024),
           xPortGetCoreID());
    printf("phpbm: PSRAM free after start %zu KB (largest %zu KB)",
           heap_caps_get_free_size(MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT) / 1024,
           heap_caps_get_largest_free_block(MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT) / 1024);
    if (phpbm_memory_reserve() != (size_t) PHPBM_OPTION_FIRMWARE_RESERVE * 1024) {
        printf(", firmware reserve %zu KB", phpbm_memory_reserve() / 1024);
    }
#if PHPBM_PHP_EXT_OPCACHE
    if (phpbm_memory_opcache() != 0) {
        printf(", opcache %d KB", PHPBM_OPTION_OPCACHE_MEMORY);
    }
#endif
    if (phpbm_php_stack_size() != PHPBM_PHP_STACK) {
        printf(", PHP stack %zu KB", phpbm_php_stack_size() / 1024);
    }
    printf("\n");
    return s_entry;
}

static void phpbm_task(void *arg)
{
    setvbuf(stdout, NULL, _IOLBF, 256);
    setvbuf(stderr, NULL, _IOLBF, 256);

    const char *entry = phpbm_boot(arg);
    if (entry != NULL) {
        phpbm_runtime_run(entry);
        for (int i = 0; i < STOP_HOOKS && s_stop_hooks[i] != NULL; i++) {
            s_stop_hooks[i]();
        }
        php_embed_shutdown();
    }
    xTaskNotifyGive(s_main);
    vTaskSuspend(NULL);
}

static bool s_power_save;

bool phpbm_power_save(void)
{
    return s_power_save;
}

#if PHPBM_OPTION_POWER_SAVE_ON
static void phpbm_power_save_start(void)
{
    esp_pm_config_t pm = {
        .max_freq_mhz = CONFIG_ESP_DEFAULT_CPU_FREQ_MHZ,
        .min_freq_mhz = CONFIG_XTAL_FREQ,
        .light_sleep_enable = true,
    };
    if (esp_pm_configure(&pm) == ESP_OK) {
        s_power_save = true;
        printf("phpbm: power save on (light sleep, %d-%d MHz)\n", pm.min_freq_mhz, pm.max_freq_mhz);
    } else {
        printf("phpbm: warning: power save could not be configured, light sleep stays off\n");
    }
}
#endif

static const char *s_wake_cause = "none";

const char *phpbm_wake_cause(void)
{
    return s_wake_cause;
}

/* Read once at boot: later light sleeps change what esp_sleep reports. */
static void phpbm_wake_cause_read(void)
{
    uint32_t causes = esp_sleep_get_wakeup_causes();
    if (causes & BIT(ESP_SLEEP_WAKEUP_UNDEFINED)) {
        return;
    }
    if (causes & (BIT(ESP_SLEEP_WAKEUP_EXT0) | BIT(ESP_SLEEP_WAKEUP_EXT1) | BIT(ESP_SLEEP_WAKEUP_GPIO))) {
        s_wake_cause = "gpio";
    } else if (causes & BIT(ESP_SLEEP_WAKEUP_TIMER)) {
        s_wake_cause = "timer";
    } else {
        s_wake_cause = "other";
    }
    printf("phpbm: woke from deep sleep (%s)\n", s_wake_cause);
}

static bool phpbm_deep_sleep_wake_pin(int pin, int level)
{
#if SOC_PM_SUPPORT_EXT1_WAKEUP && SOC_RTCIO_INPUT_OUTPUT_SUPPORTED
    if (!esp_sleep_is_valid_wakeup_gpio(pin)) {
        return false;
    }
    /* The internal pull keeps the pin at the other level while the chip sleeps. */
    rtc_gpio_init(pin);
    if (level) {
        rtc_gpio_pullup_dis(pin);
        rtc_gpio_pulldown_en(pin);
    } else {
        rtc_gpio_pulldown_dis(pin);
        rtc_gpio_pullup_en(pin);
    }
    return esp_sleep_enable_ext1_wakeup_io(1ULL << pin, level ? ESP_EXT1_WAKEUP_ANY_HIGH : ESP_EXT1_WAKEUP_ANY_LOW) == ESP_OK;
#else
    (void) pin;
    (void) level;
    return false;
#endif
}

/* Returns only when the wake-up sources cannot be set: the caller restarts instead. */
static void phpbm_deep_sleep(const phpbm_after_stop_t *after)
{
    esp_sleep_disable_wakeup_source(ESP_SLEEP_WAKEUP_ALL);
    if (after->sleep_us > 0 && esp_sleep_enable_timer_wakeup(after->sleep_us) != ESP_OK) {
        printf("phpbm: deep sleep: invalid timer, restarting\n");
        return;
    }
    if (after->wake_pin >= 0 && !phpbm_deep_sleep_wake_pin(after->wake_pin, after->wake_level)) {
        printf("phpbm: deep sleep: GPIO %d cannot wake the chip, restarting\n", after->wake_pin);
        return;
    }
    fflush(stdout);
    fsync(fileno(stdout));
    /* A USB console host gets the last lines before the device goes away. */
    vTaskDelay(pdMS_TO_TICKS(100));
    esp_deep_sleep_start();
}

/* The PHP task has its stack in PSRAM, so everything that may disable the flash
 * cache (mounting, unmounting, restarting, sleeping) runs here, on the internal-RAM main task. */
void app_main(void)
{
    s_main = xTaskGetCurrentTaskHandle();
    /* phpbm sync reads it to tell a firmware without the microSD at once. */
    printf("phpbm: storage %s\n", PHPBM_STORAGE_NAME);
    phpbm_wake_cause_read();
#if PHPBM_OPTION_POWER_SAVE_ON
    phpbm_power_save_start();
#endif
    phpbm_network_start();
    const char *root = phpbm_storage_mount();
    if (root != NULL) {
        phpbm_network_connect();
        size_t size = phpbm_php_stack_size();
        StackType_t *stack = heap_caps_malloc(size, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
        if (stack == NULL) {
            ESP_LOGE(TAG, "no PSRAM for the %zu KB PHP stack", size / 1024);
        } else {
            xTaskCreateStaticPinnedToCore(phpbm_task, "php", size, (void *) root, PHPBM_TASK_PRIORITY, stack, &s_task, PHPBM_TASK_CORE);
            ulTaskNotifyTake(pdTRUE, portMAX_DELAY);
        }
        phpbm_storage_unmount();
    }
    const phpbm_after_stop_t *after = phpbm_after_stop();
    if (after != NULL && after->action == PHPBM_AFTER_STOP_DEEP_SLEEP) {
        phpbm_deep_sleep(after);
    }
    if (after == NULL && PHPBM_OPTION_RESTART_DELAY > 0) {
        printf("phpbm: restarting in %d s\n", PHPBM_OPTION_RESTART_DELAY);
        fflush(stdout);
        vTaskDelay(pdMS_TO_TICKS(PHPBM_OPTION_RESTART_DELAY * 1000));
    }
    fflush(stdout);
    esp_restart();
}
