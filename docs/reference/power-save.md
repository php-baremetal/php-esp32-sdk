---
eyebrow: 'SDK · Reference'
lede:    'The power-save option lets the board enter light sleep by itself whenever every task waits. What it changes, what keeps the board awake, and what to do when a sleeping board stops answering.'
see_also:
  - { href: './options.md', meta: 'Reference' }
  - { href: '../development/firmware-rules.md', meta: 'SDK development' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-sys', meta: 'Package', label: 'ext-sys' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-events', meta: 'Package', label: 'ext-events' }
prev: { label: 'Memory, flash and partitions', href: './memory-and-flash.md' }
next: { label: 'Toolchain', href: './toolchain.md' }
---

# Power saving

`power-save` is an SDK option: `off` (the default) or `on`.

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "sdk": "esp32@^2.0",
  "board": "waveshare-esp32-s3-zero",
  "runtime": "event-driven",
  "storage": "embedded",
  "options": { "power-save": "on" }
}
```
<!-- @endcode-block -->

## What the option does

With `on`, the build adds the sdkconfig fragment `host/sdkconfig/power-save` and defines
`PHPBM_OPTION_POWER_SAVE_ON`. The fragment enables:

| Setting | Meaning |
| --- | --- |
| `CONFIG_PM_ENABLE` | ESP-IDF power management. |
| `CONFIG_FREERTOS_USE_TICKLESS_IDLE` | Light sleep from the idle task. |
| `CONFIG_PM_SLP_IRAM_OPT` | Sleep entry and exit code in IRAM. |
| `CONFIG_PM_RTOS_IDLE_OPT` | FreeRTOS idle code in IRAM. |
| `CONFIG_USJ_NO_AUTO_LS_ON_CONNECTION` | No automatic light sleep while a USB host is connected to the USB-Serial-JTAG port. |

At boot `app_main` configures the power manager for automatic light sleep: the CPU clock runs between the crystal
frequency and the default maximum frequency, and the console prints `phpbm: power save on (light sleep, <min>-<max>
MHz)`. If the manager cannot be configured it prints `phpbm: warning: power save could not be configured, light
sleep stays off`, and the program runs without sleep.

The board then sleeps whenever every task is waiting: the `event-driven` runtime waiting for the next event, or
`init-loop` between two `loop()` calls when `loop-interval` is above 0. RAM, PSRAM and PHP state survive, and the
board wakes on a timer, an interrupt or network activity. Nothing changes in PHP: no call is needed.

## What keeps the board awake

| Cause | Detail |
| --- | --- |
| `init-loop` with `loop-interval` 0 | `loop()` never waits, so the board never sleeps. |
| A USB data cable on the USB-Serial-JTAG console | The firmware sets `CONFIG_USJ_NO_AUTO_LS_ON_CONNECTION` on purpose, otherwise the console port goes silent. To measure real sleep, power the board from 5 V without the data cable. The boards with this console are `waveshare-esp32-s3-eth`, `waveshare-esp32-s3-touch-lcd-1.85b`, `waveshare-esp32-s3-zero` and `waveshare-esp32-s3-zero-n8r8`. |
| `power_hold()` | Forbids light sleep until the matching `power_release()`; the calls nest. In `event-driven` a hold still standing when an event's listeners return is released. |
| Periodic work | An `every()` timer wakes the board each period; a button watch wakes it every 10 ms while it runs; an ext-i2c sampler wakes it for as long as it runs. |

`power_hold()` and `power_release()` belong to [ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events),
not to ext-sys. Without `power-save` they do nothing, so the same script runs either way.

<!-- @callout variant="warning" title="Not with I2cBus::CORE1" -->
With `power-save` on, ext-i2c refuses a bus opened with `Bus::CORE1`: its polling keeps the CPU busy and defeats the
power saving. Use `Bus::SYNC`. A sampler on a `SYNC` bus wakes the chip once per period, so the board never sleeps
longer than that period. See the ext-i2c page on
[ownership and power-save](https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c).
<!-- @endcallout -->

## Explicit sleeps

The ext-sys package has three calls that work with or without the option. They are described in the
[ext-sys package](https://repository.php-baremetal.com/packages/php-baremetal/ext-sys).

| Call | What it does |
| --- | --- |
| `sys_light_sleep($ms, $wakePin, $wakeLow)` | Sleeps in place for `$ms` or until a pin reaches its level, then returns `timer`, `gpio` or `other`. It runs on the executor task, which is blocked for the whole sleep: NVS writes and `Bus::CORE1` buses wait. |
| `sys_deep_sleep($ms, $wakePin, $wakeLow)` | Ends the program and puts the chip in deep sleep; waking is a full boot. The wake pin must be an RTC IO on ESP32-S3 or an LP IO on ESP32-P4. |
| `sys_wake_cause()` | What ended the deep sleep this boot came from: `timer`, `gpio`, `other`, or `none`. |

Explicit light sleep suspends the flash cache, so the PHP task, whose stack is in PSRAM, never calls it directly:
see [Writing native packages](../development/firmware-rules.md). `sys_restart()` and `sys_deep_sleep()` stop the
whole program, even when a `web-server` request calls them.

## The USB-JTAG console and sleep

On a board with the USB-Serial-JTAG console, the console port goes silent while the chip sleeps; it is also what
drops out during `sys_light_sleep()`. That is why the firmware sets `CONFIG_USJ_NO_AUTO_LS_ON_CONNECTION`: with a
USB host connected the board does not light-sleep by itself, so the console stays up, at the price of no automatic
sleep while the cable is plugged in.

## When a board stops answering

If a board with a sleeping or crashing program no longer shows a serial port, or `phpbm flash` cannot reach it,
put the chip in download mode by hand:

1. Hold the BOOT button.
2. Press and release RESET, then release BOOT.
3. Run `phpbm flash` again. If phpbm does not find the board, pass the port with `--port`.

A firmware built without `power-save` replaces the sleeping one, and the board answers again.

## Not yet measured

The sleep current of the firmware has not been measured. A build with `power-save` and the explicit sleep calls
runs on the ESP32-S3; on the ESP32-P4 it builds but has not been run. Wake-up from a pin has not been exercised
with a real edge.
