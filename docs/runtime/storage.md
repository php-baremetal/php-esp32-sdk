---
eyebrow: 'SDK · Runtimes'
lede:    'Where the PHP sources live on the board: embedded in a read-only image in flash, or on the microSD card and copied with phpbm sync.'
see_also:
  - { href: '../boards/board-overrides.md', meta: 'Boards' }
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/commands/project.md', meta: 'phpbm', label: 'phpbm project commands' }
prev: { label: 'web-server', href: './web-server.md' }
next: { label: 'Network', href: './network.md' }
---

# Storage

A project picks one storage with `extra.baremetal.storage`. Both storages run the same PHP: only the place of the
files differs. The source directory is `src` (default `project-src`) and the entry script is `entry` inside it
(default `index.php`); see the
[phpbm project guide](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md).

| | `embedded` | `microsd` |
| --- | --- | --- |
| Where the sources are | A read-only FAT image in the `storage` flash partition | The folder `/sdcard/app` of the microSD card |
| Mount point | `/app` | `/sdcard`, sources in `/sdcard/app` |
| Requires | Nothing | Capability `microsd`: the board needs a microSD slot |
| Changing a script | Rebuild and flash | `phpbm sync` (no build, no flash) |
| Writable by PHP | No | Yes, everywhere on the card; `/sdcard/app` is managed by `phpbm sync` |

## embedded

The build packs the whole source directory into a FAT image (the `storage.cmake` of the storage calls
`fatfs_create_rawflash_image(storage "${PHPBM_APP_SRC}" FLASH_IN_PROJECT)`). The image is written to the
`storage` partition together with the app, by `phpbm flash`.

- **Size.** The partition is sized from the sources: the space they use, rounded to 4 KB units, plus 64 KB and
  one eighth, rounded up to a multiple of 64 KB, at least 128 KB.
- **Flash budget.** What the storage and the package partitions leave is the firmware's. If that is under 1024 KB
  the build stops and suggests a board with more flash or the `microsd` storage. See
  [Memory, flash and partitions](../reference/memory-and-flash.md).
- **Read-only.** At boot the partition is mapped into memory and mounted at `/app`. Writes to it fail. PHP reads
  scripts through the flash cache, with no flash operation.
- **OPcache.** With the `opcache` extension, timestamp validation is always off: the image changes only with a
  flash.

Write data that PHP produces somewhere else: an `ext-store` key, or a microSD card.

## microsd

The sources live on the card, so a script changes in seconds and the flash holds only the firmware. The
firmware mounts the card at `/sdcard`, and the sources are in `/sdcard/app`. The rest of the card is free for
the scripts' own data.

### Boards and pins

The board file describes the slot (see [Board files](../boards/board-files.md)); a board without a `microsd`
block cannot use this storage. The chip decides the type:

| Board | Slot type | Pins |
| --- | --- | --- |
| `waveshare-esp32-s3-eth` | `spi` | cs 4, sclk 7, mosi 6, miso 5 |
| `waveshare-esp32-s3-touch-lcd-1.85b` | `spi` | cs 13, sclk 15, mosi 14, miso 16 |
| `waveshare-esp32-p4-eth` | `sdio` | SDMMC slot 0 on its default pins, card power switch on GPIO 45 (`pwr_en`) |
| `waveshare-esp32-p4-wifi6` | `sdio` | SDMMC slot 0 on its default pins |
| `waveshare-esp32-p4-pico` | `sdio` | SDMMC slot 0 on its default pins |

- **`spi`** (ESP32-S3): the card uses the SPI3 host. The Waveshare ESP32-S3-ETH keeps SPI2 for its W5500.
- **`sdio`** (ESP32-P4): SDMMC slot 0 in 4-bit mode, powered by the on-chip LDO channel 4. Without pins in the
  board file the defaults are `clk` 43, `cmd` 44, `d0` 39, `d1` 40, `d2` 41, `d3` 42 and no power pin. SDMMC slot
  1 stays free for the Wi-Fi companion link.

If the first mount fails the firmware retries at a low frequency (the P4 in 1-bit mode). To use other pins,
change them with [board overrides](../boards/board-overrides.md); the pins of an `sdio` slot must be GPIO
numbers from 0 to 54.

### phpbm sync

`phpbm flash` copies the sources to the card after flashing. `phpbm sync` copies them without building or
flashing. Both talk to the firmware over the serial console.

<!-- @steps -->
- **The firmware listens at boot**

  After mounting the card it waits 1.5 seconds for a sync request on the console.

- **Only the difference is copied**

  `driver/tools/sync.py` lists the files on the card, compares size and sha256 with the local ones, deletes what
  is no longer in the source directory and sends what changed. A file is written under a temporary name and
  renamed, so an interrupted copy leaves the old file.

- **The board restarts**

  The firmware prints `phpbm: sync session over, restarting` and restarts, then runs the new sources.
<!-- @endsteps -->

<!-- @callout variant="warning" title="The env file is never copied" -->
Files named `.env` and the project's `env` file are skipped. The env file is built into the firmware and read
as `$_ENV` and `getenv()`; the card never holds it.
<!-- @endcallout -->

### Limits

- **File names.** Printable ASCII only, no trailing `.` or space, none of `\ : " * < > ? |`, no name ending in
  `.part` or `.old`, and a path under 256 bytes. The card's names are case-insensitive, so two names that
  differ only in case are refused.
- **OPcache.** With the `opcache` extension the option `opcache-validate` (default off) makes OPcache check
  script timestamps, for PHP files the program itself writes on the card. Off, the sources change only with a
  flash or a sync, which restart the board.
- **No card, no program.** The firmware stops with `--- stopped: no microSD card ---` when the card cannot be
  mounted, and with `--- stopped: no <entry> on the microSD card (run phpbm sync) ---` when the entry script is
  missing. Then it restarts after `restart-delay`.
