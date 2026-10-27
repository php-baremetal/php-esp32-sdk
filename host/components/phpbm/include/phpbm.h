#pragma once

#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

struct _zval_struct;
struct _zend_array;

const char *phpbm_storage_mount(void);
void phpbm_storage_unmount(void);
void phpbm_runtime_run(const char *entry_script);
/* The directory the project sources are mounted at (where the options' relative paths start). */
const char *phpbm_source_dir(void);
/* microsd only: serves `phpbm sync` on the console for 1.5 s, copying the sources into dir
 * (free space reported for the volume at mount); true when a session ran, and the caller restarts. */
bool phpbm_sync(const char *dir, const char *mount);

/* Implemented by the board's network component (networks/<type>): brings the
 * network up and returns true with the IPv4 address in ip once it has one.
 * A Wi-Fi companion (PHPBM_NETWORK_COMPANION) only brings its link up: ip stays empty (may be NULL). */
bool phpbm_network_up(char *ip, size_t len);
/* The IPv4 address of the default netif right now, "" without one (PHP task only). */
const char *phpbm_network_ip(void);

/* Reserves PHP's memory pool (PSRAM) and hands its chunks
 * to the Zend memory manager; call before php_embed_init. */
void phpbm_memory_init(void);
/* Default memory_limit in bytes: the pool size (free heap minus a reserve, whole chunks). */
size_t phpbm_memory_limit(void);
/* PSRAM in bytes kept for the firmware: the firmware-reserve option, capped at a quarter of the free PSRAM (0 below 4 MB of PSRAM). */
size_t phpbm_memory_reserve(void);
/* PSRAM in bytes set aside for OPcache; 0 without it, and below 4 MB of PSRAM, where it is disabled. */
size_t phpbm_memory_opcache(void);
/* Stack of the PHP task (PSRAM), and the smaller one used with less than 4 MB of PSRAM. */
#define PHPBM_PHP_STACK (256 * 1024)
#define PHPBM_PHP_STACK_SMALL (128 * 1024)
/* Stack of the PHP task in bytes: PHPBM_PHP_STACK, or PHPBM_PHP_STACK_SMALL below 4 MB of PSRAM. */
size_t phpbm_php_stack_size(void);

bool phpbm_run_file(const char *path);
void phpbm_register_server_vars(const char *entry_script);
/* Adds the phpbm entries (SCRIPT_FILENAME, SCRIPT_NAME, PHP_SELF, SERVER_SOFTWARE, SERVER_ADDR) to server. */
void phpbm_server_vars(struct _zval_struct *server, const char *entry_script);

/* Calls into PHP from the firmware: fn runs with a frame that keeps uncaught
 * exceptions, which are then handled according to the on-error option. */
void phpbm_invoke(void (*fn)(void *arg), void *arg);
/* Calls the PHP function `name` through phpbm_invoke. */
void phpbm_call(const char *name, uint32_t argc, struct _zval_struct *argv);
/* gc_collect_cycles() inside phpbm_invoke, so a throwing __destruct follows on-error. */
void phpbm_collect_garbage(void);

/* Runs body under zend_try and prints why the program stopped: end of script
 * (body returned true), exit() or a fatal error / uncaught exception. */
void phpbm_run_protected(bool (*body)(const char *entry_script), const char *entry_script);

/* Registers a function the host calls on the PHP task once the program has stopped (end, exit or fatal
 * error) and before the restart; at most 4, a function registered twice runs once. A server answers
 * its waiting clients there instead of dropping them. */
void phpbm_at_stop(void (*hook)(void));

/* Whether the power-save option is on: esp_pm is configured for automatic light sleep. */
bool phpbm_power_save(void);

/* What the firmware does once the program has stopped, instead of the restart-delay restart. */
typedef enum {
    PHPBM_AFTER_STOP_NONE,
    PHPBM_AFTER_STOP_RESTART_NOW,
    PHPBM_AFTER_STOP_DEEP_SLEEP,
} phpbm_after_stop_action_t;

typedef struct {
    phpbm_after_stop_action_t action;
    uint64_t sleep_us;  /* DEEP_SLEEP: wake-up timer, 0 = none */
    int wake_pin;       /* DEEP_SLEEP: GPIO that wakes the chip (RTC/LP IO), -1 = none */
    int wake_level;     /* the level of wake_pin that wakes it, 0 or 1 */
} phpbm_after_stop_t;

/* PHP task: records the action and ends the script the way exit() does, so the stop hooks run and the
 * storage is unmounted before app_main restarts or enters deep sleep. The first request wins. In
 * web-server the request ends normally and the server then stops. */
void phpbm_request_after_stop(const phpbm_after_stop_t *after);
/* The action requested so far, NULL when none. */
const phpbm_after_stop_t *phpbm_after_stop(void);
/* Why the chip started: "none" (power-on or a reset), "timer", "gpio" or "other" (a deep sleep wake-up). */
const char *phpbm_wake_cause(void);

/* The sdk option request-timeout (seconds, 0 = no limit): web-server requests, ext-web HTTP handlers. */
int phpbm_request_timeout(void);
/* Makes seconds the startup value of max_execution_time (every later request starts with it) and arms
 * it for the request open now (web-server). */
void phpbm_startup_time_limit(int seconds);

/* Sets a persistent ini default (called from the ini defaults hook, before the modules start). */
void phpbm_ini_set(struct _zend_array *ini, const char *name, const char *value);
/* With the session extension: registers the "memory" save handler, creates the session directory (microsd) and sets the session ini defaults. */
void phpbm_session_setup(struct _zend_array *ini);
