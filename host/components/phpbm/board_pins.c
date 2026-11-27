/*
 * The board's pin names as PHP constants (board file key `pins`, table generated into phpbm_board.c). Registered once
 * at module startup, before the first request: the class and its constants are persistent, like those of any
 * internal extension. A name that exists already is skipped with a warning; the build checks for that before.
 */
#include <string.h>

#include "esp_log.h"

#include "php.h"
#include "phpbm.h"
#include "phpbm_options.h"

static const char *TAG = "phpbm";

static PHP_MINIT_FUNCTION(phpbm_board)
{
    if (zend_hash_str_exists(CG(class_table), ZEND_STRL("baremetal\\board\\pin"))) {
        ESP_LOGW(TAG, "class Baremetal\\Board\\Pin exists already: the board's pin names are not registered on it");
    } else {
        zend_class_entry ce;
        INIT_CLASS_ENTRY(ce, "Baremetal\\Board\\Pin", NULL);
        zend_class_entry *pin = zend_register_internal_class(&ce);
        pin->ce_flags |= ZEND_ACC_FINAL;
        for (size_t i = 0; i < phpbm_board_pin_count; i++) {
            zend_declare_class_constant_long(pin, phpbm_board_pins[i].name, strlen(phpbm_board_pins[i].name), phpbm_board_pins[i].gpio);
        }
    }
#if PHPBM_OPTION_BOARD_CONSTANTS_GLOBAL
    for (size_t i = 0; i < phpbm_board_pin_count; i++) {
        const char *name = phpbm_board_pins[i].name;
        size_t len = strlen(name);
        if (zend_get_constant_str(name, len) != NULL) {
            ESP_LOGW(TAG, "constant %s exists already: the board pin %s is only Baremetal\\Board\\Pin::%s", name, name, name);
            continue;
        }
        zend_register_long_constant(name, len, phpbm_board_pins[i].gpio, CONST_PERSISTENT, module_number);
    }
#endif
    return SUCCESS;
}

zend_module_entry phpbm_board_module_entry = {
    STANDARD_MODULE_HEADER,
    "phpbm_board",
    NULL,
    PHP_MINIT(phpbm_board),
    NULL,
    NULL,
    NULL,
    NULL,
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES,
};
