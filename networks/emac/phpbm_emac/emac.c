#include <stdbool.h>
#include <stddef.h>

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "esp_eth.h"
#include "esp_eth_mac_esp.h"
#include "esp_eth_phy_ip101.h"
#include "esp_event.h"
#include "esp_log.h"
#include "esp_netif.h"

#include "phpbm.h"

/* RMII and MDIO use the ESP32-P4 EMAC default pins (ETH_ESP32_EMAC_DEFAULT_CONFIG); only the PHY reset is per board. */
#define EMAC_LINK_TIMEOUT_MS 6000
#define EMAC_DHCP_TIMEOUT_MS 15000
#define EMAC_POLL_MS 100
#define EMAC_LINK_CHECK_MS 250 /* esp_eth polls the PHY every 2 s by default: the link would always miss the timeout */

static const char *TAG = "emac";
static volatile bool s_link_up;

static void emac_link_event(void *arg, esp_event_base_t base, int32_t id, void *data)
{
    (void) arg;
    (void) base;
    (void) data;
    if (id == ETHERNET_EVENT_CONNECTED) {
        s_link_up = true;
    } else if (id == ETHERNET_EVENT_DISCONNECTED) {
        s_link_up = false;
    }
}

bool phpbm_network_up(char *ip, size_t len)
{
    esp_err_t err;
    const char *what;
    bool handler = false;
    esp_eth_mac_t *mac = NULL;
    esp_eth_phy_t *phy = NULL;
    esp_eth_handle_t eth = NULL;
    esp_netif_t *netif = NULL;

    what = "event handler";
    if ((err = esp_event_handler_register(ETH_EVENT, ESP_EVENT_ANY_ID, emac_link_event, NULL)) != ESP_OK) {
        goto fail;
    }
    handler = true;

    eth_esp32_emac_config_t emac_cfg = ETH_ESP32_EMAC_DEFAULT_CONFIG();
    eth_mac_config_t mac_cfg = ETH_MAC_DEFAULT_CONFIG();
    eth_phy_config_t phy_cfg = ETH_PHY_DEFAULT_CONFIG();
    phy_cfg.reset_gpio_num = PHPBM_NETWORK_RST;
    what = "mac/phy";
    err = ESP_FAIL;
    if ((mac = esp_eth_mac_new_esp32(&emac_cfg, &mac_cfg)) == NULL || (phy = esp_eth_phy_new_ip101(&phy_cfg)) == NULL) {
        goto fail;
    }

    esp_eth_config_t eth_cfg = ETH_DEFAULT_CONFIG(mac, phy);
    eth_cfg.check_link_period_ms = EMAC_LINK_CHECK_MS;
    what = "driver install";
    if ((err = esp_eth_driver_install(&eth_cfg, &eth)) != ESP_OK) {
        goto fail;
    }
    mac = NULL;
    phy = NULL;

    esp_netif_config_t netif_cfg = ESP_NETIF_DEFAULT_ETH();
    what = "netif";
    err = ESP_FAIL;
    if ((netif = esp_netif_new(&netif_cfg)) == NULL) {
        goto fail;
    }
    what = "netif attach";
    esp_eth_netif_glue_handle_t glue = esp_eth_new_netif_glue(eth);
    if (glue == NULL || (err = esp_netif_attach(netif, glue)) != ESP_OK) {
        err = glue == NULL ? ESP_FAIL : err;
        if (glue != NULL) {
            esp_eth_del_netif_glue(glue);
        }
        goto fail;
    }
    what = "start";
    if ((err = esp_eth_start(eth)) != ESP_OK) {
        goto fail;
    }

    for (int t = 0; !s_link_up; t += EMAC_POLL_MS) {
        if (t >= EMAC_LINK_TIMEOUT_MS) {
            ESP_LOGW(TAG, "no link (cable unplugged?)");
            return false;
        }
        vTaskDelay(pdMS_TO_TICKS(EMAC_POLL_MS));
    }
    for (int t = 0; t < EMAC_DHCP_TIMEOUT_MS; t += EMAC_POLL_MS) {
        esp_netif_ip_info_t info;
        if (esp_netif_get_ip_info(netif, &info) == ESP_OK && info.ip.addr != 0) {
            esp_ip4addr_ntoa(&info.ip, ip, (int) len);
            return true;
        }
        vTaskDelay(pdMS_TO_TICKS(EMAC_POLL_MS));
    }
    ESP_LOGW(TAG, "no DHCP lease");
    return false;

fail:
    ESP_LOGE(TAG, "%s: %s", what, esp_err_to_name(err));
    if (netif != NULL) {
        esp_netif_destroy(netif);
    }
    if (eth != NULL) {
        esp_eth_driver_uninstall(eth);
    }
    if (mac != NULL) {
        mac->del(mac);
    }
    if (phy != NULL) {
        phy->del(phy);
    }
    if (handler) {
        esp_event_handler_unregister(ETH_EVENT, ESP_EVENT_ANY_ID, emac_link_event);
    }
    return false;
}
