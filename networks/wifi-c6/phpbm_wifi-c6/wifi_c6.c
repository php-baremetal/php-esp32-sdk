#include <stdbool.h>
#include <stddef.h>

#include "esp_event.h"
#include "esp_hosted.h"
#include "esp_hosted_event.h"
#include "esp_log.h"
#include "phpbm.h"

static const char *TAG = "phpbm";

/* RESTART_ON_FAILURE is off (networks/wifi-c6/sdkconfig): the board keeps running, without Wi-Fi. */
static void transport_failed(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void) arg;
    (void) base;
    (void) id;
    (void) data;
    ESP_LOGE(TAG, "Wi-Fi companion (C6) link failed: Wi-Fi is down until the board restarts");
}

/* Brings up the SDIO link to the C6 from app_main, before the storage mounts (sdmmc_host_init is
 * not thread-safe). esp_hosted bounds the probe (CP_BRINGUP_TIMEOUT_MS, no restart on timeout).
 * No address: PHP connects to an access point with ext-wifi. */
bool phpbm_network_up(char *ip, size_t len)
{
    if (ip != NULL && len > 0) {
        ip[0] = '\0';
    }
    esp_err_t err = esp_event_handler_register(EH_HOST_EVENT, EH_HOST_EVENT_TRANSPORT_FAILURE, transport_failed, NULL);
    if (err != ESP_OK) {
        ESP_LOGW(TAG, "Wi-Fi companion: no handler for link failures (%s)", esp_err_to_name(err));
    }
    int rc = esp_hosted_init();
    if (rc == 0) {
        rc = esp_hosted_connect_to_slave();
    }
    if (rc != 0) {
        ESP_LOGE(TAG, "Wi-Fi companion (C6) not answering (%d): ext-wifi will fail", rc);
        return false;
    }
    return true;
}
