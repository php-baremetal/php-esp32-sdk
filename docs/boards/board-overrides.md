---
eyebrow: 'SDK · Boards'
lede:    'extra.baremetal.board-overrides changes keys of a board''s peripheral blocks from the project, for example other pins, without editing the SDK.'
see_also:
  - { href: './board-files.md', meta: 'Reference' }
  - { href: './supported-boards.md', meta: 'Boards' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md', meta: 'CLI', label: 'phpbm: The project' }
prev: { label: 'Board files', href: './board-files.md' }
next: { label: 'Runtimes', href: '../runtime/runtimes.md' }
---

# Board overrides

`board-overrides` is an optional key of `extra.baremetal`. It maps the name of a peripheral block of the board
to the keys to change in that block. Use it when your hardware wires a slot or a network controller to other
pins than the board file says.

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

The blocks you can name are the ones the board has: `microsd` and `network` on the boards in
[Supported boards](./supported-boards.md), and the keys they take are in [Board files](./board-files.md). The
`sdio` pin rule still applies: with `microsd` storage every pin must be an integer from 0 to 54, and `pwr_en`
may also be `-1`.

After changing `extra.baremetal`, run `composer update --lock` so Composer and the manifest follow.

## What it cannot change

<!-- @callout variant="warning" title="Blocks and types are fixed" -->
Overrides touch the keys of blocks the board already has. They cannot add a block, remove one, or change a
block's `type`; a board with a `w5500` network cannot be turned into `wifi` this way. Pick another board, or
[add one](../development/adding-a-board.md).
<!-- @endcallout -->

The top-level keys of the board (`name`, `chip`, `psram`, `flash`, `flash-mode`, `revision`, `console`) are not
blocks and cannot be overridden: naming one fails with the "has no" error below.

## Error messages

| Message | Cause |
| --- | --- |
| `board-overrides: board 'x' has no 'display' to override` | The name is not a peripheral block of the board (a typo, a block the board lacks, or a top-level key such as `flash`). |
| `board-overrides: 'microsd' must be an object` | The value is not an object, for example a list. |
| `board-overrides: 'microsd.type' cannot be overridden` | The entry contains `type`. |
| `board x: microsd.clk must be a GPIO number from 0 to 54` | A pin of an `sdio` slot is not an integer in range. |
