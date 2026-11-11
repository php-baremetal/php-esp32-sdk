---
eyebrow: 'SDK · Boards'
lede:    'extra.baremetal.board-overrides changes keys of a board''s peripheral blocks from the project, for example other pins, or adds a block the board does not have, without editing the SDK.'
see_also:
  - { href: './board-files.md', meta: 'Reference' }
  - { href: './supported-boards.md', meta: 'Boards' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md', meta: 'CLI', label: 'phpbm: The project' }
prev: { label: 'Board files', href: './board-files.md' }
next: { label: 'Runtimes', href: '../runtime/runtimes.md' }
---

# Board overrides

`board-overrides` is an optional key of `extra.baremetal`. It maps the name of a peripheral block to the keys to
change in that block, or to a whole new block. Use it when your hardware wires a slot or a network controller to
other pins than the board file says, or when you wire a peripheral the board file does not have, such as a microSD
slot on a generic board.

## What it changes

Each entry is merged over the board's block: the keys you give replace the same keys, the keys you leave out keep
the board's value, and a key the block did not have is added. The result is what the build sees.

<!-- @code-block language="JSON" label="composer.json: microSD on other SDMMC pins" -->
```json
{
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-p4-pico",
      "runtime": "init-loop",
      "storage": "microsd",
      "board-overrides": {
        "microsd": { "clk": 14, "d3": 18 }
      }
    }
  }
}
```
<!-- @endcode-block -->

Here `clk` and `d3` of the `sdio` slot change; `cmd`, `d0`, `d1` and `d2` keep their defaults.

<!-- @code-block language="JSON" label="composer.json: other pins for the W5500" -->
```json
{
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-s3-eth",
      "runtime": "web-server",
      "storage": "embedded",
      "board-overrides": {
        "network": { "cs": 21, "int": 20 }
      }
    }
  }
}
```
<!-- @endcode-block -->

An entry for a block the board has may repeat its `type` (it has no effect); a different `type` is an error. The
keys each block takes are in [Board files](./board-files.md), and the pin rules there apply to the result: the
pins of a `microsd` or `network` block must be GPIO numbers of the chip, and `pwr_en` of an `sdio` slot may also be
`-1`.

After changing `extra.baremetal`, run `composer update --lock` so Composer and the manifest follow.

## Adding a block

An entry for a block the board does not have adds it, on any board. Write the block whole, with its `type`: the
board has nothing to merge it with. Repeat `type` in an entry only when you mean to add the block: on a board without
it, `{"type": "spi", "cs": 9}` is a block with one pin, not a change of one pin.

The name of an added block is a block kind: one used by the SDK's board files or chips (`microsd`, `network`,
`display`, `i2c`, `touch`, `led`), or one an installed package declares in `extra.baremetal.board-blocks`. A second
block of a kind takes a suffix, for example `led-2`. A name is lower-case letters and digits, with parts joined by
`-`. Any other name, a typo such as `netwrok` or a word such as `php`, fails before the build.

<!-- @code-block language="JSON" label="composer.json: a microSD slot on a generic ESP32-S3" -->
```json
{
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "generic-esp32s3-octal",
      "runtime": "init-loop",
      "storage": "embedded",
      "assets": [
        { "dir": "assets/photos", "storage": "microsd" }
      ],
      "board-overrides": {
        "microsd": { "type": "spi", "cs": 10, "sclk": 12, "mosi": 11, "miso": 13 }
      }
    }
  }
}
```
<!-- @endcode-block -->

The generic board now has a `spi` microSD slot: `microsd` assets and `"storage": "microsd"` accept it, and the
firmware mounts the card on these pins. Everything that looks at the board, in phpbm and in the build, sees the
board after the overrides.

What an added block is checked against depends on who reads it:

