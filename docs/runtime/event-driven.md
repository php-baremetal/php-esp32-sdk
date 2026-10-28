---
eyebrow: 'SDK · Runtimes'
lede:    'Runs the script once to register listeners, then sleeps on an event queue and runs the listener of each event. It requires ext-events.'
see_also:
  - { href: './runtimes.md', meta: 'Overview' }
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-events', meta: 'Extension', label: 'ext-events' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-web', meta: 'Extension', label: 'ext-web' }
prev: { label: 'init-loop', href: './init-loop.md' }
next: { label: 'web-server', href: './web-server.md' }
---

# event-driven

Set `"runtime": "event-driven"` in `extra.baremetal` and `composer require php-baremetal/ext-events`. The runtime
requires that package: the build fails without it. It has no options of its own.

The PHP API (`Events::listen()`, `every()`, `watch_gpio()` and the rest) belongs to
[ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events); this page covers what the
runtime does around it.

## Lifecycle

<!-- @steps -->
- **The entry script runs**

  The whole file runs once. It registers listeners and starts sources (timers, GPIO watchers). If the script
  fails here, the program ends.

- **The listener table freezes**

  When the script returns, the runtime freezes the listener table and creates the event queue if no source
  created it yet. Listeners can no longer be added.

- **The runtime waits for events**

  It blocks on the queue with no timeout. The PHP task sleeps, so the CPU idles between events.

- **Each event is delivered**

  Events are taken one at a time in arrival order and their listeners run on the PHP task, never two at
  once. Any `power_hold()` left standing is released after each event.
<!-- @endsteps -->

The runtime never calls `setup()` or `loop()`. A long listener delays everything behind it, so keep listeners
short. The garbage collector runs every 256 events.

## What delivers events

Sources never call PHP themselves: they post a message to the queue, and the runtime delivers it. With
[ext-web](https://repository.php-baremetal.com/packages/php-baremetal/ext-web) installed, HTTP requests and
WebSocket frames arrive through the same queue, as events of a single long-lived PHP run. The
`request-timeout` option limits one such HTTP handler: PHP gets `max_execution_time` equal to it, and when it
is exceeded that is a fatal error, which stops the program.

## How the program ends

The runtime waits for events for ever, so the program ends only when:

- a listener calls `exit()`: `--- stopped: exit(N) ---`;
- a fatal error occurs, `max_execution_time` included: `--- stopped: fatal error ---`;
- an uncaught exception leaves a listener and `on-error` is `stop`;
- the script asked the board to restart or deep-sleep (`sys_restart()`, `sys_deep_sleep()`);
- the queue cannot be created: the runtime prints `phpbm: cannot create the event queue` and the program
  ends (`--- end ---`).

Then the board waits `restart-delay` seconds and restarts; see [Runtimes](./runtimes.md).

## Errors

An uncaught exception in a listener stops the delivery of that event and follows `on-error`:

| `on-error` | Effect |
| --- | --- |
| `stop` (default) | The exception is reported as a fatal error and the program ends |
| `continue` | The exception is printed as a warning and the runtime goes on with the next event |

`set_exception_handler()` is honoured first. A fatal error always ends the program.

## Power saving

With the `power-save` option on, the chip light-sleeps whenever every task waits, and this runtime waits on
the queue between events. Timers, interrupts and network activity wake it. `power_hold()` keeps it awake for
the length of one event. See [Power saving](../reference/power-save.md) and the
[ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events) docs.

## Example

`new_examples/07-event-driven/event-driven-hello`: a timer event every second and a GPIO event on the BOOT
button.

<!-- @code-block language="json" label="composer.json" -->
```json
{
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-events": "^1.0"
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-s3-zero-n8r8",
      "runtime": "event-driven",
      "storage": "embedded"
    }
  }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
/**
 * The event-driven execution model: no loop(). The script runs once to register listeners and start
 * sources, then the reactor blocks on the event queue and delivers each event to its listeners --
 * sleeping between events, so the CPU idles (no busy-wait).
 *
 * Sources are C producers: every() is a timer, watch_gpio() a debounced GPIO interrupt. Each emits a
 * typed event; the handler receives the object.
 */

use Baremetal\Event;
use Baremetal\Events;

final class Tick extends Event {}
final class BootPressed extends Event {}

$ticks = 0;

Events::listen(Tick::class, function (Tick $e) use (&$ticks): void {
    printf("tick %d  (a Tick delivered by the reactor)\n", ++$ticks);
});

Events::listen(BootPressed::class, function (BootPressed $e): void {
    echo "  BOOT button pressed!\n";
});

every(1000, Tick::class);          // a Tick every second (timer source)
watch_gpio(0, BootPressed::class); // GPIO0 = the BOOT button (falling edge, debounced)

echo "event-driven: registered listeners + sources; the reactor now blocks on the queue\n";
```
<!-- @endcode-block -->
