#include <stdint.h>
#include <stdio.h>

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"

#include "php.h"
#include "phpbm.h"
#include "phpbm_options.h"

static void setup_loop(void)
{
    if (!zend_hash_str_exists(EG(function_table), ZEND_STRL("loop"))) {
        return;
    }
    if (zend_hash_str_exists(EG(function_table), ZEND_STRL("setup"))) {
        phpbm_call("setup", 0, NULL);
    }
    for (uint64_t tick = 0;; tick++) {
        zval argument;
        ZVAL_LONG(&argument, (zend_long) (tick % ZEND_LONG_MAX));
        phpbm_call("loop", 1, &argument);
        if ((tick & 0xFF) == 0xFF) {
            phpbm_collect_garbage();
        }
#if PHPBM_OPTION_LOOP_INTERVAL > 0
        vTaskDelay(pdMS_TO_TICKS(PHPBM_OPTION_LOOP_INTERVAL));
#else
        taskYIELD();
#endif
    }
}

static bool init_loop(const char *entry_script)
{
    phpbm_register_server_vars(entry_script);
    if (!phpbm_run_file(entry_script)) {
        return false;
    }
    setup_loop();
    return true;
}

void phpbm_runtime_run(const char *entry_script)
{
    printf("--- %s (init-loop) ---\n", entry_script);
    phpbm_run_protected(init_loop, entry_script);
}
