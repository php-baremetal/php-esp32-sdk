---
eyebrow: 'SDK · Runtimes'
lede:    'Where the PHP sources and the assets live on the board: embedded in a read-only image in flash, or on the microSD card and copied with phpbm sync.'
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

The project's assets (images and other files, see [Assets](#assets)) choose their storage folder by folder,
independently of the code.


## embedded

The build packs the whole source directory, plus the `embedded` assets, into a FAT image (the `storage.cmake`
of the storage calls `fatfs_create_rawflash_image(storage "${PHPBM_APP_SRC}" FLASH_IN_PROJECT)`, where phpbm sets
`PHPBM_APP_SRC` to the folder it prepared). The image is written to the `storage` partition together with the app,
by `phpbm flash`.

- **Size.** The partition is sized from the sources and the `embedded` assets: the space they use, rounded to
  4 KB units, plus 64 KB and one eighth, rounded up to a multiple of 64 KB, at least 128 KB.
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
change them with [board overrides](../boards/board-overrides.md); on a board without a slot, the same overrides
can [add one](../boards/board-overrides.md#adding-a-block). The pins must be GPIO numbers of the chip (0-48 on the
ESP32-S3, 0-54 on the ESP32-P4), and a `spi` slot needs all four.

### phpbm sync

`phpbm flash` copies the sources, and the `microsd` [assets](#assets), to the card after flashing. `phpbm sync`
copies them without building or flashing. Both talk to the firmware over the serial console.

<!-- @steps -->
- **The firmware listens at boot**

  After mounting the card it waits 1.5 seconds for a sync request on the console.

- **Only the difference is copied**

  `driver/tools/sync.py` tells the firmware the folders it manages (`app`, plus each `microsd` asset folder),
  lists the files in them, compares size and sha256 with the local ones, deletes what is no longer in the
  project and sends what changed. A file is written under a temporary name and renamed, so an interrupted copy
  leaves the old file. The rest of the card is never listed, written or deleted.

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

## Assets

Assets are files the program reads, such as the images of the [Slate](../modules/slate.md): `.png` files are
converted to `.sli` by phpbm, `.sli` files are copied as they are. They are declared folder by folder in
`extra.baremetal.assets`, each folder with its storage (see the
[phpbm project guide](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md#assets)):

```json
"assets": [
    { "dir": "assets/ui",     "storage": "embedded" },
    { "dir": "assets/photos", "storage": "microsd" }
]
```

On the board a file is at the storage's mount point plus its path in the project, with `.png` changed to `.sli`:

| In the project | Storage | On the board | Changes with |
| --- | --- | --- | --- |
| `assets/ui/icons/coffee.png` | `embedded` | `/app/assets/ui/icons/coffee.sli` | `phpbm flash` |
| `assets/photos/beans.png` | `microsd` | `/sdcard/assets/photos/beans.sli` | `phpbm flash` or `phpbm sync` |

| Code storage | `embedded` assets | `microsd` assets |
| --- | --- | --- |
| `embedded` | In the flash image, with the sources | On the card: the firmware mounts the card too |
| `microsd` | Refused by phpbm: there is no flash image | On the card, next to `/sdcard/app` |

- **`embedded` assets** go into the read-only image of the `storage` partition, and count in its size (see
  [Memory, flash and partitions](../reference/memory-and-flash.md#the-embedded-storage-image)).
- **`microsd` assets with the code in flash.** The firmware mounts the card at `/sdcard` after the image, on the
  board's slot (see [Boards and pins](#boards-and-pins)), and listens for `phpbm sync` for 1.5 seconds, like a
  `microsd` firmware. The board needs a microSD slot. Without a card the program still runs: the firmware prints
  `phpbm: microSD not mounted (<error>): its assets are not available`, and reading those files fails.
- **Copying to the card.** `phpbm flash` copies the `microsd` assets after flashing, and `phpbm sync` copies them
  without building, as for the sources. A folder may not be inside `/sdcard/app` when the code is on the card.

### Managed folders

`phpbm sync` changes only the folders it manages: `/sdcard/app` when the code is on the card, and each declared
`microsd` asset folder (`/sdcard/assets/photos` in the example). Inside them the card mirrors the project: a file
removed from the project is deleted from the card. Everything else on the card is never listed, written or
deleted: data the program writes, files an OTA update keeps there, other folders next to the asset folders. The
sync creates the parent folders a managed folder needs, and never removes them. A folder that stops being managed (removed
from `extra.baremetal.assets`, or moved to `embedded`) keeps its files on the card: delete them by hand if they are
no longer needed. An empty `microsd` folder stays managed: emptying it clears its files on the card at the next sync.
