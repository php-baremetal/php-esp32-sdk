set(SDKCONFIG_DEFAULTS "${PHPBM_HOST}/sdkconfig.defaults")
foreach(_fragment
        "${PHPBM_SDK_DIR}/chips/${PHPBM_CHIP}/sdkconfig"
        "${PHPBM_SDK_DIR}/chips/${PHPBM_CHIP}/sdkconfig.${PHPBM_PSRAM}"
        "${PHPBM_SDK_DIR}/chips/${PHPBM_CHIP}/sdkconfig.rev-${PHPBM_REVISION}"
        "${PHPBM_SDK_DIR}/chips/${PHPBM_CHIP}/sdkconfig.flash-${PHPBM_FLASH_MODE}"
        "${PHPBM_HOST}/sdkconfig/console-${PHPBM_CONSOLE}"
        "${PHPBM_HOST}/sdkconfig/optimization-${PHPBM_OPTIMIZATION}"
        "${PHPBM_POWER_SAVE_SDKCONFIG}"
        "${PHPBM_SDK_DIR}/runtimes/${PHPBM_RUNTIME}/sdkconfig"
        "${PHPBM_SDK_DIR}/storages/${PHPBM_STORAGE}/sdkconfig"
        "${PHPBM_NETWORK_SDKCONFIG}")
    if(EXISTS "${_fragment}" AND NOT IS_DIRECTORY "${_fragment}")
        list(APPEND SDKCONFIG_DEFAULTS "${_fragment}")
    endif()
endforeach()
foreach(_fragment IN LISTS PHPBM_PACKAGE_SDKCONFIG)
    list(APPEND SDKCONFIG_DEFAULTS "${_fragment}")
endforeach()
list(APPEND SDKCONFIG_DEFAULTS "${PHPBM_BOARD_SDKCONFIG}")
