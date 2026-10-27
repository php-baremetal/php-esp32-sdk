#include <stdio.h>
#include <string.h>

#include "esp_log.h"
#include "esp_timer.h"
#include "freertos/FreeRTOS.h"

#include "php.h"
#include "php_main.h"
#include "php_variables.h"
#include "zend_exceptions.h"
#include "SAPI.h"
#include "phpbm.h"
#include "phpbm_options.h"

static const char *TAG = "phpbm";

/* max_execution_time without signals: zend_set_timeout()/zend_unset_timeout() are wrapped at link time
 * and drive a one-shot esp_timer. The callback (esp_timer task) only stores EG(timed_out) and
 * EG(vm_interrupt); the VM then calls zend_timeout(), which raises the standard fatal error. The
 * deadline, read under the lock, drops a callback that fires after the timer was stopped or restarted.
 * A loop inside C code (an extension) is not interrupted: the VM checks the flag between opcodes. */
static esp_timer_handle_t s_timeout_timer;
static portMUX_TYPE s_timeout_lock = portMUX_INITIALIZER_UNLOCKED;
static int64_t s_timeout_deadline;
static int s_invoke_depth;

static void phpbm_timeout_fire(void *arg)
{
    (void) arg;
    taskENTER_CRITICAL(&s_timeout_lock);
    if (s_timeout_deadline != 0 && esp_timer_get_time() >= s_timeout_deadline) {
        s_timeout_deadline = 0;
        zend_atomic_bool_store_ex(&EG(timed_out), true);
        zend_atomic_bool_store_ex(&EG(vm_interrupt), true);
    }
    taskEXIT_CRITICAL(&s_timeout_lock);
}

/* Restarts the countdown with seconds (0 stops it) and clears a pending timeout. */
static void phpbm_timeout_start(zend_long seconds)
{
    if (s_timeout_timer == NULL) {
        static bool warned;
        const esp_timer_create_args_t args = { .callback = phpbm_timeout_fire, .name = "php-timeout" };
        if (esp_timer_create(&args, &s_timeout_timer) != ESP_OK) {
            s_timeout_timer = NULL;
            if (!warned) {
                warned = true;
                ESP_LOGW(TAG, "no timer for max_execution_time: it is not enforced");
            }
            return;
        }
    }
    esp_timer_stop(s_timeout_timer);
    if (seconds < 0) {
        seconds = 0;
    }
    taskENTER_CRITICAL(&s_timeout_lock);
    s_timeout_deadline = seconds > 0 ? esp_timer_get_time() + (int64_t) seconds * 1000000 : 0;
    zend_atomic_bool_store_ex(&EG(timed_out), false);
    taskEXIT_CRITICAL(&s_timeout_lock);
    if (seconds > 0) {
        esp_timer_start_once(s_timeout_timer, (uint64_t) seconds * 1000000);
    }
}

void __real_zend_set_timeout(zend_long seconds, bool reset_signals);
void __real_zend_unset_timeout(void);
void __wrap_zend_set_timeout(zend_long seconds, bool reset_signals);
void __wrap_zend_unset_timeout(void);

void __wrap_zend_set_timeout(zend_long seconds, bool reset_signals)
{
    __real_zend_set_timeout(seconds, reset_signals);
    phpbm_timeout_start(seconds);
}

void __wrap_zend_unset_timeout(void)
{
    phpbm_timeout_start(0);
    __real_zend_unset_timeout();
}

bool phpbm_run_file(const char *path)
{
    zend_file_handle handle;
    zend_stream_init_filename(&handle, path);
    bool ok = php_execute_script(&handle);
    zend_destroy_file_handle(&handle);
    return ok && phpbm_after_stop() == NULL;
}

/* The function of phpbm_invoke's frame: an internal function, so code that names the active
 * function (php_error_docref, zend_argument_*_error) reports "phpbm()". Its name is a persistent
 * string flagged interned, so request code never counts references on it. */
