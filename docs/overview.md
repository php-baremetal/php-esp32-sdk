---
eyebrow: 'SDK · Overview'
lede:    'The esp32 SDK builds the real PHP engine into firmware for ESP32-S3 and ESP32-P4 boards. phpbm drives it; you write PHP and pick packages.'
see_also:
  - { href: './getting-started/first-firmware.md', meta: 'Guide' }
  - { href: './boards/supported-boards.md', meta: 'Boards' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/overview.md', meta: 'CLI', label: 'phpbm overview' }
  - { href: 'https://github.com/php-baremetal/php-esp32-sdk', meta: 'source', label: 'php-esp32-sdk on GitHub' }
prev: { label: 'No previous page', href: '#' }
next: { label: 'Your first firmware', href: './getting-started/first-firmware.md' }
---

# Overview

`php-esp32-sdk` (SDK name `esp32`) runs the unmodified PHP engine on ESP32-S3 and ESP32-P4 boards. It is not a
program you run: it is the part of the toolchain that knows the chip. It carries the board descriptions, the
recipe that builds PHP for ESP-IDF, the runtimes and storages, and a PHP driver that phpbm calls to build,
flash, sync and monitor.

<!-- @callout variant="warning" title="Preview, not ready for use" -->
SDK 2.0 is not released. Until it is, phpbm reads the previews built from the SDK's `sdk` branch, versions
`2.0.0-alpha.N`, which `esp32@^2.0` accepts. Expect changes between previews and rough edges.
<!-- @endcallout -->

## How the pieces fit

| Piece | Role |
| --- | --- |
| phpbm | The command-line tool on your computer. It downloads the SDK, checks your project against it and calls its driver. See the [phpbm docs](https://github.com/php-baremetal/phpbm/blob/master/docs/overview.md). |
| SDK (`esp32`) | Boards, chips, PHP 8.3 to 8.5 build recipes, runtimes, storages, networks, options, the ESP-IDF host project and the driver. |
| Your project | A Composer project: `composer.json` with `extra.baremetal` and the PHP sources in `project-src/`. |
| Packages | The features: extensions (`ext-*`), drivers, and `php-baremetal/php-<name>` packages that switch on a PHP extension. Listed in the [registry](https://repository.php-baremetal.com). |
| conductor | The Composer plugin that installs the packages into `.phpbm/packages/`, adds the HAL for the SDK's platform and writes the manifest phpbm reads. |

The project names the SDK (`esp32@^2.0`), a board, a runtime and a storage. The SDK turns that into a
firmware image built with ESP-IDF v6.1.

## What it provides

| | |
| --- | --- |
| Chips | ESP32-S3 (`esp32s3`), ESP32-P4 (`esp32p4`) |
| PHP | 8.3, 8.4 and 8.5; the minor comes from `config.platform.php` in `composer.json` ([PHP versions and extensions](./reference/php.md)) |
| Boards | 13, from Espressif and Waveshare plus generic boards ([Supported boards](./boards/supported-boards.md)) |
| Runtimes | `init-loop`, `event-driven`, `web-server` ([Runtimes](./runtime/runtimes.md)) |
| Storages | `embedded` (sources in flash, read-only), `microsd` ([Storage](./runtime/storage.md)) |
| Networks | `wifi`, `w5500` (SPI Ethernet), `emac` (on-chip Ethernet), `wifi-c6` (Wi-Fi through a companion C6) ([Network](./runtime/network.md)) |
| Options | `network`, `on-error`, `restart-delay`, `firmware-reserve`, `optimization`, `power-save`, `request-timeout`; runtimes add `loop-interval`, `port` and `init` ([Options](./reference/options.md)) |

Memory, flash layout and the partition table are in [Memory, flash and partitions](./reference/memory-and-flash.md);
the toolchain phpbm installs for the SDK is in [Toolchain](./reference/toolchain.md).

## Requirements

- A supported board with external PSRAM: every board in the SDK has it, and the firmware is sized for it.
- A USB cable to the board's serial port.
- phpbm, the conductor, PHP 8.4 or later and Composer on your computer, plus Python 3.10 or later and about 6 GB
  of disk for ESP-IDF. Installation is described in the
  [phpbm docs](https://github.com/php-baremetal/phpbm/blob/master/docs/overview.md).

## Where next

- [Your first firmware](./getting-started/first-firmware.md): from `phpbm sdk:install esp32` to a running board.
- [Supported boards](./boards/supported-boards.md) and [Board files](./boards/board-files.md): what a board id means.
- [Runtimes](./runtime/runtimes.md): how your script is run.
- [Troubleshooting](./reference/troubleshooting.md).
