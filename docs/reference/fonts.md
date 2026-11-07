---
eyebrow: 'SDK · Reference'
lede:    'Where the bitmap fonts of ext-display come from, how the build compiles them into the firmware, which header formats are accepted and what a font costs in flash.'
see_also:
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-display', meta: 'Package', label: 'ext-display' }
  - { href: './memory-and-flash.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md', meta: 'phpbm', label: 'The project' }
prev: { label: 'Power saving', href: './power-save.md' }
next: { label: 'Toolchain', href: './toolchain.md' }
---

# Fonts

Fonts are bitmap fonts compiled into the firmware. [ext-display](https://repository.php-baremetal.com/packages/php-baremetal/ext-display)
draws text with them and picks one by name. A project that does not use ext-display has no fonts.

## Where fonts come from

phpbm collects the fonts of a build from four places. Each font has a name; two fonts with the same name are an
error.

| Source | Name | Notes |
| --- | --- | --- |
| ext-display | `default` | The font shipped with the package, `fonts/default.h`. Set `extra.baremetal.fonts.default` to `false` to leave it out. |
| Font packages | The key under `extra.baremetal.fonts` | A package lists `"name": "path/to/font.h"`, relative to the package. |
| The project font folder | The file name without `.h` | Every `.h` file of the folder (`extra.baremetal.fonts.dir`) is a font. |
| `extra.baremetal.fonts.bake` | The `name` of the entry | phpbm bakes a TTF or BDF file into a header when it builds; the result is cached in `~/.phpbm/cache/fonts`. |

Using fonts without ext-display in the project is an error: `fonts need php-baremetal/ext-display`. The options
of the font folder and the bake list are described in the phpbm project guide.

## How the build compiles them

phpbm passes the resolved fonts to the SDK in the build plan (`BuildPlan::$fonts`). The SDK writes one source file
that includes each header and builds the `display_fonts[]` table, sorted by name, in the packages component.
ext-display reads that table to find a font by name.

## Accepted formats

A font header must define either:

- a `const GFXfont`, the Adafruit GFX format produced by `fontconvert`;
- a `const display_font_t`, the extended format produced by `phpbm font:bake`.

Any other file is refused at build time: `font file '<path>' is not a GFX font header`.

## Flash cost

A font costs the glyphs it holds, not the size of its source file. The default font has 191 glyphs at 1 bit per
pixel: its bitmap is 1954 bytes, plus a small table with one entry per glyph (the 21 KB of `default.h` is mostly
source text). Bake only the characters and sizes the project needs with `chars` and `range`, and use the lowest
`bpp` that looks right. The flash layout is in [Memory, flash and partitions](./memory-and-flash.md).
