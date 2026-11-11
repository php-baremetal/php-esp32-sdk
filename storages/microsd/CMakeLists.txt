include("$ENV{PHPBM_INPUTS}")
# The card's mount and the sync server are in the phpbm host component (host/cmake/microsd.cmake): the embedded
# storage mounts the card too when the project has assets on it.
idf_component_register(SRCS "microsd.c" REQUIRES phpbm WHOLE_ARCHIVE)
target_compile_definitions(${COMPONENT_LIB} PRIVATE PHPBM_APP_ENTRY="${PHPBM_APP_ENTRY}")
