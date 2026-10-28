---
eyebrow: 'SDK · Modules'
lede:    'Read and drive digital pins with four functions. On ESP32 every gpio_mode() call leaves the internal pull-up on, and an invalid pin number raises no PHP error.'
see_also:
  - { href: './events-and-timers.md', meta: 'Module' }
  - { href: '../runtime/event-driven.md', meta: 'Runtime' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-gpio', meta: 'Package', label: 'ext-gpio' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-events', meta: 'Package', label: 'ext-events' }
prev: { label: 'Available modules', href: './overview.md' }
next: { label: 'LED strips', href: './led-strips.md' }
---

# GPIO

[ext-gpio](https://repository.php-baremetal.com/packages/php-baremetal/ext-gpio) switches an LED or a relay and
reads a button or a jumper. A pin is an input or an output; an output can still be read back.

```bash
composer require php-baremetal/ext-gpio php-baremetal/ext-sys
```

ext-sys is only there for `delay()` in the examples. The esp32 HAL (`esp32-gpio-hal`) is added by the conductor.

## Functions

| Function | Meaning |
| --- | --- |
| `gpio_mode(int $pin, int $mode): void` | Make the pin an input (`GPIO_INPUT`) or an output (`GPIO_OUTPUT`) |
| `gpio_write(int $pin, int $level): void` | Drive an output: 0 for low, any other value for high |
| `gpio_read(int $pin): int` | The level of the pin, 0 or 1; works on outputs too |
| `gpio_available(): bool` | Always `true` when the extension is in the firmware |

There are no classes and no exceptions. `$pin` is the GPIO number of the chip, not the label printed on the board.

## Blink an LED

The example `04-gpio-led/blink-sos` blinks SOS on an LED with a resistor of about 330 ohm between GPIO 2 and GND.
It runs on `waveshare-esp32-s3-zero-n8r8` with the `init-loop` runtime:

<!-- @code-block language="php" label="04-gpio-led/blink-sos/project-src/index.php" -->
```php
<?php
define('LED', 2);
const UNIT = 200;   // milliseconds; a dot is one unit, a dash is three

function pulse(int $units): void {
    gpio_write(LED, 1);
    delay(UNIT * $units);
    gpio_write(LED, 0);
    delay(UNIT);            // gap between symbols
}

function setup(): void {
    gpio_mode(LED, GPIO_OUTPUT);
    echo "blinking SOS on GPIO " . LED . "\n";
}

function loop(int $tick): void {
    foreach ([1, 1, 1, 3, 3, 3, 1, 1, 1] as $units) {   // S O S
        pulse($units);
    }
    delay(UNIT * 6);        // longer gap before repeating
}
```
<!-- @endcode-block -->

## Read a button

In `04-gpio-led/button-led` a button between GPIO 4 and GND is mirrored to the LED on GPIO 2. The input reads 1
when idle and 0 when the button pulls it to ground, because the pull-up is on:

<!-- @code-block language="php" label="04-gpio-led/button-led/project-src/index.php" -->
```php
<?php
define('LED', 2);
define('BUTTON', 4);

function setup(): void {
    gpio_mode(LED, GPIO_OUTPUT);
    gpio_mode(BUTTON, GPIO_INPUT);
}

function loop(int $tick): void {
    $pressed = gpio_read(BUTTON) === 0;   // pulled low when pressed
    gpio_write(LED, $pressed ? 1 : 0);
    delay(20);
}
```
<!-- @endcode-block -->

## What the ESP32 does

| Behaviour | Detail |
| --- | --- |
| Pull-up | `gpio_mode()` resets the pin first, which enables the internal pull-up and detaches any peripheral that used the pin. Every pin you set up has its pull-up on. |
| Outputs | An output is also an input, so `gpio_read()` returns the level the pin is driving. |
| Output level | `gpio_mode()` does not set a level: call `gpio_write()` right after it to start from a known state. |
| Pin numbers | Not checked by the extension. ESP-IDF rejects an invalid pin with a message on the console; PHP sees no error. |
| Where it runs | Directly on the PHP task. No other task is involved. |

<!-- @callout variant="warning" title="Do not reuse a pin another module owns" -->
`gpio_mode()` takes the pin away from whatever used it before. Do not call it on the pins of an I2C bus, an SPI
bus, an LED strip or the board's own wiring (microSD, Ethernet, display).
<!-- @endcallout -->

## Interrupts instead of polling

Polling in `loop()` keeps the CPU busy. With the `event-driven` runtime and
[ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events), `watch_gpio()` raises an
event on each falling edge of a pin, and the board sleeps until then. The pin becomes an input with the pull-up
on, so wire the button between the pin and GND. Edges closer than 200 ms to the previous one of the same event
class are ignored (debounce). The example `07-event-driven/gpio-interrupt` toggles the LED on GPIO 2 from the BOOT
button (GPIO 0); its `composer.json` requires `ext-events` and `ext-gpio` and sets `"runtime": "event-driven"`:

<!-- @code-block language="php" label="07-event-driven/gpio-interrupt/project-src/index.php" -->
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

watch_gpio(0, Pressed::class);   // GPIO0 = BOOT, falling edge, debounced

echo "gpio-interrupt: press BOOT to toggle the LED; the reactor sleeps between presses\n";
```
<!-- @endcode-block -->

The listeners run on the PHP task; see [event-driven](../runtime/event-driven.md). For click, double-click and
hold gestures use `watch_button()`, which samples the pin every 10 ms (more in
[Events and timers](./events-and-timers.md)). With `power-save` on, `watch_gpio()` costs nothing while the pin is
idle, so it suits light sleep ([Power saving](../reference/power-save.md)).

## Common errors

| Symptom | Cause |
| --- | --- |
| `Call to undefined function gpio_mode()` | ext-gpio is not in the firmware: `composer require php-baremetal/ext-gpio`, then `phpbm flash` |
| `Call to undefined function delay()` | `delay()` comes from ext-sys |
| An input reads 1 with nothing connected | The pull-up is on; wire the button to GND, or read an idle 1 as "not pressed" |
| Nothing happens on a pin and the console shows an ESP-IDF error | The pin number is not a valid GPIO of the chip |
| `watch_gpio(): unknown class '...' or too many sources` | The event class does not exist, or 16 source classes are already in use |
| `Events::listen() must be called in setup(); the listener table is frozen afterwards` | Listeners can only be added while the script first runs in `event-driven` |

The full API is on the [ext-gpio](https://repository.php-baremetal.com/packages/php-baremetal/ext-gpio) package
page.
