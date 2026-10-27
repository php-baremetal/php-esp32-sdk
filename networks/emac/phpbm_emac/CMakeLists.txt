include("$ENV{PHPBM_INPUTS}")
idf_component_register(
    SRCS "emac.c"
    REQUIRES phpbm esp_eth esp_netif
    WHOLE_ARCHIVE)
target_compile_definitions(${COMPONENT_LIB} PRIVATE
    PHPBM_NETWORK_RST=${PHPBM_NETWORK_RST})
