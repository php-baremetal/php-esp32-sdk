---
eyebrow: 'SDK · Modules'
lede:    'Open a SPI host, talk to a chip by chip select, or drive the QSPI round display of the Waveshare ESP32-S3-Touch-LCD-1.85B. Two hosts exist, and microSD storage or a W5500 network already takes one.'
see_also:
  - { href: './i2c-and-sensors.md', meta: 'Module' }
  - { href: '../boards/supported-boards.md', meta: 'Boards' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-spi', meta: 'Package', label: 'ext-spi' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/esp32-display-st77916', meta: 'Package', label: 'esp32-display-st77916' }
prev: { label: 'I2C and sensors', href: './i2c-and-sensors.md' }
next: { label: 'Events and timers', href: './events-and-timers.md' }
---

# SPI and displays

[ext-spi](https://repository.php-baremetal.com/packages/php-baremetal/ext-spi) is a SPI master. `Baremetal\Spi\Bus`
opens a SPI host on your pins (four data lines for QSPI), `Baremetal\Spi\Device` exchanges raw bytes with one chip
on it, and driver packages add typed classes under `Baremetal\Spi\Driver\`.

```bash
composer require php-baremetal/ext-spi                  # bus and raw devices
composer require php-baremetal/esp32-display-st77916    # ST77916 QSPI round display, ESP32 only
```

The display driver requires ext-spi, so it is enough on its own. The conductor adds the esp32 HAL
(`esp32-spi-hal`).

## Bus and raw devices

`new Bus(int $sclk, int $mosi, int $miso = -1, int $data2 = -1, int $data3 = -1, int $host = 1)` opens a host.
`miso` of `-1` makes a write-only bus; the bus is four-line only when both `data2` and `data3` are given. `host`
`1` is `SPI2_HOST` and `2` is `SPI3_HOST`. A second `new Bus()` on the same host and pins returns the same bus.

`Bus::device(int $cs, int $hz = 1000000, int $mode = 0): Device` attaches the chip whose chip select is GPIO `$cs`.
A `Device` has `transfer(string $tx): string` (full duplex, same length back), `write(string $data)` and
`read(int $length): string`. CS stays active for the whole call.

<!-- @code-block language="php" label="read the JEDEC id of a SPI flash chip" -->
```php
<?php
use Baremetal\Spi\Bus;

$bus   = new Bus(sclk: 12, mosi: 11, miso: 13);
$flash = $bus->device(cs: 10, hz: 10_000_000);

$rx = $flash->transfer("\x9F\x00\x00\x00");          // JEDEC ID command + 3 dummy bytes
printf("JEDEC id: %s\n", bin2hex(substr($rx, 1)));
```
<!-- @endcode-block -->

## The ST77916 display

`esp32-display-st77916` adds `Baremetal\Spi\Driver\St77916`, a driver for 360 x 360 QSPI round panels (the size is
a parameter, 1 to 4096 per side). Colours are RGB565 integers.

| Method | Meaning |
| --- | --- |
| `new St77916(Bus $bus, int $cs = 21, int $rst = 3, int $bl = -1, int $width = 360, int $height = 360, ...)` | Bring the panel up; also `x_gap`, `y_gap`, `mirror_x`, `mirror_y`, `swap_xy`, `invert` for orientation |
| `fill(int $color)` | Paint the whole panel |
| `rect(int $x, int $y, int $w, int $h, int $color)` | Paint a solid rectangle, clipped to the panel; origin top-left |
| `rgb(int $r, int $g, int $b): int` | Pack 8-bit components into RGB565 |
| `wake()` | Re-initialise the panel after a light sleep, then repaint |

The example `06-display/display-hello` runs on `waveshare-esp32-s3-touch-lcd-1.85b` with the `init-loop` runtime
and requires `ext-sys`, `ext-spi` and `esp32-display-st77916`. It cycles the background colour:

<!-- @code-block language="php" label="06-display/display-hello/project-src/index.php" -->
```php
<?php
use Baremetal\Spi\Bus;
use Baremetal\Spi\Driver\St77916;
use Baremetal\Output\Display;

$bus = new Bus(sclk: 40, mosi: 46, miso: 45, data2: 42, data3: 41);   // QSPI: mosi=D0, miso=D1
$lcd = new St77916($bus, cs: 21, rst: 3, bl: 5, width: 360, height: 360);

printf("St77916 instanceof Output\\Display: %s\n", $lcd instanceof Display ? "yes" : "no");

function loop(int $tick): void
{
    global $lcd;
    $colors = [$lcd->rgb(255, 0, 0), $lcd->rgb(0, 255, 0), $lcd->rgb(0, 0, 255), $lcd->rgb(255, 255, 255)];
    $lcd->fill($colors[$tick % 4]);
    delay(1000);
}
```
<!-- @endcode-block -->

The panel memory is not cleared at start: call `fill()` first. The driver owns the wire, so `transfer()`, `write()`
and `read()` on an `St77916` always fail with `ESP_ERR_INVALID_STATE`.

## OLED displays on I2C

The `06-display` folder has two more examples, `oled-ssd1306-ext` and `oled-ssd1306-fps`. They drive an SSD1306
128 x 32 OLED over I2C (SDA on GPIO 7, SCL on GPIO 8) on `waveshare-esp32-p4-pico`, and neither uses ext-spi or a
registry package. They show two ways to add a driver that is not in the registry:

| Example | How the driver is added |
| --- | --- |
| `oled-ssd1306-fps` | Pure PHP. `composer.json` requires only `ext-sys` and `ext-gpio`; the driver is the class `SSD1306` in `project-src/SSD1306.php`, loaded with `require __DIR__ . '/SSD1306.php'`. It bit-bangs I2C over two pins with `gpio_write()` and `gpio_read()` and reports the frames per second. |
| `oled-ssd1306-ext` | A project-local C extension. The extension is a folder `extensions/ssd1306` with its own `composer.json` (type `bm-ext`, module `ssd1306`) and a C file. The project's `composer.json` lists it as a `path` repository (`"url": "extensions/ssd1306"`) and requires it as `php-baremetal-examples/ssd1306`; `phpbm flash` compiles it into the firmware and the script calls `ssd1306_begin()`, `ssd1306_text()`, `ssd1306_flush()` and the other `ssd1306_*` functions. |

The first one needs no C and is the easier start; the second is faster because the I2C link, the framebuffer and
the text rendering run in C. The example keeps the same panel in both so that you can compare the frame rates.

## SPI hosts and the board

ESP32 chips give `Bus` two general-purpose hosts, and the firmware may already use them:

| What | Host it takes |
| --- | --- |
| `microsd` storage in SPI mode | `SPI3_HOST` (`host: 2`) |
| `w5500` network | `SPI2_HOST` (`host: 1`) |

Opening a host the firmware already uses fails with `ESP_ERR_INVALID_STATE`. With microSD storage keep the default
`host: 1`; with a W5500 network pass `host: 2`. A board that uses both (`waveshare-esp32-s3-eth` with `microsd` and
the network on) has no host left for `Bus`. Board wiring is in [Supported boards](../boards/supported-boards.md).

## What the ESP32 does

| Behaviour | Detail |
| --- | --- |
| Transfers | One call blocks the PHP task until the transfer is done; no other task is involved |
| Size | At most 32768 bytes per call; more fails with `ESP_ERR_INVALID_SIZE`, so split it |
| DMA | Hosts use DMA on an automatic channel; PHP strings are copied through a bounce buffer |
| Limits | 2 buses and 8 devices in all (each display takes one) |
| Clock | 1 MHz by default; `$hz` and `$mode` (0 to 3) are not checked by ext-spi, and are ignored when the chip select is already attached |
| Display clock | The ST77916 runs at 40 MHz in quad mode; a full-panel fill takes about 17 ms at 360 x 360 |
| Light sleep | With `power-save` on, or after `sys_light_sleep()`, the QSPI lines may be disturbed: call `$lcd->wake()`, then repaint |
| Lifetime | Buses and devices stay open until the board restarts, across requests too |

## Common errors

| Message | Cause |
| --- | --- |
| `cannot open SPI host N: ...` | The host or a pin is used by a bus on other pins, both bus slots are in use, or the firmware already uses the host |
| `cannot add SPI device cs=N: ...` | The chip select is held by a driver, or all 8 device slots are in use |
| `SPI transfer failed: ...` | The transfer failed, was too long, or the device is an `St77916` |
| `display on cs N already initialised with different parameters` | The panel was already created on this bus and CS with other arguments |
| `st77916 init failed: ...` | Out of internal RAM for the band buffer, or the panel did not initialise |
| `st77916 fill failed: ...` | A band did not finish within 1 second (`ESP_ERR_TIMEOUT`) |
| `st77916 failed: a display transfer never completed` | After a timeout the panel refuses to draw until the board restarts |

The full API is on the [ext-spi](https://repository.php-baremetal.com/packages/php-baremetal/ext-spi) and
[esp32-display-st77916](https://repository.php-baremetal.com/packages/php-baremetal/esp32-display-st77916) package
pages.
