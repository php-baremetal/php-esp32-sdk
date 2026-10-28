---
eyebrow: 'SDK development'
lede:    'A board is one JSON file under boards/. Pick the chip, the PSRAM mode, the flash and the console, add the peripheral blocks with their pins, then prove it with the tests and on the board.'
see_also:
  - { href: '../boards/board-files.md', meta: 'Boards' }
  - { href: '../boards/supported-boards.md', meta: 'Boards' }
  - { href: './working-on-the-sdk.md', meta: 'SDK development' }
prev: { label: 'Working on the SDK', href: './working-on-the-sdk.md' }
next: { label: 'Adding a PHP extension or minor', href: './adding-php.md' }
---

# Adding a board or chip

The format of a board file is described in [Board files](../boards/board-files.md); this page is the
contributor's path.

<!-- @callout variant="warning" title="Two hard requirements" -->
The part needs external PSRAM and at least 4 MB of flash, or the PHP engine does not fit. The loader does
not enforce this (`psram` is optional in the file); the review and your hardware test do.
<!-- @endcallout -->

## Add a board

<!-- @steps -->
- **Pick the chip and the PSRAM mode**

  The chip is a directory under `chips/`: `esp32s3` or `esp32p4`. Its `chip.json` lists the PSRAM modes it
  supports: `quad` and `octal` on the ESP32-S3, `hex` on the ESP32-P4. The mode is a property of the module
  (an N8R8 has octal PSRAM, an N8R2 quad), so read the datasheet of your module, not the chip's.

- **Copy the closest board**

  Start from `boards/<vendor>-<model>.json` of the nearest board in the same chip family, and name your file
  `<vendor>-<model>.json`. The file name, without `.json`, is the board id that projects write in
  `extra.baremetal.board`. Use the vendor's product name for `name`, never a nickname.

- **Fill the identity**

  `name`, `chip`, `flash` (a whole number of megabytes: `"16MB"`, the driver rejects anything else) and
  `psram` (`quad`, `octal` or `hex`, one the chip lists). `console` is `uart` (the default) or `usb-jtag`:
  set `usb-jtag` when the board's USB port is the chip's own USB Serial/JTAG. `flash-mode` and `revision` are
  optional, see below.

- **Add the peripheral blocks**

  Every other key of the file is a peripheral block: an object with a `type`. The chip must support that
  type for that block name, which is what its `capabilities` says: `microsd` (`spi` on the ESP32-S3,
  `sdio` on the ESP32-P4) and `network` (`w5500` and `wifi` on the ESP32-S3, `emac` and `wifi-c6` on the
  ESP32-P4). The other keys of a block are the pins and settings the storage or network reads.

- **Test it**

  Add the board to `tests/SdkDescriptorsTest.php` and run `vendor/bin/phpunit`. The loader already rejects a
  chip that does not exist, a PSRAM mode the chip does not support, and a block type the chip does not
  support. The tests also check how a board turns into build inputs.

- **Flash it**

  Link your checkout (`phpbm sdk:link esp32 .`), build and flash a project for the board, and run a program
  that exercises everything you declared: the console, the storage (a microSD sync), the network. Keep the
  serial output for the pull request.
<!-- @endsteps -->

### Peripheral blocks

| Block | `type` | Keys read (required unless noted) |
|---|---|---|
| `microsd` | `spi` | `cs`, `sclk`, `mosi`, `miso` |
| `microsd` | `sdio` | `clk`, `cmd`, `d0`, `d1`, `d2`, `d3`, `pwr_en` (all optional: the SDMMC slot of the chip supplies defaults; `pwr_en` of `-1` means none) |
| `network` | `w5500` | `cs`, `int`, `rst`, `sclk`, `mosi`, `miso` |
| `network` | `emac` | `rst` (the PHY reset GPIO) |
| `network` | `wifi` | none |
| `network` | `wifi-c6` | none |

Each key becomes `PHPBM_<BLOCK>_<KEY>` in `inputs.cmake`, and the storage and network components read it.
A value is a number or a string. SDIO pins must be GPIO numbers from 0 to 54 (the ESP32-P4), or the build
stops with `board <id>: microsd.<pin> must be a GPIO number from 0 to 54`. `board-overrides` in a project can
change the keys of a block that the board declares, never its `type`.

### A sample board

<!-- @code-block language="JSON" label="boards/waveshare-esp32-s3-eth.json" -->
```json
{
    "name": "Waveshare ESP32-S3-ETH",
    "chip": "esp32s3",
    "psram": "octal",
    "flash": "16MB",
    "console": "usb-jtag",
    "microsd": {
        "type": "spi",
        "cs": 4,
        "sclk": 7,
        "mosi": 6,
        "miso": 5
    },
    "network": {
        "type": "w5500",
        "cs": 14,
        "int": 10,
        "rst": 9,
        "sclk": 13,
        "mosi": 11,
        "miso": 12
    }
}
```
<!-- @endcode-block -->

A board without peripherals is only the first five keys: `generic-esp32p4` has no `microsd` and no
`network`, and then no runtime or storage that needs them can be used on it.

## Flash mode and silicon revision

Two optional keys select the sdkconfig fragments of the chip:

| Key | Default | Fragment | Error when missing |
|---|---|---|---|
| `flash-mode` | `quad` | `chips/<chip>/sdkconfig.flash-<mode>` (none for `quad`) | `board <id>: chip '<chip>' does not support flash-mode '<mode>'` |
| `revision` | `v1` where the chip has `sdkconfig.rev-v1`, none otherwise | `chips/<chip>/sdkconfig.rev-<revision>` | `board <id>: chip '<chip>' has no revision '<revision>'` |

The ESP32-S3 has `flash-octal`; the ESP32-P4 has the
revisions `v1` (silicon before 3.0, the Waveshare P4 boards so far) and `v3`. The names are lowercase letters and digits.

## Add a chip variant

A chip is a directory, and everything the driver and the CMake host know about it is in these files:

| File | Purpose |
|---|---|
| `chips/<chip>/chip.json` | `name`, `target` (the ESP-IDF target passed as `IDF_TARGET`), `capabilities` (a map from `microsd` and `network` to the list of types the chip supports) and `psram` (the list of modes). All four are required. |
| `chips/<chip>/sdkconfig` | Optional. Fragment applied to every board of the chip. |
| `chips/<chip>/sdkconfig.<psram>` | The fragment of each PSRAM mode in `psram`. |
| `chips/<chip>/sdkconfig.rev-<revision>` | One per silicon revision a board may name. |
| `chips/<chip>/sdkconfig.flash-<mode>` | One per non-quad flash mode a board may name. |

A fragment that does not exist is skipped by the CMake host, so a PSRAM mode in `chip.json` without its
`sdkconfig.<psram>` builds a firmware that does not enable the PSRAM. Write the fragment.

A variant of an existing chip (a new PSRAM mode, flash mode or revision) is a new `chip.json` value plus a
fragment. A new chip family also needs support in ESP-IDF 6.1, in the host components, and in the storages and networks that its
capabilities name; open an issue or a discussion before writing it. Add the chip to
`test_chips()` and a board for it to `test_every_board_loads()` in `tests/SdkDescriptorsTest.php`.
