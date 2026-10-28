---
eyebrow: 'SDK · Modules'
lede:    'Delays and clocks, chip and memory figures, restart and the two kinds of sleep with ext-sys; a small in-RAM table with ext-mem; and ext-hello to check that a project builds its packages.'
see_also:
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-sys', meta: 'Package', label: 'ext-sys' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-mem', meta: 'Package', label: 'ext-mem' }
prev: { label: 'Saving data', href: './saving-data.md' }
next: { label: 'PHP extensions', href: './php-extensions.md' }
---

# System and power

Three small packages cover what every firmware needs: `ext-sys` for time, chip information, restart and sleep,
`ext-mem` for values that live in RAM, and `ext-hello` as the smallest check that a build works. None of them
needs a pin or a board with special hardware, and all three run on every board of the SDK.

| Package | Adds | Install |
| --- | --- | --- |
| [ext-sys](https://repository.php-baremetal.com/packages/php-baremetal/ext-sys) | `sys_*` functions and `delay()` | `composer require php-baremetal/ext-sys` |
| [ext-mem](https://repository.php-baremetal.com/packages/php-baremetal/ext-mem) | `mem_*` functions | `composer require php-baremetal/ext-mem` |
| [ext-hello](https://repository.php-baremetal.com/packages/php-baremetal/ext-hello) | `bm_hello()` | `composer require php-baremetal/ext-hello` |

Run the commands inside a project created with `phpbm init`; the conductor adds the platform part of each package
for the board on its own.

## Time and delays

| Function | Meaning |
| --- | --- |
| `sys_delay(int $ms)`, `delay(int $ms)` | Suspends the PHP task for `$ms` milliseconds, with 1 ms resolution. Other tasks keep running and the watchdog stays fed. |
| `sys_uptime_ms(): int` | Milliseconds since boot. |
| `sys_micros(): int` | Microseconds since boot. |

PHP integers are 32 bits on the board, so `sys_micros()` turns negative after about 35.8 minutes and
`sys_uptime_ms()` after about 24.8 days. Compare the difference of two readings, never the readings themselves.
Both clocks restart from 0 after a restart and after a deep sleep, and keep counting through a light sleep.

## Chip and memory figures

| Function | Meaning |
| --- | --- |
| `sys_chip_model(): string` | `"ESP32-S3"`, `"ESP32-P4"` and so on. |
| `sys_cpu_freq_mhz(): int` | The configured CPU frequency, not a measurement. |
| `sys_mac(): string` | The Wi-Fi station MAC as `aa:bb:cc:dd:ee:ff`; unique per chip, a usable device id. |
| `sys_idf_version(): string` | The ESP-IDF version of the firmware. |
| `sys_heap_free()`, `sys_heap_size()` | Free and total internal RAM, in bytes. |
| `sys_psram_free()`, `sys_psram_size()`, `sys_psram_largest_free()` | PSRAM figures in bytes; the last one is the largest block still available, which is much smaller than the free total when the pool is fragmented. |

These figures describe the board's heaps, not PHP's own memory manager. For what the script uses, call
`memory_get_usage()`. How the PSRAM is split into `memory_limit` and the share kept for the firmware is in
[Memory, flash and partitions](../reference/memory-and-flash.md). The deprecated unprefixed names (`heap_free()`,
`psram_free()`, ...) still work and raise `E_DEPRECATED`: use the `sys_` names.

<!-- @code-block language="php" label="a health report" -->
```php
<?php
printf("%s @ %d MHz, ESP-IDF %s, MAC %s\n",
    sys_chip_model(), sys_cpu_freq_mhz(), sys_idf_version(), sys_mac());
printf("reset: %s, wake: %s\n", sys_reset_reason(), sys_wake_cause());
printf("internal RAM free %d of %d bytes\n", sys_heap_free(), sys_heap_size());
printf("PSRAM free %d of %d bytes, largest block %d\n",
    sys_psram_free(), sys_psram_size(), sys_psram_largest_free());
```
<!-- @endcode-block -->

The example `01-language/benchmark` reads the PSRAM figures before and after each step to measure what a piece of
code costs; `01-language/ext-selftest` checks every `sys_*` function and prints PASS or FAIL.

## Restart and sleep

`sys_restart()` and `sys_deep_sleep()` do not restart or sleep from inside PHP. They end the program like `exit()`:
the rest of the script, `loop()` and the pending handlers are skipped, shutdown functions and destructors run, and
the firmware then shuts PHP down, unmounts the storage and restarts or sleeps. The console prints
`--- stopped: restart ---` or `--- stopped: deep sleep 5000 ms ---`. This works from the script, `setup()`,
`loop()`, a handler or a web request. The stop cannot be caught, and `finally` blocks do not run.

| Function | What the board does |
| --- | --- |
| `sys_restart(): never` | Restarts at once, with no `restart-delay`. Afterwards `sys_reset_reason()` is `"software"`. |
| `sys_deep_sleep(int $ms, ?int $wakePin = null, bool $wakeLow = true): never` | Stops the program and powers down CPUs and RAM. Waking is a full boot: the script starts from the beginning and `sys_reset_reason()` is `"deepsleep"`. |
| `sys_light_sleep(int $ms, ?int $wakePin = null, bool $wakeLow = true): string` | Sleeps in place and returns when the chip wakes: `"timer"`, `"gpio"` or `"other"`. RAM, PSRAM, variables and open files survive. |
| `sys_wake_cause(): string` | What ended the deep sleep this boot came from: `"timer"`, `"gpio"`, `"other"`, or `"none"` after power-on, reset button, `sys_restart()` or a crash. |
| `sys_reset_reason(): string` | Why the chip last booted: `poweron`, `external`, `software`, `panic`, `int_wdt`, `task_wdt`, `wdt`, `deepsleep`, `brownout`, `sdio`, `usb`, `jtag` or `unknown`. |

`$ms` of 0 means no timer, and then `$wakePin` is required. The longest sleep is 2147483647 ms, about 24.8 days.

- **Deep sleep wake pins.** On the ESP32-S3 an RTC GPIO (GPIO 0 to 21), on the ESP32-P4 an LP GPIO. `$wakeLow`
  `true` wakes when the pin is low, as for a button to GND.
- **Light sleep wake pins.** Any valid GPIO of the chip; the wake is on the level, not on an edge. If the pin is
  already at the wake level the call returns `"gpio"` at once, without sleeping.
- **Wrong arguments.** A negative `$ms`, `$ms` 0 without a pin, a pin the chip does not have, or (deep sleep) a
  GPIO that cannot wake the chip, throws `\ValueError` before anything stops or sleeps.
- **Console.** The USB-Serial-JTAG console drops out while the chip sleeps.

<!-- @callout variant="warning" title="sys_light_sleep() blocks the executor" -->
For the whole sleep the executor task runs nothing else: background I2C samplers stop, and writes to the
`ext-store` store or to a bus on `Bus::CORE1` wait until the chip wakes. Keep explicit light sleeps short when the
firmware relies on them.
<!-- @endcallout -->

What a stop does in each runtime: in `init-loop` and `event-driven` the program or the reactor stops and `loop()`
is not called again; in `web-server` a call from a request ends that request like `exit()`, its response is sent,
then the server stops, and requests that arrive before the restart get 503. `sys_light_sleep()` in `web-server`
pauses the request that called it.

### Two sleeps in a cycle

The example `10-power/power-bench` (event-driven, `ext-events` and `ext-sys`) cycles active, light sleep and deep
sleep, five seconds each, so the current of each state can be read on a meter. Deep sleep reboots the board, so the
cycle repeats by itself.

<!-- @code-block language="php" label="10-power/power-bench/project-src/index.php (excerpt)" -->
```php
<?php
use Baremetal\Event;
use Baremetal\Events;

const SECS = 5;

final class Cycle extends Event {}

if (sys_wake_cause() !== 'none') {
    echo "(back from deep sleep -> new cycle)\n";
}

Events::listen(Cycle::class, function (): void {
    echo "[ACTIVE] " . SECS . "s -- read current\n";
    $t = microtime(true);
    while (microtime(true) - $t < SECS) {
        // CPU awake and busy
    }

    echo "[LIGHT SLEEP] " . SECS . "s -- read current\n";
    sys_light_sleep(SECS * 1000);      // timer-only wake

    echo "[DEEP SLEEP] " . SECS . "s -- read current (then reboot)\n";
    usleep(200000);
    sys_deep_sleep(SECS * 1000);       // timer-only wake -> full reboot -> cycle repeats
});

Events::now(new Cycle());
```
<!-- @endcode-block -->

A wake on a pin is one more argument: `sys_deep_sleep(10000, wakePin: 4)` sleeps for 10 s or until GPIO 4 goes low.

<!-- @callout variant="note" title="Measure with the USB data cable unplugged" -->
A connected USB data cable on a board whose console is the USB-Serial-JTAG keeps the chip awake. Power the board
from 5 V to read the real sleep current.
<!-- @endcallout -->

### Sleep without writing it

The `power-save` option puts the board into light sleep by itself whenever every task waits: between events,
during `delay()`, between two `loop()` calls with a `loop-interval`. The script keeps running and needs no
`sys_light_sleep()` call. The option, what keeps the board awake and the trade-offs are in
[Power saving](../reference/power-save.md). With the option on, `ext-events` adds `power_hold()` and
`power_release()` around timing-critical work; the example `10-power/power-save` shows both with a heartbeat every
10 s.

## ext-mem

`ext-mem` is an in-RAM key-value table shared by every request of one boot. It is not a source of memory figures
(those come from `ext-sys`): it keeps PHP values. It is pure, needs no pin and no HAL, and works on every board.

| Function | Meaning |
| --- | --- |
| `mem_set(string $key, mixed $value): bool` | Stores a serialized copy under a non-empty key. |
| `mem_get(string $key, mixed $default = null): mixed` | Returns a fresh copy, or `$default` when the key is missing. |
| `mem_has(string $key): bool` | Whether the key is present; tells a stored `null` from a missing key. |
| `mem_delete(string $key): bool` | Removes a key. |
| `mem_clear(): bool` | Removes every key. |
| `mem_keys(): array` | The stored keys, in insertion order. |

<!-- @code-block language="php" label="share a counter across requests" -->
```php
<?php
$hits = (int) mem_get('hits', 0) + 1;   // survives from one request to the next
mem_set('hits', $hits);
mem_set('last', ['at' => time(), 'path' => '/']);

echo "request #$hits, keys: ", implode(', ', mem_keys()), "\n";
```
<!-- @endcode-block -->

On the board:

- The table survives the end of a request and a light sleep, and is empty after a reset, a power loss, a crash or a
  deep sleep. Nothing is written to flash, so writing often costs no wear. Values that must survive a reset belong
  in `ext-store`, see [Saving data](./saving-data.md).
- Every read is an independent copy: `mem_get('cart')['items'][] = $x;` changes nothing. Read into a variable,
  change it, then `mem_set()` it back.
- A `Closure` or a `Generator` makes `mem_set()` throw an `Exception`; a resource is stored as the integer `0`.
- There is no size or key limit besides free RAM, and running out of RAM is fatal: `mem_set()` does not return
  `false` for a full heap. Bound caches whose keys come from request input.
- `ext-selftest` (`01-language/ext-selftest`) round-trips values through `mem_set()` and `mem_get()` among its checks.

## ext-hello

`ext-hello` adds one function, `bm_hello()`, which returns `"hello from ext-hello package"`. Require it to prove
that the toolchain, the registry and the conductor build a project with packages; if it prints, the whole chain
works. The example `00-hello` is the smallest firmware:

<!-- @code-block language="php" label="00-hello/project-src/index.php" -->
```php
<?php
echo bm_hello(), "\n";          // "hello from ext-hello package" -- from the ext-hello package

function loop(int $t): void
{
    if ($t % 5 === 0) {
        echo "tick {$t} -- still alive\n";
    }
    delay(1000);
}
```
<!-- @endcode-block -->

Its `composer.json` requires `php-baremetal/ext-hello` and `php-baremetal/ext-sys` (for `delay()`), with board
`waveshare-esp32-s3-zero-n8r8`, runtime `init-loop` and storage `embedded`. If `bm_hello()` is undefined, the
package is not in the build: check `composer.json`, run `composer install` and build again. See
[Troubleshooting](../reference/troubleshooting.md).

## Common errors

| Symptom | Cause |
| --- | --- |
| `\ValueError` from `sys_deep_sleep()` or `sys_light_sleep()` | Negative `$ms`, `$ms` 0 without a wake pin, a GPIO the chip does not have, or a deep sleep pin that is not an RTC/LP GPIO. |
| `Call to undefined function sys_delay()` | `ext-sys` is not required in `composer.json`. |
| `sys_light_sleep()` returns `"other"` at once | The chip refused to sleep, or the executor queue stayed full for 1000 ms. |
| A time comparison fails after a while | The 32-bit counters wrapped: compare differences. |
| The board never sleeps with `power-save` on | A USB data cable is connected, or `init-loop` runs with `loop-interval` 0. |
