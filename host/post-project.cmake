include("${PHPBM_SDK_DIR}/storages/${PHPBM_STORAGE}/storage.cmake" OPTIONAL)
include("${PHPBM_SDK_DIR}/runtimes/${PHPBM_RUNTIME}/runtime.cmake" OPTIONAL)
# internal_functions.c (php) reads phpbm_package_modules from phpbm_packages, which itself requires php:
# declare the reverse link dependency instead of relying on WHOLE_ARCHIVE alone.
idf_component_get_property(_php_lib php COMPONENT_LIB)
idf_component_get_property(_packages_lib phpbm_packages COMPONENT_LIB)
target_link_libraries(${_php_lib} INTERFACE ${_packages_lib})
