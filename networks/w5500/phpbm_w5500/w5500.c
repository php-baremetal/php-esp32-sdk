#include <stdbool.h>
#include <stddef.h>

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "driver/gpio.h"
#include "driver/spi_master.h"
#include "esp_eth.h"
#include "esp_eth_mac_w5500.h"
#include "esp_eth_phy_w5500.h"
#include "esp_event.h"
#include "esp_log.h"
#include "esp_mac.h"
#include "esp_netif.h"

#include "phpbm.h"

#define W5500_HOST SPI2_HOST /* SPI3_HOST is the microSD's */
#define W5500_CLOCK_MHZ 20
#define W5500_PHY_ADDR 1
#define W5500_LINK_TIMEOUT_MS 4000
#define W5500_DHCP_TIMEOUT_MS 15000
#define W5500_POLL_MS 100
#define W5500_LINK_CHECK_MS 250 /* esp_eth polls the PHY every 2 s by default: the link would always miss the timeout */

static const char *TAG = "w5500";
static volatile bool s_link_up;

static void w5500_link_event(void *arg, esp_event_base_t base, int32_t id, void *data)
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
    bool handler = false, bus = false;
    esp_eth_mac_t *mac = NULL;
    esp_eth_phy_t *phy = NULL;
    esp_eth_handle_t eth = NULL;
    esp_netif_t *netif = NULL;

    what = "event handler";
    if ((err = esp_event_handler_register(ETH_EVENT, ESP_EVENT_ANY_ID, w5500_link_event, NULL)) != ESP_OK) {
        goto fail;
    }
    handler = true;

    spi_bus_config_t bus_cfg = {
        .mosi_io_num = PHPBM_NETWORK_MOSI,
        .miso_io_num = PHPBM_NETWORK_MISO,
        .sclk_io_num = PHPBM_NETWORK_SCLK,
        .quadwp_io_num = -1,
        .quadhd_io_num = -1,
    };
    what = "spi bus";
    if ((err = spi_bus_initialize(W5500_HOST, &bus_cfg, SPI_DMA_CH_AUTO)) != ESP_OK) {
        goto fail;
    }
    bus = true;
    what = "gpio isr service";
    err = gpio_install_isr_service(0);
    if (err != ESP_OK && err != ESP_ERR_INVALID_STATE) {
        goto fail;
    }

    spi_device_interface_config_t devcfg = {
        .mode = 0,
        .clock_speed_hz = W5500_CLOCK_MHZ * 1000 * 1000,
        .queue_size = 20,
        .spics_io_num = PHPBM_NETWORK_CS,
    };
    eth_w5500_config_t w5500 = ETH_W5500_DEFAULT_CONFIG(W5500_HOST, &devcfg);
    w5500.base.int_gpio_num = PHPBM_NETWORK_INT;
    eth_mac_config_t mac_cfg = ETH_MAC_DEFAULT_CONFIG();
    eth_phy_config_t phy_cfg = ETH_PHY_DEFAULT_CONFIG();
    phy_cfg.phy_addr = W5500_PHY_ADDR;
    phy_cfg.reset_gpio_num = PHPBM_NETWORK_RST;
    what = "mac/phy";
    err = ESP_FAIL;
    if ((mac = esp_eth_mac_new_w5500(&w5500, &mac_cfg)) == NULL || (phy = esp_eth_phy_new_w5500(&phy_cfg)) == NULL) {
        goto fail;
    }

    esp_eth_config_t eth_cfg = ETH_DEFAULT_CONFIG(mac, phy);
    eth_cfg.check_link_period_ms = W5500_LINK_CHECK_MS;
    what = "driver install";
    if ((err = esp_eth_driver_install(&eth_cfg, &eth)) != ESP_OK) {
        goto fail;
    }
    mac = NULL;
    phy = NULL;
    uint8_t addr[6];
    if (esp_read_mac(addr, ESP_MAC_ETH) == ESP_OK && (err = esp_eth_ioctl(eth, ETH_CMD_S_MAC_ADDR, addr)) != ESP_OK) {
        ESP_LOGW(TAG, "mac address: %s", esp_err_to_name(err));
    }

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

    for (int t = 0; !s_link_up; t += W5500_POLL_MS) {
        if (t >= W5500_LINK_TIMEOUT_MS) {
            ESP_LOGW(TAG, "no link (cable unplugged?)");
            return false;
        }
        vTaskDelay(pdMS_TO_TICKS(W5500_POLL_MS));
    }
    for (int t = 0; t < W5500_DHCP_TIMEOUT_MS; t += W5500_POLL_MS) {
        esp_netif_ip_info_t info;
        if (esp_netif_get_ip_info(netif, &info) == ESP_OK && info.ip.addr != 0) {
            esp_ip4addr_ntoa(&info.ip, ip, (int) len);
            return true;
        }
        vTaskDelay(pdMS_TO_TICKS(W5500_POLL_MS));
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
    if (bus) {
        spi_bus_free(W5500_HOST);
    }
    if (handler) {
        esp_event_handler_unregister(ETH_EVENT, ESP_EVENT_ANY_ID, w5500_link_event);
    }
    return false;
}
