if(NOT DEFINED PHPBM_INPUTS OR NOT EXISTS "${PHPBM_INPUTS}")
    message(FATAL_ERROR "PHPBM_INPUTS is not set: build this firmware with phpbm")
endif()
include("${PHPBM_INPUTS}")
set(ENV{PHPBM_INPUTS} "${PHPBM_INPUTS}")
set(PHPBM_HOST "${PHPBM_SDK_DIR}/host")

include("${PHPBM_HOST}/cmake/sdkconfig.cmake")
include("${PHPBM_HOST}/cmake/partitions.cmake")

set(IDF_TARGET "${PHPBM_TARGET}")
set(PROJECT_VER "${PHPBM_PROJECT_VER}")
set(EXTRA_COMPONENT_DIRS
    "${PHPBM_HOST}/components"
    "${PHPBM_SDK_DIR}/runtimes/${PHPBM_RUNTIME}"
    "${PHPBM_SDK_DIR}/storages/${PHPBM_STORAGE}"
    "${PHPBM_PACKAGES_COMPONENT}")
set(COMPONENTS phpbm phpbm_packages "${PHPBM_RUNTIME}" "${PHPBM_STORAGE}")
set(_named "runtime=${PHPBM_RUNTIME}" "storage=${PHPBM_STORAGE}")
# The registry driver keeps the short name <type>, so the component is phpbm_<type> and cannot collide with IDF.
if(NOT PHPBM_NETWORK_COMPONENT STREQUAL "")
    get_filename_component(_network_component "${PHPBM_NETWORK_COMPONENT}" NAME)
    list(APPEND EXTRA_COMPONENT_DIRS "${PHPBM_NETWORK_COMPONENT}")
    list(APPEND COMPONENTS "${_network_component}")
endif()
foreach(_entry IN LISTS _named)
    string(REPLACE "=" ";" _entry "${_entry}")
    list(GET _entry 0 _kind)
    list(GET _entry 1 _name)
    if(EXISTS "$ENV{IDF_PATH}/components/${_name}")
        message(FATAL_ERROR "${_kind} '${_name}' has the name of an ESP-IDF component")
    endif()
endforeach()
include("$ENV{IDF_PATH}/tools/cmake/project.cmake")

# Keep build-host paths out of the firmware (__FILE__, assert messages). GCC applies the
# last matching map first, so nested directories come after the ones containing them.
foreach(_map IN LISTS PHPBM_PREFIX_MAPS)
    idf_build_set_property(COMPILE_OPTIONS "-ffile-prefix-map=${_map}" APPEND)
endforeach()
