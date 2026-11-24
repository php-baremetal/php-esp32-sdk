/*
 * ESP32-P4: the app CPU waits for the scheduler with a pause between its reads.
 *
 * At start-up ESP-IDF runs esp_startup_start_app_other_cores() (freertos/app_startup.c) on core 1. It waits for
 * core 0's scheduler with a loop of two instructions, a load of port_xSchedulerRunning[0] and a branch, while core 0
 * creates the first tasks. On the ESP32-P4 the two cores share the L1 data cache, and that load hits the same cache
 * line at almost every cycle. Core 0's accesses that need the same part of the cache can then wait until its data bus
 * times out (HP_SYSTEM_CORE0_DBUS_TIMEOUT, 65535 cycles), and the CPU reports a store access fault: in
 * prvInitialiseNewTask, the memset that fills the main task's stack, before app_main. Whether the two loops lock
 * up depends on where the linker puts the code and the data, so some firmware images faulted on every boot and
 * others never did.
 *
 * The SDK compiles ESP-IDF's function as phpbm_idf_start_app_other_cores (post-project.cmake) and provides this one:
 * the same wait with a pause of 10 us between reads (esp_rom_delay_us() counts CPU cycles, not memory), then
 * ESP-IDF's function, whose own loop ends at once. It runs once per boot and adds at most 10 us to it.
 * Waiting first is only equivalent while ESP-IDF's function does nothing core 0 needs before its wait:
 * host/cmake/idf-startup.cmake fails the configure otherwise.
 */
#include "sdkconfig.h"

#if CONFIG_IDF_TARGET_ESP32P4 && !CONFIG_FREERTOS_UNICORE
#include "esp_rom_sys.h"

extern volatile unsigned port_xSchedulerRunning[CONFIG_FREERTOS_NUMBER_OF_CORES];

void phpbm_idf_start_app_other_cores(void) __attribute__((noreturn));

void __attribute__((noreturn)) phpbm_start_app_other_cores(void)
{
    while (port_xSchedulerRunning[0] == 0) {
        esp_rom_delay_us(10);
    }
    phpbm_idf_start_app_other_cores();
}

/* overrides the weak default of esp_system/startup.c, which calls it on core 1 */
void esp_startup_start_app_other_cores(void) __attribute__((alias("phpbm_start_app_other_cores"), noreturn));
#endif
