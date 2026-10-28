---
eyebrow: 'SDK · Boards'
lede:    'The 13 boards of the esp32 SDK: chip, PSRAM, flash, console and the peripherals each one has.'
see_also:
  - { href: './board-files.md', meta: 'Reference' }
  - { href: './board-overrides.md', meta: 'Reference' }
  - { href: '../runtime/storage.md', meta: 'Storage' }
  - { href: '../runtime/network.md', meta: 'Network' }
prev: { label: 'Your first firmware', href: '../getting-started/first-firmware.md' }
next: { label: 'Board files', href: './board-files.md' }
---

# Supported boards

A project selects its board with `extra.baremetal.board`, one of the ids below. The id is the file name in the
SDK's `boards/` directory without `.json`. `phpbm discover` lists the ids that match the chip on a connected
port. How a board file is built is in [Board files](./board-files.md).

## Boards

| Board id | Product | Chip | PSRAM | Flash | Console |
| --- | --- | --- | --- | --- | --- |
| `espressif-esp32-s3-devkitc-1-n16r8` | Espressif ESP32-S3-DevKitC-1 (N16R8) | ESP32-S3 | octal | 16 MB | uart |
| `espressif-esp32-s3-devkitc-1-n8r2` | Espressif ESP32-S3-DevKitC-1 (N8R2) | ESP32-S3 | quad | 8 MB | uart |
| `espressif-esp32-s3-devkitc-1-n8r8` | Espressif ESP32-S3-DevKitC-1 (N8R8) | ESP32-S3 | octal | 8 MB | uart |
| `generic-esp32s3-quad` | ESP32-S3 (quad PSRAM, generic) | ESP32-S3 | quad | 4 MB | uart |
| `generic-esp32s3-octal` | ESP32-S3 (octal PSRAM, generic) | ESP32-S3 | octal | 8 MB | uart |
| `generic-esp32p4` | ESP32-P4 (generic) | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-s3-eth` | Waveshare ESP32-S3-ETH | ESP32-S3 | octal | 16 MB | usb-jtag |
| `waveshare-esp32-s3-touch-lcd-1.85b` | Waveshare ESP32-S3-Touch-LCD-1.85B | ESP32-S3 | octal | 16 MB | usb-jtag |
| `waveshare-esp32-s3-zero` | Waveshare ESP32-S3-Zero | ESP32-S3 | quad | 4 MB | usb-jtag |
| `waveshare-esp32-s3-zero-n8r8` | Waveshare ESP32-S3-Zero (N8R8) | ESP32-S3 | octal | 8 MB | usb-jtag |
| `waveshare-esp32-p4-eth` | Waveshare ESP32-P4-ETH | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-p4-pico` | Waveshare ESP32-P4-Pico | ESP32-P4 | hex | 32 MB | uart |
| `waveshare-esp32-p4-wifi6` | Waveshare ESP32-P4-WIFI6 | ESP32-P4 | hex | 32 MB | uart |

The P4 boards also set `revision` to `v1` (see [Board files](./board-files.md)).

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
| `waveshare-esp32-s3-eth` | `spi`: cs 4, sclk 7, mosi 6, miso 5 | `w5500`: cs 14, int 10, rst 9, sclk 13, mosi 11, miso 12 |
| `waveshare-esp32-s3-touch-lcd-1.85b` | `spi`: cs 13, sclk 15, mosi 14, miso 16 | `wifi` |
| `waveshare-esp32-s3-zero` | none | `wifi` |
| `waveshare-esp32-s3-zero-n8r8` | none | `wifi` |
| `waveshare-esp32-p4-eth` | `sdio`: pwr_en 45 | `emac`: rst 51 |
| `waveshare-esp32-p4-pico` | `sdio` | none |
| `waveshare-esp32-p4-wifi6` | `sdio` | `wifi-c6` |

- `sdio` boards use SDMMC slot 0. The pins not named in the table take the defaults clk 43, cmd 44, d0 39,
  d1 40, d2 41, d3 42; `pwr_en` is absent unless the board sets it.
- The microSD slot is what the `microsd` storage needs, and a network block is what the `web-server` runtime
  needs. A board without the block cannot use them ([Storage](../runtime/storage.md), [Runtimes](../runtime/runtimes.md)).
- `wifi` and `wifi-c6` boards connect from PHP with `php-baremetal/ext-wifi`; `w5500` and `emac` come up
  before PHP starts when the network is on ([Network](../runtime/network.md)).
- A different wiring of a slot or a network block is set in the project with
  [board overrides](./board-overrides.md).

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
Every board of the SDK has external PSRAM, and the firmware keeps the PHP heap and the PHP task stack there.
The PSRAM type of the board must match the chip: quad or octal on the ESP32-S3, hex on the ESP32-P4. The
flash size must be the real size of the module: the firmware's partition table is laid out from it.
<!-- @endcallout -->

Flash sizes that exist among the boards: 4 MB, 8 MB, 16 MB and 32 MB. Boards with a `usb-jtag` console print
on the chip's built-in USB serial; the others use a UART console.
