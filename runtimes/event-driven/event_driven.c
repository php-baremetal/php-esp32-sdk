#include <stdint.h>
#include <stdio.h>

#include "php.h"
#include "event_bus_php.h"
#include "phpbm.h"

static void deliver(void *msg)
{
    bm_events_deliver_msg(msg);
}

static bool event_driven(const char *entry_script)
{
    phpbm_register_server_vars(entry_script);
    if (!phpbm_run_file(entry_script)) {
        return false;
    }
    bm_events_freeze();
    bm_events_ensure_queue();
    for (uint32_t n = 1;; n++) {
        bm_event_msg_t msg;
        if (!bm_events_receive(&msg, UINT32_MAX)) {
            /* only without a queue: blocking forever never times out */
            printf("phpbm: cannot create the event queue\n");
            return true;
        }
        phpbm_invoke(deliver, &msg);
        bm_events_power_autorelease();
        if ((n & 0xFF) == 0) {
            phpbm_collect_garbage();
        }
    }
}

void phpbm_runtime_run(const char *entry_script)
{
    printf("--- %s (event-driven) ---\n", entry_script);
    phpbm_run_protected(event_driven, entry_script);
}
