---
eyebrow: 'SDK · Modules'
lede:    'Typed events, listeners, timers and pin watchers with ext-events. The event-driven runtime delivers them; under init-loop only Events::now() and Events::dispatch() do.'
see_also:
  - { href: '../runtime/event-driven.md', meta: 'Runtime' }
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: './web.md', meta: 'Module' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-events', meta: 'Extension', label: 'ext-events' }
prev: { label: 'SPI and displays', href: './spi-and-displays.md' }
next: { label: 'Wi-Fi', href: './wifi.md' }
---

# Events and timers

ext-events lets a script react instead of running top to bottom. An event is an object of a class you declare
as a subclass of `Baremetal\Event`. Listeners are callables registered per event class. Sources raise events
below PHP: a periodic timer, a falling edge on a pin, gestures on a push button. Other extensions raise events
into the same queue: [ext-i2c](./i2c-and-sensors.md) sensor samples, and [ext-web](./web.md) HTTP requests and
WebSocket frames.

## Install

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/ext-events
```
<!-- @endcode-block -->

The conductor adds the ESP32 HAL (`esp32-events-hal`) for you: never require it. The `event-driven` runtime
requires ext-events, so the build fails without it. `ext-i2c` and `ext-web` bring it in as well.

## The main functions

| Name | Meaning |
| --- | --- |
| `Baremetal\Event` | Base class of every event. `$device` holds the object that raised it, or `null`. |
| `Events::listen(string $event, callable $listener)` | Register a listener for one exact class. Returning `false` stops the later listeners of that event. |
| `Events::now(Event $event)` | Deliver at once, on the caller's stack. |
| `Events::dispatch(Event $event)` | Deliver breadth-first: events dispatched by listeners run after the current batch. |
| `every(int $ms, string $event)` | Raise `$event` every `$ms` milliseconds. |
| `watch_gpio(int $pin, string $event)` | Raise `$event` on each falling edge of `$pin`, with a 200 ms debounce. |
| `watch_button(int $pin, array $on, array $opts = [])` | Raise events for button gestures: `pressed`, `released`, `held`, `click`, `repeat`, `double`. |
| `button_sampling(bool $on)` | Pause or resume the button sampler. |
| `power_hold()`, `power_release()` | Forbid or allow automatic light sleep. |

The full signatures, options and exceptions are in the
[ext-events package](https://repository.php-baremetal.com/packages/php-baremetal/ext-events).

## A timer and a button

`new_examples/07-event-driven/event-driven-hello` is the example of the [event-driven](../runtime/event-driven.md)
page. `new_examples/07-event-driven/gpio-interrupt` toggles an LED from the BOOT button (GPIO 0 on the
Waveshare ESP32-S3-Zero):

<!-- @code-block language="json" label="composer.json, require and extra.baremetal" -->
```json
{
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-events": "^1.0",
    "php-baremetal/ext-gpio": "^1.0"
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
use Baremetal\Event;
use Baremetal\Events;

const LED = 2;

final class Pressed extends Event {}

gpio_mode(LED, GPIO_OUTPUT);
$on = false;

Events::listen(Pressed::class, function (Pressed $e) use (&$on): void {
    $on = !$on;
    gpio_write(LED, $on ? 1 : 0);
    printf("button pressed -> LED %s\n", $on ? 'on' : 'off');
});

watch_gpio(0, Pressed::class);   // GPIO0 = BOOT, falling edge, debounced by the source

echo "gpio-interrupt: press BOOT to toggle the LED; the reactor sleeps between presses\n";
```
<!-- @endcode-block -->

The script runs once, registers the listener and the source, and returns. From then on the runtime delivers
each press. `$on` keeps its value between events, because the script stays resident.

## Which runtime delivers what

| Runtime | Timers, pin and button events | `Events::now()` and `dispatch()` | Listener table |
| --- | --- | --- | --- |
| `event-driven` | Delivered by the runtime, one at a time on the PHP task | Work | Frozen when the entry script returns |
| `init-loop` | Never delivered: the sources run, but nothing reads the queue | Work | Never frozen |
| `web-server` | Not supported | Not supported | Not supported |

<!-- @callout variant="warning" title="Sources need the event-driven runtime" -->
Under `init-loop`, `every()` and `watch_gpio()` start without error, and their events are never delivered. Use
the [event-driven](../runtime/event-driven.md) runtime for them. In `web-server` every request starts with a
fresh PHP state while ext-events keeps its listener table for the whole run: do not use ext-events there.
<!-- @endcallout -->

In `event-driven`, `Events::listen()` throws `Events::listen() must be called in setup(); the listener table is
frozen afterwards` once the entry script has returned, also from inside a listener. Register every listener in
the entry script.

## Behaviour on the board

- **The queue.** One queue of 32 messages carries every event from outside the PHP task: timers, pin edges,
  buttons, sensor samples, HTTP requests, WebSocket frames. A timer or pin event that finds it full is dropped
  without notice. An HTTP request that finds it full is answered 503.
- **Timers.** `every()` starts an `esp_timer`; its callback runs on the `esp_timer` task (core 0) and only posts
  to the queue. A timer, a pin watch or a button watch cannot be stopped once started; only the button sampler
  can be paused.
- **Pins.** `watch_gpio()` makes the pin an input with the internal pull-up and catches the falling edge by
  interrupt. Wire the button between the pin and GND. The 200 ms debounce is per event class, so two pins that
  share a class share it.
- **Buttons.** `watch_button()` samples every 10 ms, so timings have a 10 ms resolution; at most four buttons.
  Prefer `watch_gpio()` where gestures are not needed.
- **Delivery.** Events arrive in order and their listeners never run at the same time. A long listener delays
  every event behind it, HTTP requests included. Keep listeners short and split long work into steps driven by
  `every()`.
- **Matching.** A listener matches the exact class: a listener on a parent class receives nothing from its
  subclasses. Events raised by a source are created without calling their constructor.
- **Limits.** Sources can use at most 16 distinct event classes in the whole firmware, shared with the sample
  events of ext-i2c.

## Power: power_hold() and power_release()

With the `power-save` option on, the chip light-sleeps whenever every task waits, and the `event-driven` runtime
waits on the queue between events. Nothing in PHP is needed for that. `power_hold()` forbids light sleep until
the matching `power_release()`; calls nest. Use it around work that must not be slowed by sleep, such as a
tight timing loop.

- In `event-driven`, a hold still standing when the listeners of an event return is released: a hold never
  spans two events. Under `init-loop` a hold lasts until `power_release()`.
- Without `power-save` both functions do nothing, so the same script runs either way.
- What wakes the chip: an `every()` timer wakes it each period, `watch_gpio()` only on an edge, `watch_button()`
  every 10 ms while the sampler runs. See [Power saving](../reference/power-save.md).

## Common errors

| Message | Cause |
| --- | --- |
| `Events::listen() must be called in setup(); the listener table is frozen afterwards` | A listener registered after the entry script returned. |
| `every(): unknown class '...' or too many sources` | The event class does not exist, or 16 source classes are already in use. The same message exists for `watch_gpio()` and `watch_button()`. |
| `every(): cannot start the timer` | The timer could not be created or started. |
| `watch_button(): too many buttons` | Four buttons are already watched. |
| `phpbm: cannot create the event queue` | The runtime could not create the queue; the program ends. |

An uncaught exception in a listener follows the `on-error` option: see [event-driven](../runtime/event-driven.md).
