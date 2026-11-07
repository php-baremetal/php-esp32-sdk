---
eyebrow: 'SDK · Modules'
lede:    'Read a finger and its gestures from the capacitive touch controller of the Waveshare ESP32-S3-Touch-LCD-1.85B, as typed events in the event-driven runtime.'
see_also:
  - { href: './spi-and-displays.md', meta: 'Module' }
  - { href: './events-and-timers.md', meta: 'Module' }
  - { href: '../boards/board-files.md', meta: 'Boards' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-input', meta: 'Package', label: 'ext-input' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/touch-cst816s', meta: 'Package', label: 'touch-cst816s' }
prev: { label: 'SPI and displays', href: './spi-and-displays.md' }
next: { label: 'Events and timers', href: './events-and-timers.md' }
---

# Touch input

Two packages make a touch screen. [ext-input](https://repository.php-baremetal.com/packages/php-baremetal/ext-input)
defines what a touch device is: the `Baremetal\Input\Touch` interface, the four event classes `Pressed`, `Moved`,
`Released` and `Gesture`, and the `GestureType` enum. It has no hardware code.
[touch-cst816s](https://repository.php-baremetal.com/packages/php-baremetal/touch-cst816s) is the driver for the
CST816S controller on I2C: the class `Baremetal\I2c\Driver\Cst816s`, which implements `Touch`.

```bash
composer require php-baremetal/touch-cst816s    # brings ext-input, ext-i2c and ext-gpio with it
```

## The board

The `waveshare-esp32-s3-touch-lcd-1.85b` board file has a `touch` block, so the driver needs no pins:

<!-- @code-block language="json" label="boards/waveshare-esp32-s3-touch-lcd-1.85b.json" -->
```json
"touch": { "type": "cst816s", "int": 4, "rst": 1, "width": 360, "height": 360 }
```
<!-- @endcode-block -->

The controller shares the board's I2C bus (SDA 11, SCL 10) at address `0x15`; `int` is the INT line, which goes low
at each report, and `rst` is the reset line. `Cst816s::onboard()` reads the block and the bus.

## Read a point

`point()` reads the chip now and returns `['x' => ..., 'y' => ...]` in screen coordinates (0 to 359 on this panel),
or `null` with no finger. The chip sleeps when it is not touched and answers on the bus only after a touch or a reset,
so a read while it sleeps can fail: `point()` and `gesture()` then return `null` and never throw.

## Events

With the INT pin wired, `events()` turns on the events. Each falling edge of INT makes the executor read the chip's
report and raise what changed, in the `event-driven` runtime:

| Event | Raised when | Fields |
| --- | --- | --- |
| `Pressed` | A finger comes down | `$x`, `$y` |
| `Moved` | A report that moves the point: no coalescing, each one is an event (about 20 to 65 a second) | `$x`, `$y` |
| `Released` | The finger goes up | `$x`, `$y`: the last point |
| `Gesture` | The chip recognised a gesture | `$x`, `$y`, `$type`: a `GestureType` |

`GestureType` has `SwipeUp`, `SwipeDown`, `SwipeLeft`, `SwipeRight`, `Tap`, `DoubleTap` and `LongPress`; the swipe
directions are the ones on the screen, after the board's orientation. The chip computes the gestures itself. A double
tap is reported only if the chip is set up to report it: the driver does that by default (`double-tap` key of the
board's `touch` block, or the `$double_tap` constructor argument), and then a single `Tap` is raised about 260 ms after
the release instead of at once. With `double-tap` off, `Tap` is immediate and `DoubleTap` never comes.

`events()` returns a handle: `stop()` and `start()` pause and resume the events. Without `int:` it throws
`\ValueError` `Cst816s::events(): needs the INT pin (int:)`.

## Example

`06-display/touch-paint` (board `waveshare-esp32-s3-touch-lcd-1.85b`, `event-driven` runtime) paints a circle of
radius 4 at each `Pressed` and `Moved`, clears the screen on a double tap, changes the colour with a swipe to the
left or the right, and prints every gesture on the console:

<!-- @code-block language="php" label="06-display/touch-paint/project-src/index.php" -->
```php
<?php
use Baremetal\Events;
use Baremetal\I2c\Driver\Cst816s;
use Baremetal\Input\{Gesture, GestureType, Moved, Pressed};
use Baremetal\Spi\Driver\St77916;

$lcd = St77916::onboard();
$lcd->clear($lcd->rgb(0, 0, 0));
$lcd->flush();

$color = $lcd->rgb(255, 255, 255);

$paint = function (Pressed|Moved $e) use ($lcd, &$color): void {
    $lcd->fillCircle($e->x, $e->y, 4, $color);
    $lcd->flush($e->x - 4, $e->y - 4, $e->x + 5, $e->y + 5);
};
Events::listen(Pressed::class, $paint);
Events::listen(Moved::class, $paint);

Events::listen(Gesture::class, function (Gesture $e) use ($lcd): void {
    printf("gesture %s\n", $e->type->value);
    if ($e->type === GestureType::DoubleTap) {
        $lcd->clear($lcd->rgb(0, 0, 0));
        $lcd->flush();
    }
});

Cst816s::onboard()->events();
```
<!-- @endcode-block -->

The example in the repository also cycles through six colours with the swipes.

## Other boards

A board with a CST816S wired to its I2C bus gets a `touch` block with `type`, `int`, `rst`, `width` and `height`
(see [Board files](../boards/board-files.md)); the optional keys `address`, `mirror-x`, `mirror-y` and `swap-xy`
turn the chip's axes into the screen's, and `double-tap` turns double tap detection off. For a chip wired by hand, build it with its constructor:

<!-- @code-block language="php" -->
```php
use Baremetal\I2c\Bus;
use Baremetal\I2c\Driver\Cst816s;

$touch = new Cst816s(new Bus(sda: 11, scl: 10), int: 4, rst: 1, width: 360, height: 360);
```
<!-- @endcode-block -->

The full APIs are on the
[ext-input](https://repository.php-baremetal.com/packages/php-baremetal/ext-input) and
[touch-cst816s](https://repository.php-baremetal.com/packages/php-baremetal/touch-cst816s) package pages.