static zend_internal_function s_invoke_func;

static zend_function *phpbm_invoke_func(void)
{
    if (s_invoke_func.function_name == NULL) {
        zend_string *name = zend_string_init("phpbm", sizeof("phpbm") - 1, 1);
        zend_string_hash_val(name);
        GC_SET_REFCOUNT(name, 1);
        GC_ADD_FLAGS(name, IS_STR_INTERNED | IS_STR_PERMANENT);
        s_invoke_func.type = ZEND_INTERNAL_FUNCTION;
        s_invoke_func.function_name = name;
    }
    return (zend_function *) &s_invoke_func;
}

static bool phpbm_is_exit(zend_object *ex)
{
    return ex != NULL && (zend_is_unwind_exit(ex) || zend_is_graceful_exit(ex));
}

/* zend_user_exception_handler(), except that exit() in the handler stays pending in EG(exception), so
 * the program stops (PHP drops whatever the handler throws, exit() included, and the loop would go on). */
static void phpbm_user_exception_handler(void)
{
    zend_object *old = EG(exception);
    zval handler, params[1], retval;
    EG(exception) = NULL;
    ZVAL_OBJ(&params[0], old);
    ZVAL_COPY_VALUE(&handler, &EG(user_exception_handler));
    zend_stack_push(&EG(user_exception_handlers), &handler);
    ZVAL_UNDEF(&EG(user_exception_handler));

    if (call_user_function(CG(function_table), NULL, &handler, &retval, 1, params) == SUCCESS) {
        zval_ptr_dtor(&retval);
        if (EG(exception) != NULL && !phpbm_is_exit(EG(exception))) {
            OBJ_RELEASE(EG(exception));
            EG(exception) = NULL;
        }
        OBJ_RELEASE(old);
    } else {
        EG(exception) = old;
    }

    if (Z_TYPE(EG(user_exception_handler)) == IS_UNDEF) {
        zval *top = zend_stack_top(&EG(user_exception_handlers));
        if (top != NULL) {
            ZVAL_COPY_VALUE(&EG(user_exception_handler), top);
            zend_stack_del_top(&EG(user_exception_handlers));
        }
    }
}

/* A frame of an internal function makes zend_call_function() leave an uncaught exception in
 * EG(exception) instead of rethrowing it, so the firmware decides what to do with it (the same as
 * zend_call_function() with no frame at all). */
static void phpbm_invoke_frame(void (*fn)(void *arg), void *arg)
{
    zend_execute_data frame;
    memset(&frame, 0, sizeof(frame));
    zend_execute_data *saved = EG(current_execute_data);
    frame.func = phpbm_invoke_func();
    frame.prev_execute_data = saved;
    EG(current_execute_data) = &frame;
    zend_try {
        fn(arg);
    } zend_catch {
        EG(current_execute_data) = saved;
        zend_bailout();
    } zend_end_try();
    EG(current_execute_data) = saved;

    if (EG(exception) == NULL) {
        return;
    }
    bool exiting = phpbm_is_exit(EG(exception));
    if (!exiting && Z_TYPE(EG(user_exception_handler)) != IS_UNDEF) {
        phpbm_user_exception_handler();
        if (EG(exception) == NULL) {
            return;
        }
        exiting = phpbm_is_exit(EG(exception));
    }
    if (PHPBM_OPTION_ON_ERROR_CONTINUE && !exiting) {
        zend_exception_error(EG(exception), E_WARNING);
        return;
    }
    zend_exception_error(EG(exception), E_ERROR);
    zend_bailout();
}

