---
eyebrow: 'SDK · Boards'
lede:    'The 7 boards of the esp32 SDK and the boards of php-baremetal/boards-arduino, php-baremetal/boards-freenove, php-baremetal/boards-seeed and php-baremetal/boards-waveshare: chip, PSRAM, flash, console and the peripherals each one has.'
see_also:
  - { href: './board-files.md', meta: 'Reference' }
  - { href: './board-overrides.md', meta: 'Reference' }
  - { href: '../runtime/storage.md', meta: 'Storage' }
  - { href: '../runtime/network.md', meta: 'Network' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/boards-arduino', meta: 'Board package', label: 'boards-arduino' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/boards-freenove', meta: 'Board package', label: 'boards-freenove' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/boards-seeed', meta: 'Board package', label: 'boards-seeed' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/boards-waveshare', meta: 'Board package', label: 'boards-waveshare' }
prev: { label: 'IDE support', href: '../getting-started/ide-support.md' }
next: { label: 'Board files', href: './board-files.md' }
---

# Supported boards

A project selects its board with `extra.baremetal.board`, one of the ids below. The id is the file name without
`.json`, in the SDK's `boards/` directory or in the `boards/esp32/` directory of a board package (a Composer
package of type `bm-boards`). `phpbm discover` lists the ids that match the chip on a connected port. How a board
file is built, and how a board package is laid out, is in [Board files](./board-files.md).

## Boards of the SDK

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `espressif-esp32-s3-devkitc-1-n16r8` | Espressif ESP32-S3-DevKitC-1 (N16R8) | ESP32-S3 | octal | 16 MB | uart |
| `espressif-esp32-s3-devkitc-1-n8r2` | Espressif ESP32-S3-DevKitC-1 (N8R2) | ESP32-S3 | quad | 8 MB | uart |
| `espressif-esp32-s3-devkitc-1-n8r8` | Espressif ESP32-S3-DevKitC-1 (N8R8) | ESP32-S3 | octal | 8 MB | uart |
| `generic-esp32s3-quad` | ESP32-S3 (quad PSRAM, generic) | ESP32-S3 | quad | 4 MB | uart |
| `generic-esp32s3-octal` | ESP32-S3 (octal PSRAM, generic) | ESP32-S3 | octal | 8 MB | uart |
| `generic-esp32p4` | ESP32-P4 (generic) | ESP32-P4 | hex | 32 MB | uart |
| `php-baremetal-s3-discover` | php-baremetal S3-DISCOVER (coming soon) | ESP32-S3 | octal | 16 MB | usb-jtag |

The generic P4 board sets `revision` `v1` (see [Board files](./board-files.md)).

The three Espressif ESP32-S3-DevKitC-1 boards have an on-board WS2812 on GPIO 48 in GRB order (the `led` block).

## Peripherals

A board lists only the peripherals the SDK uses. The pin numbers are GPIOs.

| Board id | microSD | Network |
| --- | --- | --- |
| `espressif-esp32-s3-devkitc-1-n16r8` | none | `wifi` |
| `espressif-esp32-s3-devkitc-1-n8r2` | none | `wifi` |
| `espressif-esp32-s3-devkitc-1-n8r8` | none | `wifi` |
| `generic-esp32s3-quad` | none | `wifi` |
| `generic-esp32s3-octal` | none | `wifi` |
| `generic-esp32p4` | none | none |
| `php-baremetal-s3-discover` | `spi`: cs 11, sclk 13, mosi 12, miso 14 | `wifi` |

- An `sdio` slot (ESP32-P4) uses SDMMC slot 0. The pins it does not name take the defaults clk 43, cmd 44,
  d0 39, d1 40, d2 41, d3 42; `pwr_en` is absent unless the block sets it.
