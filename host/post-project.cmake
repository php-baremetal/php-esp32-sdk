include("${PHPBM_SDK_DIR}/storages/${PHPBM_STORAGE}/storage.cmake" OPTIONAL)
include("${PHPBM_SDK_DIR}/runtimes/${PHPBM_RUNTIME}/runtime.cmake" OPTIONAL)
# internal_functions.c (php) reads phpbm_package_modules from phpbm_packages, which itself requires php:
# declare the reverse link dependency instead of relying on WHOLE_ARCHIVE alone.
idf_component_get_property(_php_lib php COMPONENT_LIB)
idf_component_get_property(_packages_lib phpbm_packages COMPONENT_LIB)
target_link_libraries(${_php_lib} INTERFACE ${_packages_lib})
# ESP32-P4: ESP-IDF's esp_startup_start_app_other_cores() polls the scheduler flag with no pause, which can starve
# core 0 in the shared L1 data cache until its data bus times out (a store access fault before app_main). It is
# compiled as phpbm_idf_start_app_other_cores; phpbm's startup.c provides the function with a paced poll and calls it.
if(CONFIG_IDF_TARGET_ESP32P4 AND NOT CONFIG_FREERTOS_UNICORE)
    idf_component_get_property(_freertos_lib freertos COMPONENT_LIB)
    idf_component_get_property(_freertos_dir freertos COMPONENT_DIR)
    include("${PHPBM_SDK_DIR}/host/cmake/idf-startup.cmake")
    phpbm_check_idf_app_startup("${_freertos_dir}/app_startup.c")   # nothing core 0 needs before ESP-IDF's wait
    set_property(SOURCE "${_freertos_dir}/app_startup.c" TARGET_DIRECTORY ${_freertos_lib} APPEND PROPERTY
        COMPILE_DEFINITIONS "esp_startup_start_app_other_cores=phpbm_idf_start_app_other_cores")
endif()
