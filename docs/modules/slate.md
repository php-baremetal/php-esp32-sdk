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

## The examples

Both examples run on `waveshare-esp32-s3-touch-lcd-1.85b` with the `event-driven` runtime.

- `06-display/slate-hello` shows every kind of element and effect on one page: a card with a gradient and a shadow,
  a disc with a radial gradient, a progress ring with round caps, lines, centred text, a translucent group, a
  blurred group and a small canvas. Touching the screen shows a second page, and touching it again goes back.
- `06-display/slate-gauge` has a `Gauge` component (an `Arc` and a `Text` in a `Group`) driven by the tilt of the
  QMI8658 IMU, polled at 100 Hz, and a dot orbiting at 60 Hz. It builds with `"slate-debug": true`, so the frames
  per second and the timings of the compositor are visible while the board moves.

```bash
composer install
phpbm flash
phpbm monitor
```

The Performance and Debug pages of the [ext-slate](https://repository.php-baremetal.com/packages/php-baremetal/ext-slate)
documentation explain how a frame is made and what each field of the profiler line means.