- The microSD slot is what the `microsd` storage needs, and a network block is what the `web-server` runtime
  needs. A board without the block cannot use them, unless the project adds the block with
  [board overrides](./board-overrides.md#adding-a-block) ([Storage](../runtime/storage.md), [Runtimes](../runtime/runtimes.md)).
- `wifi` and `wifi-c6` boards connect from PHP with `php-baremetal/ext-wifi`; `w5500` and `emac` come up
  before PHP starts when the network is on ([Network](../runtime/network.md)).
- A different wiring of a slot or a network block is set in the project with
  [board overrides](./board-overrides.md).

## Arduino boards

The Arduino Nano ESP32 is in the board package `php-baremetal/boards-arduino`, not in the SDK. Require it in the
project (`phpbm init` does it when you pick it), then use the id as for an SDK board:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/boards-arduino
```
<!-- @endcode-block -->

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `arduino-nano-esp32` | Arduino Nano ESP32 | ESP32-S3 | octal | 16 MB | usb-jtag |

Its board file names the pins as printed on the board (`D0` to `D13`, `A0` to `A7`, `LED_BUILTIN`, ...), so a
script writes `Pin::D13` instead of GPIO 48 ([Pin names](./board-files.md#pin-names)). Its blocks (Wi-Fi, the I2C
bus on A4 and A5), the full list of pins and the driver packages each block needs are in the package's
documentation:
[boards-arduino](https://repository.php-baremetal.com/packages/php-baremetal/boards-arduino). The SDK lists the
package in `sdk.json` (`board-packages`), which is how `phpbm init` finds it.

## Freenove boards

The Freenove ESP32-S3 WROOM Board Lite boards are in the board package `php-baremetal/boards-freenove`, not in the
SDK. Require it in the project (`phpbm init` does it when you pick one of them), then use the id as for an SDK
board:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/boards-freenove
```
<!-- @endcode-block -->

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `freenove-esp32-s3-wroom-lite-n16r8` | Freenove ESP32-S3 WROOM Board Lite (N16R8) | ESP32-S3 | octal | 16 MB | usb-jtag |
| `freenove-esp32-s3-wroom-lite-n16r16` | Freenove ESP32-S3 WROOM Board Lite (N16R16) | ESP32-S3 | octal | 16 MB | usb-jtag |

Their blocks (Wi-Fi, an on-board WS2812 LED), their pins and the driver packages each block needs are in the
package's documentation:
[boards-freenove](https://repository.php-baremetal.com/packages/php-baremetal/boards-freenove). The SDK lists the
package in `sdk.json` (`board-packages`), which is how `phpbm init` finds it.

## Seeed Studio boards

The Seeed Studio XIAO ESP32-S3 is in the board package `php-baremetal/boards-seeed`, not in the SDK. Require it in
the project (`phpbm init` does it when you pick it), then use the id as for an SDK board:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/boards-seeed
```
<!-- @endcode-block -->

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `seeed-xiao-esp32s3` | Seeed Studio XIAO ESP32-S3 | ESP32-S3 | octal | 8 MB | usb-jtag |

Its board file names the pins as printed on the board (`D0` to `D10`, `A0` to `A10`, `LED_BUILTIN`, ...), so a
script writes `Pin::D10` instead of GPIO 9 ([Pin names](./board-files.md#pin-names)). Its blocks (Wi-Fi, the I2C
bus on D4 and D5), the full list of pins and the driver packages each block needs are in the package's
documentation:
[boards-seeed](https://repository.php-baremetal.com/packages/php-baremetal/boards-seeed). The SDK lists the
package in `sdk.json` (`board-packages`), which is how `phpbm init` finds it.

## Waveshare boards

The Waveshare boards are in the board package `php-baremetal/boards-waveshare`, not in the SDK. Require it in the
project (`phpbm init` does it when you pick one of them), then use the id as for an SDK board:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/boards-waveshare
```
<!-- @endcode-block -->

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `waveshare-esp32-s3-eth` | Waveshare ESP32-S3-ETH | ESP32-S3 | octal | 16 MB | usb-jtag |
| `waveshare-esp32-s3-touch-lcd-1.85b` | Waveshare ESP32-S3-Touch-LCD-1.85B | ESP32-S3 | octal | 16 MB | usb-jtag |
| `waveshare-esp32-s3-touch-lcd-5b` | Waveshare ESP32-S3-Touch-LCD-5B | ESP32-S3 | octal | 16 MB | usb-jtag |
| `waveshare-esp32-s3-zero` | Waveshare ESP32-S3-Zero | ESP32-S3 | quad | 4 MB | usb-jtag |
| `waveshare-esp32-s3-zero-n8r8` | Waveshare ESP32-S3-Zero (N8R8) | ESP32-S3 | octal | 8 MB | usb-jtag |
| `waveshare-esp32-p4-eth` | Waveshare ESP32-P4-ETH | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-p4-pico` | Waveshare ESP32-P4-Pico | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-p4-wifi6` | Waveshare ESP32-P4-WIFI6 | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-p4-wifi6-touch-lcd-5` | Waveshare ESP32-P4-WIFI6-Touch-LCD-5 | ESP32-P4 | hex | 32 MB | uart |

Their peripherals (microSD slots, networks, displays, touch controllers, LEDs), their pins and the driver
packages each block needs are in the package's documentation:
[boards-waveshare](https://repository.php-baremetal.com/packages/php-baremetal/boards-waveshare). The SDK lists the package in
`sdk.json` (`board-packages`), which is how `phpbm init` finds it.

## php-baremetal S3-DISCOVER

`php-baremetal-s3-discover` is php-baremetal's own board, in prototyping and not on sale yet: an ESP32-S3R8
(octal PSRAM) with 16 MB of flash, 18 x 38.8 mm, USB-C on the chip's USB serial, a printed antenna, a microSD
slot, a LiPo charger and a WS2812 on GPIO 21 (GRB). Its I2C bus (SDA 42, SCL 41) carries a QMI8658A IMU
(0x6B, INT1 on GPIO 16) and an MMC5603NJ magnetometer used as a compass (0x30), driven by `php-baremetal/imu-qmi8658` and `php-baremetal/mag-mmc5603`; the battery voltage is on
GPIO 9 through a 1:2 divider. The slot is wired for 4-bit SD mode and the board file uses it in SPI mode, the one the SDK supports on
the ESP32-S3. `phpbm init` does not list the board until it ships; `--board php-baremetal-s3-discover` selects it.

## Generic boards

The `generic-*` boards describe a bare module with no carrier-specific peripherals, for a board the SDK has no
file for. Pick the one whose chip, PSRAM type and flash size match your module:

- `generic-esp32s3-quad`: ESP32-S3, quad PSRAM, 4 MB flash.
- `generic-esp32s3-octal`: ESP32-S3, octal PSRAM, 8 MB flash.
- `generic-esp32p4`: ESP32-P4, hex PSRAM, 32 MB flash, no microSD and no network block.

The two S3 generic boards declare `wifi`; the generic P4 declares nothing, so it runs `init-loop` and
`event-driven` with `embedded` storage only. To describe a different module, see
[Adding a board or chip](../development/adding-a-board.md).

## Requirements

<!-- @callout variant="warning" title="External PSRAM is required" -->
Every board of the SDK, of boards-arduino, of boards-freenove, of boards-seeed and of boards-waveshare has external PSRAM, and the firmware keeps the PHP heap and the PHP task stack there.
The PSRAM type of the board must match the chip: quad or octal on the ESP32-S3, hex on the ESP32-P4. The
flash size must be the real size of the module: the firmware's partition table is laid out from it.
<!-- @endcallout -->

Flash sizes that exist among the boards: 4 MB, 8 MB, 16 MB and 32 MB. Boards with a `usb-jtag` console print
on the chip's built-in USB serial; the others use a UART console.
