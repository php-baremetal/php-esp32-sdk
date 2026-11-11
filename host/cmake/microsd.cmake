# The microSD card: mounted (and served to phpbm sync) when the code is on it or when assets of the project go on
# it (the code in flash). Sets PHPBM_MICROSD, plus the components and the slot's definitions the mount needs.
set(PHPBM_MICROSD OFF)
set(PHPBM_MICROSD_REQUIRES "")
set(PHPBM_MICROSD_DEFINES "")
if(PHPBM_STORAGE STREQUAL "microsd" OR PHPBM_ASSETS_MICROSD)
    set(PHPBM_MICROSD ON)
    if(PHPBM_MICROSD_TYPE STREQUAL "spi")
        set(PHPBM_MICROSD_REQUIRES sdmmc esp_driver_sdspi esp_driver_spi)
        set(PHPBM_MICROSD_DEFINES
            PHPBM_MICROSD_CS=${PHPBM_MICROSD_CS}
            PHPBM_MICROSD_SCLK=${PHPBM_MICROSD_SCLK}
            PHPBM_MICROSD_MOSI=${PHPBM_MICROSD_MOSI}
            PHPBM_MICROSD_MISO=${PHPBM_MICROSD_MISO})
    elseif(PHPBM_MICROSD_TYPE STREQUAL "sdio")
        # SDMMC slot 0 IO MUX pins of the P4 reference wiring unless the board names others.
        foreach(_pin "PWR_EN=-1" "CLK=43" "CMD=44" "D0=39" "D1=40" "D2=41" "D3=42")
            string(REPLACE "=" ";" _pin "${_pin}")
            list(GET _pin 0 _name)
            if(NOT DEFINED PHPBM_MICROSD_${_name})
                list(GET _pin 1 PHPBM_MICROSD_${_name})
            endif()
        endforeach()
        set(PHPBM_MICROSD_REQUIRES sdmmc esp_driver_sdmmc esp_driver_gpio)
        set(PHPBM_MICROSD_DEFINES
            PHPBM_MICROSD_SDMMC=1
            PHPBM_MICROSD_PWR_EN=${PHPBM_MICROSD_PWR_EN}
            PHPBM_MICROSD_CLK=${PHPBM_MICROSD_CLK}
            PHPBM_MICROSD_CMD=${PHPBM_MICROSD_CMD}
            PHPBM_MICROSD_D0=${PHPBM_MICROSD_D0}
            PHPBM_MICROSD_D1=${PHPBM_MICROSD_D1}
            PHPBM_MICROSD_D2=${PHPBM_MICROSD_D2}
            PHPBM_MICROSD_D3=${PHPBM_MICROSD_D3})
    elseif(PHPBM_STORAGE STREQUAL "microsd")
        message(FATAL_ERROR "storage 'microsd': board '${PHPBM_BOARD}' has a '${PHPBM_MICROSD_TYPE}' microSD slot, only 'spi' and 'sdio' are supported")
    else()
        message(FATAL_ERROR "assets on the microSD: board '${PHPBM_BOARD}' has a '${PHPBM_MICROSD_TYPE}' microSD slot, only 'spi' and 'sdio' are supported")
    endif()
endif()
