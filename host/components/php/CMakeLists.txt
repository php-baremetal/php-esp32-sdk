include("$ENV{PHPBM_INPUTS}")
set(PHP_SRC "${PHPBM_PHP_SRC}")
set(PHP_VER_DIR "${PHPBM_SDK_DIR}/php/${PHPBM_PHP_MINOR}")
if(NOT EXISTS "${PHP_SRC}/main/php_version.h")
    message(FATAL_ERROR "PHP sources not found in ${PHP_SRC}: build this firmware with phpbm")
endif()
include("${PHP_VER_DIR}/sources.cmake")

# Optional PHP extensions (bm-php-ext packages): sources, includes and defines resolved by the driver.
set(PHP_EXT_SRCS ${PHPBM_PHP_EXT_SOURCES})
if(NOT PHPBM_PHP_EXT_TABLE STREQUAL "")
    list(APPEND PHP_EXT_SRCS "${PHPBM_PHP_EXT_TABLE}")
endif()
# Port files of an extension (headers configure would generate, stubs, backends): compat/extensions/<name>,
# on the include path only when it is active (the driver adds its *.c to the sources).
set(PHP_EXT_INCLUDE_DIRS ${PHPBM_PHP_EXT_INCLUDE_DIRS})
foreach(ext IN LISTS PHPBM_PHP_EXTENSIONS)
    if(IS_DIRECTORY "${PHP_VER_DIR}/compat/extensions/${ext}")
        list(APPEND PHP_EXT_INCLUDE_DIRS "${PHP_VER_DIR}/compat/extensions/${ext}")
    endif()
endforeach()
# Without the date extension, a stub covers the date symbols the core calls.
if(NOT "date" IN_LIST PHPBM_PHP_EXTENSIONS)
    list(APPEND PHP_EXT_SRCS "${PHP_VER_DIR}/compat/date_stub.c")
endif()

# PHPBM_PHP_EXT_IDF_REQUIRES: ESP-IDF components of the active extensions (registry ones are declared in
# the phpbm_packages manifest); inputs.cmake is read above, so it is set in IDF's requirements pass too.
idf_component_register(
    SRCS ${PHP_SRCS} ${PHP_EXT_SRCS}
         "${PHP_VER_DIR}/compat/internal_functions.c"
         "compat/posix_stubs.c"
         "compat/syslog.c"
         "compat/uname.c"
    INCLUDE_DIRS "${PHP_VER_DIR}/compat" "compat"
                 "${PHP_SRC}" "${PHP_SRC}/main" "${PHP_SRC}/Zend" "${PHP_SRC}/TSRM" "${PHP_SRC}/sapi/embed"
    PRIV_INCLUDE_DIRS ${PHP_PRIV_INCLUDE_DIRS} ${PHP_EXT_INCLUDE_DIRS}
    REQUIRES pthread lwip esp_common
    PRIV_REQUIRES ${PHPBM_PHP_EXT_IDF_REQUIRES})

target_compile_definitions(${COMPONENT_LIB} PRIVATE
    HAVE_CONFIG_H
    ZEND_ENABLE_STATIC_TSRMLS_CACHE=1
    KeccakP200_excluded KeccakP400_excluded KeccakP800_excluded
    HAVE_INT32_T HAVE_UINT32_T HAVE_INT64_T HAVE_UINT64_T
    NI_NUMERICHOST=1 NI_NUMERICSERV=2 NI_NOFQDN=4 NI_NAMEREQD=8 NI_DGRAM=16 NI_MAXHOST=1025 NI_MAXSERV=32
    AF_UNIX=1 PF_UNIX=1)
if(NOT PHPBM_PHP_EXT_TABLE STREQUAL "")
    target_compile_definitions(${COMPONENT_LIB} PRIVATE PHPBM_PHP_EXTENSIONS=1 ${PHPBM_PHP_EXT_DEFINES})
endif()
# -fno-strict-aliasing: GCC 15 strict aliasing breaks PCRE lookaheads at -Os/-O2
target_compile_options(${COMPONENT_LIB} PRIVATE -w -std=gnu17 -Wno-error=incompatible-pointer-types -fno-strict-aliasing)
