include("$ENV{PHPBM_INPUTS}")
if(PHPBM_MICROSD_TYPE STREQUAL "spi")
    idf_component_register(
        SRCS "microsd.c"
        REQUIRES phpbm fatfs sdmmc esp_driver_sdspi esp_driver_spi
        WHOLE_ARCHIVE)
    target_compile_definitions(${COMPONENT_LIB} PRIVATE
        PHPBM_MICROSD_CS=${PHPBM_MICROSD_CS}
        PHPBM_MICROSD_SCLK=${PHPBM_MICROSD_SCLK}
        PHPBM_MICROSD_MOSI=${PHPBM_MICROSD_MOSI}
        PHPBM_MICROSD_MISO=${PHPBM_MICROSD_MISO}
        PHPBM_APP_ENTRY="${PHPBM_APP_ENTRY}")
elseif(PHPBM_MICROSD_TYPE STREQUAL "sdio")
    # SDMMC slot 0 IO MUX pins of the P4 reference wiring unless the board names others.
    foreach(_pin "PWR_EN=-1" "CLK=43" "CMD=44" "D0=39" "D1=40" "D2=41" "D3=42")
        string(REPLACE "=" ";" _pin "${_pin}")
        list(GET _pin 0 _name)
        if(NOT DEFINED PHPBM_MICROSD_${_name})
            list(GET _pin 1 PHPBM_MICROSD_${_name})
        endif()
    endforeach()
    idf_component_register(
        SRCS "microsd.c"
        REQUIRES phpbm fatfs sdmmc esp_driver_sdmmc esp_driver_gpio
        WHOLE_ARCHIVE)
    target_compile_definitions(${COMPONENT_LIB} PRIVATE
        PHPBM_MICROSD_SDMMC=1
        PHPBM_MICROSD_PWR_EN=${PHPBM_MICROSD_PWR_EN}
        PHPBM_MICROSD_CLK=${PHPBM_MICROSD_CLK}
        PHPBM_MICROSD_CMD=${PHPBM_MICROSD_CMD}
        PHPBM_MICROSD_D0=${PHPBM_MICROSD_D0}
        PHPBM_MICROSD_D1=${PHPBM_MICROSD_D1}
        PHPBM_MICROSD_D2=${PHPBM_MICROSD_D2}
        PHPBM_MICROSD_D3=${PHPBM_MICROSD_D3}
        PHPBM_APP_ENTRY="${PHPBM_APP_ENTRY}")
else()
    message(FATAL_ERROR "storage 'microsd': board '${PHPBM_BOARD}' has a '${PHPBM_MICROSD_TYPE}' microSD slot, only 'spi' and 'sdio' are supported")
endif()
