---
eyebrow: 'SDK · Modules'
lede:    'Slate draws scenes of shapes, text and pixels on the round panel and redraws only what changes: gradients, shadows, blur, touch, and a profiler you switch on with one option.'
see_also:
  - { href: './touch.md', meta: 'Module' }
  - { href: './spi-and-displays.md', meta: 'Module' }
  - { href: './events-and-timers.md', meta: 'Module' }
  - { href: '../reference/options.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-slate', meta: 'Package', label: 'ext-slate' }
prev: { label: 'Touch input', href: './touch.md' }
next: { label: 'Events and timers', href: './events-and-timers.md' }
---

# Slate

[ext-slate](https://repository.php-baremetal.com/packages/php-baremetal/ext-slate) is a retained-mode 2D scene
graph for display panels. Instead of drawing pixels frame after frame, a script builds **scenes** out of
**elements** (`RoundRect`, `Circle`, `Arc`, `Line`, `Text`, `Canvas` and `Group`), then changes their properties.
Slate works out what changed and composes only that part of the screen, band by band, at the pace of the panel. A
screen that does not change costs no frame at all.

```bash
composer require php-baremetal/ext-slate    # brings ext-display, ext-events and ext-input with it
```

Slate needs a display driver with the band path (`esp32-display-st77916` 1.2 or later) and, for touch, a touch
driver such as `touch-cst816s`. It runs best in the `event-driven` runtime: the listeners change the elements, and
the changes of one listener appear together in the same frame.

## A first scene

<!-- @code-block language="php" -->
```php
use Baremetal\Slate\{Slate, Scene, RoundRect, Text, Align};

final class Home extends Scene
{
    protected function build(): void
    {
        $this->add(
            new RoundRect(0, 0, 360, 360, fill: 0x0B1020),
            new Text(180, 170, 'Hello', align: Align::Center),
        );
    }
}

$slate = Slate::onboard();       // the board's own panel and touch screen
$slate->show(new Home());
```
<!-- @endcode-block -->

`Slate::onboard()` reads the board's `display` and `touch` blocks, like the `onboard()` methods of the drivers.
A page is a class that extends `Scene`: its components are built in `build()`, and `onEnter()`, `onLeave()` and
`onTouch()` are called when the page is shown, replaced and touched. A component of your own extends `Group`.
The complete API is on the [ext-slate](https://repository.php-baremetal.com/packages/php-baremetal/ext-slate)
package page.

## Build options

The options go in `extra.baremetal.options` of `composer.json`:

| Option | Default | What it does |
| --- | --- | --- |
| `slate-effects` | `["gradients", "shadows", "blur"]` | The graphic effects compiled into the firmware. An effect left out is not built, and the PHP call that uses it throws `\ValueError`. |
| `slate-debug` | `false` | Builds the profiler: frames per second on screen and a line of timings per second on the console. |

<!-- @code-block language="json" label="composer.json" -->
```json
"extra": {
  "baremetal": {
    "options": { "slate-effects": ["gradients"], "slate-debug": true }
  }
}
```
<!-- @endcode-block -->

## What it costs

Measured on the Waveshare ESP32-S3-Touch-LCD-1.85B (ESP32-S3 at 240 MHz, 32 KB instruction cache, PSRAM, ST77916
360 x 360 panel):

- **A still screen** composes no frame: the profiler reads `0.0 fps`.
- **A whole new screen** (a page change) takes about 13 ms for opaque shapes, gradients, text and arcs, which is
  about the time the panel needs to receive it; a full-screen radial gradient takes about 42 ms, a translucent
  full-screen group about 50 ms, and a shadow or a blur is built once, at its first frame (about 30 ms for a
  240 x 240 card's shadow, more for a blurred group), then reused.
- **Moving parts** are composed alone: only the part of the screen that changed is drawn, and what does not change
  underneath is copied instead of drawn again.
- **Memory:** the band buffers are in internal RAM (from the panel driver); a translucent group borrows a scratch
  area of internal RAM (at most 43 KB on a 360-wide panel) while it is drawn and gives it back; elements, texts and
  the images of shadows, blurs and cached groups live in PSRAM, within a budget of half the largest free block, at
  most 2 MB. On a board without PSRAM nothing is cached, and shadows and group blur are not drawn.
- **Effects** left out of `slate-effects` are not compiled and save their code (see Build options above).

## The examples

The examples run on `waveshare-esp32-s3-touch-lcd-1.85b` with the `event-driven` runtime.

- `06-display/slate-hello` shows every kind of element and effect on one page: a card with a gradient and a shadow,
  a disc with a radial gradient, a progress ring with round caps, lines, centred text, a translucent group, a
  blurred group and a small canvas. Touching the screen shows a second page, and touching it again goes back.
- `06-display/slate-gauge` has a `Gauge` component (an `Arc` and a `Text` in a `Group`) driven by the tilt of the
  QMI8658 IMU, polled at 100 Hz, and a dot orbiting at 60 Hz. It builds with `"slate-debug": true`, so the frames
  per second and the timings of the compositor are visible while the board moves. It runs at 60 frames per
  second, with no torn frame and no frame delayed by the compositor.
- `06-display/slate-stress` moves everything at once: a dozen translucent dots, a sliding card with a shadow, a
  group whose opacity pulses, a progress ring and a frame counter, all changed at 60 Hz, with the IMU at 100 Hz,
  a circle following the finger and Wi-Fi connecting in the background. It runs at about 55 frames per second,
  Wi-Fi connected or not.
- `06-display/slate-bench` shows thirteen full-screen scenes one after the other (opaque, translucent, gradients,
  shapes, text, an arc, a shadow, translucent, blurred and shadowed groups) and prints the time each takes to fill
  the screen.

```bash
composer install
phpbm flash
phpbm monitor
```

The Performance and Debug pages of the [ext-slate](https://repository.php-baremetal.com/packages/php-baremetal/ext-slate)
documentation explain how a frame is made and what each field of the profiler line means.
