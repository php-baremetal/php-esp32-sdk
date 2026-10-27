include("$ENV{PHPBM_INPUTS}")
set(_srcs "boot.c" "run.c" "memory.c" "fs_pathnorm.c" "fs_glob.c" "${PHPBM_ENV_C}")
set(_priv esp_pm esp_hw_support esp_driver_gpio esp_app_format esp_netif esp_event esp_timer fatfs)
if(PHPBM_STORAGE STREQUAL "microsd")
    list(APPEND _srcs "sync.c")
    list(APPEND _priv mbedtls esp_driver_usb_serial_jtag esp_driver_uart)
endif()
if("session" IN_LIST PHPBM_PHP_EXTENSIONS)
    list(APPEND _srcs "session.c")
endif()
idf_component_register(
    SRCS ${_srcs}
    INCLUDE_DIRS "include" "${PHPBM_INCLUDE_DIR}"
    REQUIRES php
    PRIV_REQUIRES ${_priv})
target_compile_definitions(${COMPONENT_LIB} PRIVATE
    PHPBM_PROJECT_NAME="${PHPBM_PROJECT_NAME}"
    PHPBM_BOARD_NAME="${PHPBM_BOARD_NAME}"
    PHPBM_STORAGE_NAME="${PHPBM_STORAGE}"
    PHPBM_APP_ENTRY="${PHPBM_APP_ENTRY}")
foreach(_fn stat lstat open opendir access mkdir unlink rmdir rename)
    idf_build_set_property(LINK_OPTIONS "-Wl,--wrap=${_fn}" APPEND)
endforeach()
# max_execution_time on an esp_timer (run.c).
foreach(_fn zend_set_timeout zend_unset_timeout)
    idf_build_set_property(LINK_OPTIONS "-Wl,--wrap=${_fn}" APPEND)
endforeach()
foreach(_fn glob globfree)
    idf_build_set_property(LINK_OPTIONS "-Wl,--wrap=${_fn}" APPEND)
    idf_build_set_property(LINK_OPTIONS "-Wl,-u,__wrap_${_fn}" APPEND)
endforeach()
# statvfs() for PHP's disk_free_space()/disk_total_space() (fs_pathnorm.c): ESP-IDF's is a stub.
idf_build_set_property(LINK_OPTIONS "-Wl,--wrap=statvfs" APPEND)
idf_build_set_property(LINK_OPTIONS "-Wl,-u,__wrap_statvfs" APPEND)
