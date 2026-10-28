---
eyebrow: 'SDK · Runtimes'
lede:    'Runs the script once, then setup() once and loop($tick) for ever. The simplest runtime, with no package to install.'
see_also:
  - { href: './runtimes.md', meta: 'Overview' }
  - { href: './event-driven.md', meta: 'Runtime' }
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-sys', meta: 'Extension', label: 'ext-sys' }
prev: { label: 'Runtimes', href: './runtimes.md' }
next: { label: 'event-driven', href: './event-driven.md' }
---

# init-loop

Set `"runtime": "init-loop"` in `extra.baremetal`. The runtime requires no package and no capability.

## Lifecycle

<!-- @steps -->
- **The entry script runs**

  The whole file runs once, top to bottom. `$_SERVER` holds `SCRIPT_FILENAME`, `SCRIPT_NAME`, `PHP_SELF` and
  `SERVER_SOFTWARE` (`phpbm`), plus `SERVER_ADDR` when the board has an address. If the script fails here,
  the program ends.

- **setup() runs once**

  If the script defined a function `loop`, the runtime calls `setup()` next, when one exists.

- **loop($tick) runs for ever**

  `loop()` is called with an `int` that starts at 0 and grows by one on every call. Between two calls the
  runtime waits `loop-interval` milliseconds.
<!-- @endsteps -->

<!-- @callout variant="warning" title="No loop(), no setup()" -->
The runtime looks for `loop()` first. A script that defines `setup()` but no `loop()` does not get `setup()`
called: the program ends right after the script (`--- end ---`).
<!-- @endcallout -->

Functions and globals defined by the script stay alive for the whole run, so `setup()` and `loop()` share
state through globals or statics. The whole program is one PHP request: nothing is reset between ticks. The
runtime collects cycles (`gc_collect_cycles()`) every 256 ticks.

## The tick

`$tick` counts the calls of `loop()` since the script started, from 0. It is not a clock: with
`loop-interval` 0 it advances as fast as `loop()` returns. To read time, use `sys_uptime_ms()` or `sys_micros()`
from [ext-sys](https://repository.php-baremetal.com/packages/php-baremetal/ext-sys).

## Option

| Option | Values | Default | Effect |
| --- | --- | --- | --- |
| `loop-interval` | 0 to 60000 (ms) | `0` | Wait between the end of one `loop()` and the next call. `0` calls `loop()` again at once, after a task yield. |

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "runtime": "init-loop",
  "options": { "loop-interval": 100 }
}
```
<!-- @endcode-block -->

With `loop-interval` 0 the PHP task never waits, so [power-save](../reference/power-save.md) never lets the
chip sleep. Give `loop()` an interval, or call `delay()` from ext-sys inside it, to let the board rest.

## How the program ends

`loop()` never returns control to the runtime for good, so the program ends only when:

- PHP code calls `exit()`: `--- stopped: exit(N) ---`;
- a fatal error occurs: `--- stopped: fatal error ---`;
- an uncaught exception leaves `setup()` or `loop()` and the `on-error` option is `stop`;
- the script asked the board to restart or deep-sleep (`sys_restart()`, `sys_deep_sleep()`);
- the script defined no `loop()`: `--- end ---`.

Then the board waits `restart-delay` seconds and restarts; see [Runtimes](./runtimes.md).

## Errors

An uncaught exception in `setup()`, `loop()` or the garbage collection follows `on-error`:

| `on-error` | Effect |
| --- | --- |
| `stop` (default) | The exception is reported as a fatal error and the program ends |
| `continue` | The exception is printed as a warning and the loop goes on with the next tick |

`set_exception_handler()` is honoured first. A fatal error always ends the program, and so does `exit()`
(which is never treated as an error).

## Example

`new_examples/00-hello`: a loop that prints a line every five ticks and waits a second between calls.

<!-- @code-block language="json" label="composer.json" -->
```json
{
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-hello": "^1.0",
    "php-baremetal/ext-sys": "^1.0"
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-s3-zero-n8r8",
      "runtime": "init-loop",
      "storage": "embedded"
    }
  }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php

// 00-hello -- the simplest firmware: prove a packaged engine extension (ext-hello) loads and runs.
// Requires only the neutral packages (ext-events, ext-hello) + platform=esp32; conductor binds the
// arch backing (esp32-events-hal) on its own.

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