- **`microsd` and `network`**, implemented by the SDK: the `type` must be one the chip supports (`spi` for a
  microSD slot on the ESP32-S3, `sdio` on the ESP32-P4), every pin the type needs must be there (a `spi` slot
  needs `cs`, `sclk`, `mosi` and `miso`), and each pin must be a GPIO number of the chip. The build checks this
  before it compiles anything, and the error names the missing or wrong key.
- **`display`, `i2c`, `touch`, `led` and other blocks read by packages**: the driver checks the block when the
  script calls its `onboard()`. A block of another type, or one without a key the driver needs, throws a
  `RuntimeException` that names the board, the block and the key, for example
  `board 'generic-esp32s3-octal': display.cs is missing`.

When `phpbm board` changes the board, an override that adds a block stays on the new board and counts for the
runtime and storage check: added when the new board does not have the block, merged over it when the new board has
it with the same `type`. It is dropped (`phpbm board` lists it and asks first) when the new board has the block
with another `type`, when the new chip does not support the type of an SDK block (a `spi` microSD slot on an
ESP32-P4), and when it gave the `type` but only some keys of a block the old board had: such a partial block cannot
stand alone on a board without it.

Added `wifi-c6` and `emac` networks build on any ESP32-P4 board, but their wiring is that of the reference designs:
only `rst` of `emac` can be set (the RMII pins and the IP101 PHY are fixed), and the `wifi-c6` link uses the
SDIO wiring of the reference board.

## What it cannot change

<!-- @callout variant="warning" title="Types are fixed, blocks are never removed" -->
Overrides cannot change the `type` of a block the board has, or remove a block; a board with a `w5500` network
cannot be turned into `wifi` this way. Pick another board, or [add one](../development/adding-a-board.md).
<!-- @endcallout -->

The top-level keys of the board (`name`, `chip`, `psram`, `flash`, `flash-mode`, `revision`, `console`) are not
blocks: they cannot be overridden, and they cannot be added as blocks either. Naming one fails with the "has no"
error below.

## Error messages

| Message | Cause |
| --- | --- |
| `board-overrides: board 'x' has no 'display' to override: to add the block, give its 'type'` | The name is not a block of the board and the entry has no `type`: a typo, or a block to add that needs its `type`. |
| `board-overrides: board 'x' has no 'flash' to override` | A top-level key of the board, which is not a block. |
| `board-overrides: 'display.type' must be a non-empty string` | An added block whose `type` is empty or not a string. |
| `board-overrides: 'microsd' must be an object` | The value is not an object, for example a list. |
| `board-overrides: 'microsd.type' cannot be overridden: board 'x' has a 'sdio' microsd` | The entry gives a `type` other than the board's. |
| `board x: chip 'esp32s3' does not support microsd 'sdio' (it supports spi)` | An added `microsd` or `network` block whose type the chip does not have. |
| `board x: microsd.cs is missing (a 'spi' microsd needs cs, sclk, mosi, miso)` | A pin the block's type needs is not there. |
| `board x: microsd.clk must be a GPIO number from 0 to 54` | A pin is not an integer in the chip's range (0-48 on the ESP32-S3, 0-54 on the ESP32-P4). |
| `board x: network.rst must be a GPIO number from 0 to 48, or -1 for none` | The same, for a pin that may also be `-1` (`rst` of a network, `pwr_en` of an `sdio` slot). |
| `board-overrides: 'Led_2' is not a block name (lower-case letters and digits, parts joined by '-', e.g. led-2)` | The name of an added block is not written as a block name. |
| `board-overrides: 'netwrok' is not a block of sdk esp32 2.0.0 or of an installed package (blocks: ...); a second block of a kind takes a suffix, e.g. led-2` | phpbm: an added block whose name is no block kind (a typo, or a word such as `php`). |
| `board-overrides: chip 'esp32p4' does not support microsd 'spi' (it supports sdio)` | phpbm: an added `microsd` or `network` block whose type the chip does not have. |
| `board x: network.component would set PHPBM_NETWORK_COMPONENT, which the build sets itself: rename the block or the key` | A block key that would overwrite a variable of the build. |
