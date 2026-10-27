#include <stdbool.h>
#include <stdio.h>
#include <unistd.h>

#include "esp_err.h"
#include "esp_log.h"
#include "esp_system.h"
#include "esp_vfs_fat.h"
#include "sdmmc_cmd.h"
#include "phpbm.h"

#if PHPBM_MICROSD_SDMMC
#include "driver/gpio.h"
#include "driver/sdmmc_host.h"
#include "sd_pwr_ctrl_by_on_chip_ldo.h"
#else
#include "driver/sdspi_host.h"
#include "driver/spi_common.h"
#endif

#define MOUNT_POINT "/sdcard"
#define SOURCE_DIR MOUNT_POINT "/app" /* managed by phpbm sync; the rest of the card is the scripts' */

static const char *TAG = "phpbm";
static sdmmc_card_t *s_card;

#if PHPBM_MICROSD_SDMMC
/* SDMMC slot 0 on the board's pins (default: its IO MUX pins, the P4 reference wiring), card IO powered
 * by on-chip LDO channel 4. Slot 1 stays free for esp_hosted's SDIO link to a C6. */
#define SD_LDO_CHAN 4

static sd_pwr_ctrl_handle_t s_pwr;

static esp_err_t sd_mount(int width, int freq_khz)
{
    esp_vfs_fat_sdmmc_mount_config_t mount = {
        .format_if_mount_failed = false,
        .max_files = 8,
        .allocation_unit_size = 16 * 1024,
    };
    sdmmc_host_t host = SDMMC_HOST_DEFAULT();
    host.slot = SDMMC_HOST_SLOT_0;
    host.max_freq_khz = freq_khz;
    host.pwr_ctrl_handle = s_pwr;
    sdmmc_slot_config_t slot = SDMMC_SLOT_CONFIG_DEFAULT();
    slot.clk = PHPBM_MICROSD_CLK;
    slot.cmd = PHPBM_MICROSD_CMD;
    slot.d0 = PHPBM_MICROSD_D0;
    slot.d1 = PHPBM_MICROSD_D1;
    slot.d2 = PHPBM_MICROSD_D2;
    slot.d3 = PHPBM_MICROSD_D3;
    slot.d4 = slot.d5 = slot.d6 = slot.d7 = GPIO_NUM_NC;
    slot.width = width;
    slot.flags |= SDMMC_SLOT_FLAG_INTERNAL_PULLUP;
    return esp_vfs_fat_sdmmc_mount(MOUNT_POINT, &host, &slot, &mount, &s_card);
}

static esp_err_t sd_bus_mount(void)
{
#if PHPBM_MICROSD_PWR_EN >= 0
    /* The card's VDD is switched by a P-MOSFET: low powers it. */
    gpio_config_t io = {.pin_bit_mask = 1ULL << PHPBM_MICROSD_PWR_EN, .mode = GPIO_MODE_OUTPUT};
    if (gpio_config(&io) == ESP_OK) {
        gpio_set_level(PHPBM_MICROSD_PWR_EN, 0);
    }
#endif
    sd_pwr_ctrl_ldo_config_t ldo = {.ldo_chan_id = SD_LDO_CHAN};
    esp_err_t err = sd_pwr_ctrl_new_on_chip_ldo(&ldo, &s_pwr);
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "microSD power: on-chip LDO channel %d unavailable (%s)", SD_LDO_CHAN, esp_err_to_name(err));
        return err;
    }
    err = sd_mount(4, SDMMC_FREQ_DEFAULT);
    if (err != ESP_OK) {
        ESP_LOGW(TAG, "microSD mount failed (%s), retrying 1-bit at %d kHz", esp_err_to_name(err), SDMMC_FREQ_PROBING);
        err = sd_mount(1, SDMMC_FREQ_PROBING);
    }
    return err;
}

static void sd_bus_free(void)
{
    if (s_pwr != NULL) {
        sd_pwr_ctrl_del_on_chip_ldo(s_pwr);
        s_pwr = NULL;
    }
#if PHPBM_MICROSD_PWR_EN >= 0
    /* Released to its reset state (input with pull-up), which switches the card's VDD off. */
    gpio_reset_pin(PHPBM_MICROSD_PWR_EN);
#endif
}
#else
#define SD_HOST SPI3_HOST /* SPI2_HOST is the W5500's */

static bool s_bus;

static esp_err_t sd_mount(int freq_khz)
{
    esp_vfs_fat_sdmmc_mount_config_t mount = {
        .format_if_mount_failed = false,
        .max_files = 8,
        .allocation_unit_size = 16 * 1024,
    };
    sdmmc_host_t host = SDSPI_HOST_DEFAULT();
    host.slot = SD_HOST;
    host.max_freq_khz = freq_khz;
    sdspi_device_config_t slot = SDSPI_DEVICE_CONFIG_DEFAULT();
    slot.gpio_cs = PHPBM_MICROSD_CS;
    slot.host_id = SD_HOST;
    return esp_vfs_fat_sdspi_mount(MOUNT_POINT, &host, &slot, &mount, &s_card);
}

static esp_err_t sd_bus_mount(void)
{
    spi_bus_config_t bus = {
        .mosi_io_num = PHPBM_MICROSD_MOSI,
        .miso_io_num = PHPBM_MICROSD_MISO,
        .sclk_io_num = PHPBM_MICROSD_SCLK,
        .quadwp_io_num = -1,
        .quadhd_io_num = -1,
        .max_transfer_sz = 4000,
    };
    esp_err_t err = spi_bus_initialize(SD_HOST, &bus, SPI_DMA_CH_AUTO);
    if (err == ESP_OK) {
        s_bus = true;
        err = sd_mount(SDMMC_FREQ_DEFAULT);
        if (err != ESP_OK) {
            ESP_LOGW(TAG, "microSD mount failed (%s), retrying at %d kHz", esp_err_to_name(err), SDMMC_FREQ_PROBING);
            err = sd_mount(SDMMC_FREQ_PROBING);
        }
    }
    return err;
}

static void sd_bus_free(void)
{
    if (s_bus) {
        spi_bus_free(SD_HOST);
        s_bus = false;
    }
}
#endif

const char *phpbm_storage_mount(void)
{
    esp_err_t err = sd_bus_mount();
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "microSD: %s", esp_err_to_name(err));
        printf("--- stopped: no microSD card ---\n");
        phpbm_storage_unmount();
        return NULL;
    }
    printf("phpbm: microSD %s, %llu MB, on %s\n", s_card->cid.name,
           (unsigned long long) s_card->csd.capacity * s_card->csd.sector_size / (1024 * 1024), MOUNT_POINT);
    if (phpbm_sync(SOURCE_DIR, MOUNT_POINT)) {
        printf("phpbm: sync session over, restarting\n");
        fflush(stdout);
        phpbm_storage_unmount();
        esp_restart();
    }
    if (access(SOURCE_DIR "/" PHPBM_APP_ENTRY, R_OK) != 0) {
        printf("--- stopped: no %s on the microSD card (run phpbm sync) ---\n", PHPBM_APP_ENTRY);
        phpbm_storage_unmount();
        return NULL;
    }
    return SOURCE_DIR;
}

void phpbm_storage_unmount(void)
{
    if (s_card != NULL) {
        esp_vfs_fat_sdcard_unmount(MOUNT_POINT, s_card);
        s_card = NULL;
    }
    sd_bus_free();
}