/* Each outermost call gets its own max_execution_time countdown (0 by default: no limit). */
void phpbm_invoke(void (*fn)(void *arg), void *arg)
{
    bool outer = s_invoke_depth++ == 0;
    if (outer) {
        phpbm_timeout_start(EG(timeout_seconds));
    }
    zend_try {
        phpbm_invoke_frame(fn, arg);
    } zend_catch {
        s_invoke_depth--;
        if (outer) {
            phpbm_timeout_start(0);
        }
        zend_bailout();
    } zend_end_try();
    s_invoke_depth--;
    if (outer) {
        phpbm_timeout_start(0);
    }
}

struct phpbm_call_args {
    const char *name;
    uint32_t argc;
    zval *argv;
};

static void phpbm_call_function(void *arg)
{
    struct phpbm_call_args *call = arg;
    zval function, result;
    ZVAL_STRING(&function, call->name);
    ZVAL_UNDEF(&result);
    call_user_function(NULL, NULL, &function, &result, call->argc, call->argv);
    zval_ptr_dtor(&result);
    zval_ptr_dtor(&function);
}

void phpbm_call(const char *name, uint32_t argc, zval *argv)
{
    struct phpbm_call_args call = { name, argc, argv };
    phpbm_invoke(phpbm_call_function, &call);
}

static void collect_garbage(void *arg)
{
    (void) arg;
    gc_collect_cycles();
}

void phpbm_collect_garbage(void)
{
    phpbm_invoke(collect_garbage, NULL);
}

void phpbm_register_server_vars(const char *entry_script)
{
    zend_is_auto_global_str(ZEND_STRL("_SERVER"));
    zval *server = &PG(http_globals)[TRACK_VARS_SERVER];
    if (Z_TYPE_P(server) != IS_ARRAY) {
        ESP_LOGW(TAG, "$_SERVER is not available");
        return;
    }
    phpbm_server_vars(server, entry_script);
}

void phpbm_server_vars(zval *server, const char *entry_script)
{
    const char *name = strrchr(entry_script, '/');
    php_register_variable("SCRIPT_FILENAME", entry_script, server);
    php_register_variable("SCRIPT_NAME", name != NULL ? name : entry_script, server);
    php_register_variable("PHP_SELF", name != NULL ? name : entry_script, server);
    php_register_variable("SERVER_SOFTWARE", "phpbm", server);
    if (phpbm_network_ip()[0] != '\0') {
        php_register_variable("SERVER_ADDR", phpbm_network_ip(), server);
    }
}

static phpbm_after_stop_t s_after;

void phpbm_request_after_stop(const phpbm_after_stop_t *after)
{
    if (EG(exception) != NULL) {
        return;
    }
    if (s_after.action == PHPBM_AFTER_STOP_NONE) {
        s_after = *after;
    }
    EG(exit_status) = 0;
    zend_throw_unwind_exit();
}

const phpbm_after_stop_t *phpbm_after_stop(void)
{
    return s_after.action != PHPBM_AFTER_STOP_NONE ? &s_after : NULL;
}

void phpbm_run_protected(bool (*body)(const char *entry_script), const char *entry_script)
{
    volatile bool ended = false;
    zend_try {
        ended = body(entry_script);
    } zend_end_try();

    const int fatal = E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE;
    if (s_after.action == PHPBM_AFTER_STOP_RESTART_NOW) {
        printf("--- stopped: restart ---\n");
    } else if (s_after.action == PHPBM_AFTER_STOP_DEEP_SLEEP) {
        printf("--- stopped: deep sleep");
        if (s_after.sleep_us > 0) {
            printf(" %llu ms", (unsigned long long) (s_after.sleep_us / 1000));
        }
        if (s_after.wake_pin >= 0) {
            printf(", wake on GPIO %d %s", s_after.wake_pin, s_after.wake_level ? "high" : "low");
        }
        printf(" ---\n");
    } else if (PG(last_error_message) != NULL && (PG(last_error_type) & fatal) != 0) {
        printf("--- stopped: fatal error ---\n");
    } else if (!ended) {
        printf("--- stopped: exit(%d) ---\n", EG(exit_status));
    } else {
        printf("--- end ---\n");
    }
}
