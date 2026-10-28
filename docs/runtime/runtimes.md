---
eyebrow: 'SDK · Runtimes'
lede:    'A runtime is the part of the firmware that runs your entry script and decides what happens next. The esp32 SDK has three: init-loop, event-driven and web-server.'
see_also:
  - { href: './init-loop.md', meta: 'Runtime' }
  - { href: './event-driven.md', meta: 'Runtime' }
  - { href: './web-server.md', meta: 'Runtime' }
  - { href: '../reference/options.md', meta: 'Reference' }
prev: { label: 'Board overrides', href: '../boards/board-overrides.md' }
next: { label: 'init-loop', href: './init-loop.md' }
---

# Runtimes

A project picks one runtime with `extra.baremetal.runtime`. The firmware boots, mounts the
[storage](./storage.md), starts the PHP engine, and hands the entry script to the runtime. The runtime
decides when PHP code runs, what calls it, and when the program is over. The choice is fixed at build time:
the other two runtimes are not in the firmware.

## The three runtimes

| | `init-loop` | `event-driven` | `web-server` |
| --- | --- | --- | --- |
| Lifecycle | Runs the script once, then `setup()` once and `loop($tick)` forever | Runs the script once, then waits for events and runs their listeners | Runs the entry script again for every HTTP request, each with a fresh PHP state |
| Requires | Nothing | Package `php-baremetal/ext-events` | Capability `network` (the board needs a network type) |
| Options | `loop-interval` | None | `init`, `port` |
| Delivers events | No: `ext-events` sources run, but their queue is never read | Yes: timers, GPIO, buttons, samplers and, with `ext-web`, HTTP and WebSocket | No: `ext-events` is not supported here |
| Calls PHP functions | `setup()`, `loop()` | Listeners registered by the script | None: the whole script is the handler |
| `on-error` | Applies to an uncaught exception in `setup()`, `loop()` and the garbage collector | Applies to an uncaught exception in a listener | No effect: an error ends only that request, with a 500 |
| Program end | `exit()`, a fatal error, an uncaught exception with `stop`, or a script without `loop()` | `exit()`, a fatal error, an uncaught exception with `stop` | Only when PHP cannot start a request, or on a request that restarts the board |
| `restart-delay` | Waited once the program has ended, then the board restarts | The same | The same, on those rare ends |

`on-error`, `restart-delay` and the other SDK-wide options belong to the firmware, not to a runtime; see
[Options](../reference/options.md). A runtime adds options of its own: `loop-interval` for `init-loop`,
`init` and `port` for `web-server`.

## What happens when the program ends

Every runtime reports why it stopped on the console, then the board waits and restarts:

| Console line | Cause |
| --- | --- |
| `--- end ---` | The script returned and the runtime had nothing more to run |
| `--- stopped: exit(N) ---` | The script called `exit()` |
| `--- stopped: fatal error ---` | A fatal error, or an uncaught exception with `on-error` set to `stop` |
| `--- stopped: restart ---` | The script asked to restart (`sys_restart()` from ext-sys): the board restarts at once, without `restart-delay` |
| `--- stopped: deep sleep ---` | The script asked for deep sleep (`sys_deep_sleep()` from ext-sys): the board sleeps instead of restarting |

After the first three the firmware prints `phpbm: restarting in N s`, waits `restart-delay` seconds (default 5;
with `0` it restarts at once and prints nothing) and restarts the chip. The script then runs again from the top.

## Choosing one

<!-- @steps -->
- **The firmware serves HTTP pages or a JSON API**

  Use `web-server`: PHP-FPM style, one fresh run of the entry script per request.

- **The firmware polls or repeats a job**

  Use `init-loop`: `setup()` once, `loop($tick)` over and over. It needs no package and is the simplest model.

- **The firmware reacts to timers, buttons, GPIO or sensors**

  Use `event-driven` with `ext-events`: the script registers listeners, and the board sleeps between events.

- **The firmware needs HTTP with state kept between requests**

  Use `event-driven` with `ext-web`: requests arrive as events inside one long-lived PHP run.
<!-- @endsteps -->

<!-- @callout variant="note" title="Power saving" -->
With the `power-save` option the chip light-sleeps whenever every task waits: in `event-driven` between
events, in `init-loop` between two `loop()` calls when `loop-interval` is above 0. See
[Power saving](../reference/power-save.md).
<!-- @endcallout -->
