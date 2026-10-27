# Wi-Fi through the on-board ESP32-C6 (esp_hosted over SDIO): esp_wifi_remote provides esp_wifi_* to ext-wifi.
# The host brings up the SDIO link from app_main (phpbm_network_up); the network itself is managed from PHP.
idf_component_register(
    SRCS "wifi_c6.c"
    REQUIRES phpbm
    WHOLE_ARCHIVE)
