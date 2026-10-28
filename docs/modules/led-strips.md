---
eyebrow: 'SDK · Modules'
lede:    'Drive WS2812 and SK6812 LEDs, strips, rings or the RGB LED on a board, from a pixel buffer. Each strip takes one of the four RMT transmit channels of the chip.'
see_also:
  - { href: './gpio.md', meta: 'Module' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-led', meta: 'Package', label: 'ext-led' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/led-ws2812', meta: 'Package', label: 'led-ws2812' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/led-sk6812', meta: 'Package', label: 'led-sk6812' }
prev: { label: 'GPIO', href: './gpio.md' }
next: { label: 'I2C and sensors', href: './i2c-and-sensors.md' }
---

# LED strips

[ext-led](https://repository.php-baremetal.com/packages/php-baremetal/ext-led) drives addressable RGB LEDs: strips,
rings, and the single RGB LED many boards carry. You create one object per data pin, write colours into its pixel
buffer, then send the buffer with `show()`.

You require a chip package; it requires ext-led, and the conductor adds the esp32 HAL (`esp32-ledstrip-hal`):

```bash
composer require php-baremetal/led-ws2812      # WS2812 and WS2812B, three channels
composer require php-baremetal/led-sk6812      # SK6812, RGBW
```

The first build downloads Espressif's `led_strip` component through the ESP-IDF component manager, so it needs
network access.

## The classes

Each chip package adds one class in `Baremetal\Led\Driver\`. Both extend `Baremetal\Led\Device`:

| Class | Package | Colour order constants | Default order |
| --- | --- | --- | --- |
| `Ws2812` | led-ws2812 | `GRB`, `RGB` | `GRB` |
| `Sk6812` | led-sk6812 | `GRB`, `RGB`, `GRBW`, `RGBW` | `GRBW` |

The constructor is `new Ws2812(int $pin, int $count = 1, ?int $order = null)`, and the same for `Sk6812`. The
strip is cleared (every pixel off) when the object is created.

| Method | Meaning |
| --- | --- |
| `pixel(int $i, int $r, int $g, int $b)` | Set pixel `$i` (from 0) in the buffer |
| `hsv(int $i, int $h, int $s, int $v)` | Set pixel `$i` from hue (degrees), saturation and value (0 to 255); `$v` is the brightness |
| `fill(int $r, int $g, int $b)` | Set every pixel in the buffer |
| `show()` | Send the buffer to the LEDs; nothing lights up before this |
| `set(int $r, int $g, int $b)` | `fill()` and `show()` in one call |
| `off()` | Clear the buffer and send it |
| `count(): int` | The number of pixels |

Colour components outside 0 to 255 are clamped, and hues wrap at 360.

## A strip

<!-- @code-block language="php" label="a strip of 8 WS2812" -->
```php
<?php
use Baremetal\Led\Driver\Ws2812;

$strip = new Ws2812(pin: 5, count: 8);       // GRB order, all pixels off

for ($i = 0; $i < $strip->count(); $i++) {
    $strip->hsv($i, $i * 45, 255, 32);       // a dim rainbow into the buffer
}
$strip->show();
```
<!-- @endcode-block -->

If red and green come out swapped, the strip is not GRB: pass `Ws2812::RGB`. For an SK6812, `Sk6812::GRBW` and
`Sk6812::RGBW` are the orders that fit; the white channel is always written as 0.

## The RGB LED on a board

Many boards carry one WS2812 on a data pin. The example `04-gpio-led/s3-rgb-show` runs a rainbow on the LED of the
`waveshare-esp32-s3-zero-n8r8`, on GPIO 48. That LED is a single WS2812 in RGB order, so the order is passed
explicitly. The pin differs on other boards (the example comment mentions 38 on some ESP32-S3 boards): check the
schematic of yours. The `composer.json` requires `ext-sys`, `ext-led` and `led-ws2812`, with the `init-loop`
runtime.

<!-- @code-block language="php" label="04-gpio-led/s3-rgb-show/project-src/index.php" -->
```php
<?php
use Baremetal\Led\Driver\Ws2812;

const PIN    = 48;
const BRIGHT = 10;   // 0..255 value -- kept very low on purpose; the WS2812 is dazzlingly bright

$led = null;

function setup(): void
{
    global $led;
    $led = new Ws2812(PIN, 1, Ws2812::RGB);
    echo "ws2812 rainbow on GPIO " . PIN . " -- PHP " . PHP_VERSION . "\n";
}

function loop(int $tick): void
{
    global $led;
    for ($h = 0; $h < 360; $h += 2) {   // 180 steps
        $led->hsv(0, $h, 255, BRIGHT);
        $led->show();
        delay(25);                      // ~4.5 s per full cycle
    }
}
```
<!-- @endcode-block -->

## What the ESP32 does

| Behaviour | Detail |
| --- | --- |
| RMT channels | Each strip is built on the RMT peripheral and takes one transmit channel; ESP32-S3 and ESP32-P4 have 4. When none is free the constructor throws `cannot init ws2812 on GPIO N`. |
| Timing | 10 MHz RMT resolution, no DMA. WS2812 or SK6812 timing is chosen by the class. |
| `show()` | Blocks the PHP task until the frame has gone out. Errors of the transfer are not reported. |
| Pixels | The buffer takes 3 or 4 bytes per pixel; memory is the practical limit on `count`. |
| Lifetime | The object owns its channel. Destroying it releases the channel and leaves the LEDs on their last frame. |
| One object per pin | Two objects on the same pin are not detected: each takes its own channel. |

In the `event-driven` runtime keep the object in a variable that your handlers capture (or a global); in the
`web-server` runtime it lasts one request.

<!-- @callout variant="warning" title="Keep the brightness low" -->
Full-brightness LEDs are very bright and draw a lot of current. Use a low `$v` (the example uses 10) and a
suitable power supply for long strips.
<!-- @endcallout -->

## Common errors

| Message | Cause |
| --- | --- |
| `cannot init ws2812 on GPIO N` (`sk6812` for `Sk6812`) | The pin cannot be an output, no RMT channel is free, or memory ran out |
| `Argument #2 ($count) must be at least 1` | `$count` is 0 or negative |
| `Argument #3 ($order) must be one of the GRB, RGB constants` | An order the class does not support; `Sk6812` lists its four constants |
| `Argument #1 ($i) is out of range for this strip` | `pixel()` or `hsv()` with an index outside 0 to `count() - 1` |

The full API is on the [ext-led](https://repository.php-baremetal.com/packages/php-baremetal/ext-led) package page.
