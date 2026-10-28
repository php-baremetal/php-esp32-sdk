---
eyebrow: 'SDK · Boards'
lede:    'A board is one JSON file in boards/: the chip, the memory, the console and the peripherals with their pins. This page lists every key, its values and what the SDK does with it.'
see_also:
  - { href: './supported-boards.md', meta: 'Boards' }
  - { href: './board-overrides.md', meta: 'Reference' }
  - { href: '../development/adding-a-board.md', meta: 'SDK development' }
prev: { label: 'Supported boards', href: './supported-boards.md' }
next: { label: 'Board overrides', href: './board-overrides.md' }
---

# Board files

The board id used in `extra.baremetal.board` is the file name without `.json`: `boards/waveshare-esp32-s3-eth.json`
is the board `waveshare-esp32-s3-eth`. When the SDK is loaded every file is checked against its chip; a failed
check stops phpbm with a message naming the file. To add a board, see
[Adding a board or chip](../development/adding-a-board.md); the boards that exist are in
[Supported boards](./supported-boards.md).

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

## Keys of the board

| Key | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Product name. Printed in the start-up line of the firmware. |
| `chip` | yes | A chip of the SDK: a directory in `chips/` (`esp32s3`, `esp32p4`). |
| `psram` | no | PSRAM type. Must be one the chip lists (table below). Selects `chips/<chip>/sdkconfig.<psram>`. |
| `flash` | yes | Flash size as a whole number of megabytes with `MB`, e.g. `16MB`. Sets the flash size of the image and the partition table. |
| `flash-mode` | no | Flash bus width. `quad` is the default; `octal` is accepted on the ESP32-S3. |
| `revision` | no | Silicon revision family the firmware targets. ESP32-P4 only: `v1` (default) or `v3`. |
| `console` | no | `uart` (default) or `usb-jtag`. Selects `host/sdkconfig/console-<value>`. |
| any other key | no | A peripheral block (below). |

Every other key of the file must be an object with a non-empty `type`.

### PSRAM, flash mode and revision per chip

| Chip | `psram` | `flash-mode` | `revision` |
| --- | --- | --- | --- |
| `esp32s3` | `quad`, `octal` | `quad`, `octal` | none: a `revision` is an error |
| `esp32p4` | `hex` | `quad` | `v1`, `v3` |

- `psram`: the PSRAM wiring of the module (for example the N16R8 DevKitC has octal, the N8R2 quad).
- `flash-mode`: `octal` is for modules with octal flash. It adds `chips/esp32s3/sdkconfig.flash-octal`.
- `revision`: `v1` builds for P4 chips older than revision 3, `v3` for revision v3.1 and later. A chip of the
  other family refuses to boot the image. `phpbm discover` prints the revision of the connected chip.
- `console`: `usb-jtag` uses the chip's built-in USB serial, `uart` the UART pins.

<!-- @callout variant="note" title="Values are checked, not guessed" -->
A `revision` or a `flash-mode` the chip has no `sdkconfig` fragment for is rejected at build time, and a `psram`
the chip does not list is rejected when the SDK is loaded. Messages are listed at the end of this page.
<!-- @endcallout -->

## Peripheral blocks

A block is named after a capability. Its `type` says which kind of that peripheral the board has, and it must be
one the chip supports. `chips/<chip>/chip.json` lists the supported types:

| Chip | `microsd` types | `network` types |
| --- | --- | --- |
| `esp32s3` | `spi` | `w5500`, `wifi` |
| `esp32p4` | `sdio` | `emac`, `wifi-c6` |

A block the chip has no capability for, or a type it does not list, is an error. The block is also what makes a
runtime or storage available: `storage: microsd` needs a `microsd` block, `runtime: web-server` needs a
`network` block.

All keys of a block other than `type` reach the build as `PHPBM_<BLOCK>_<KEY>` (upper case). A value must be a
number, a string or a boolean.

### microsd

| Type | Keys | Notes |
| --- | --- | --- |
| `spi` | `cs`, `sclk`, `mosi`, `miso` | SPI card on explicit pins. |
| `sdio` | `clk`, `cmd`, `d0`, `d1`, `d2`, `d3`, `pwr_en` | SDMMC slot 0, 4-bit. Defaults when absent: clk 43, cmd 44, d0 39, d1 40, d2 41, d3 42. `pwr_en` is the card power switch; absent or `-1` means none. |

Every `sdio` pin must be an integer from 0 to 54 (`pwr_en` may also be `-1`) when the storage is `microsd`.

### network

| Type | Keys | Notes |
| --- | --- | --- |
| `wifi` | none | The chip's own radio. The script connects with `php-baremetal/ext-wifi`. |
| `wifi-c6` | none | Wi-Fi through a companion ESP32-C6. Also connected with `ext-wifi`. |
| `w5500` | `cs`, `int`, `rst`, `sclk`, `mosi`, `miso` | SPI Ethernet controller; the pins are those of its SPI wiring. |
| `emac` | `rst` | The P4's on-chip Ethernet MAC; `rst` is the PHY reset pin. |

[Network](../runtime/network.md) describes how each type is brought up.

## Error messages

| Message | Cause |
| --- | --- |
| `boards/x.json: 'microsd' must be an object` | A key other than the board keys holds something that is not an object. |
| `boards/x.json: 'microsd.type' must be a non-empty string` | A peripheral block without a `type`. |
| `boards/x.json: chip 'abc' does not exist` | `chip` names no directory in `chips/`. |
| `boards/x.json: chip 'esp32s3' does not support psram 'hex'` | The `psram` is not in the chip's list. |
| `boards/x.json: chip 'esp32s3' does not support microsd 'sdio'` | The block, or its type, is not in the chip's capabilities. |
| `board x: unsupported flash size '16' (use e.g. 4MB)` | `flash` is not a whole number followed by `MB`. |
| `board x: chip 'esp32s3' has no revision 'v1'` | `revision` with no matching fragment for the chip. |
| `board x: chip 'esp32p4' does not support flash-mode 'octal'` | `flash-mode` with no matching fragment for the chip. |
| `board x: microsd.clk must be a GPIO number from 0 to 54` | An `sdio` pin outside the range, with `microsd` storage. |
| `board x: network.cs must be a number or a string` | A block value that is an array or an object. |

To change a pin in a project without editing the board file, use [board overrides](./board-overrides.md).
