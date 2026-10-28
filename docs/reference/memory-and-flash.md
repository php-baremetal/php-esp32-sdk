---
eyebrow: 'SDK · Reference'
lede:    'How the PSRAM is split between PHP and the firmware, what the PHP task stack is, how the flash is partitioned, and what the optimization option costs.'
see_also:
  - { href: './options.md', meta: 'Reference' }
  - { href: '../development/firmware-rules.md', meta: 'SDK development' }
  - { href: '../boards/supported-boards.md', meta: 'Boards' }
  - { href: '../runtime/storage.md', meta: 'Runtime' }
prev: { label: 'PHP versions and extensions', href: './php.md' }
next: { label: 'Power saving', href: './power-save.md' }
---

# Memory, flash and partitions

Every board of the SDK has PSRAM, and the firmware refuses to build without it: PHP's memory, the PHP task stack
and the OPcache cache all live there.

## memory_limit

At boot the firmware reserves one block of PSRAM for PHP's memory manager, in chunks of 256 KB. The block size is
`memory_limit`; it is always a multiple of 256 KB, at least 512 KB and at most 64 MB (256 chunks).

<!-- @code-block language="text" label="how the block is sized" -->
```text
keep   = firmware reserve + startup + opcache-memory
chunks = (free PSRAM - keep) / 256 KB, at most 256, at least 2
memory_limit = chunks x 256 KB
```
<!-- @endcode-block -->

| Profile | When | Startup | Firmware reserve | PHP stack | OPcache |
| --- | --- | --- | --- | --- | --- |
| Large | total PSRAM of 4 MB or more | `startup-kb` of the PHP minor, plus the `startup-kb` of each optional extension, plus 208 KB for packages | the `firmware-reserve` option, at most a quarter of the free PSRAM | 256 KB | `opcache-memory` KB taken from the block |
| Small | total PSRAM under 4 MB | `startup-kb` of the minor plus extensions, without the 208 KB | 0 (logged at boot) | 128 KB | disabled at boot |

- `firmware-reserve` (256 to 4096 KB, default 1024) is the PSRAM kept for the rest of the firmware: the network, the
  HTTP server, the native extensions. Raise it when a package runs out of PSRAM; lower it to give PHP more.
- `startup-kb` is in `sdk.json` per minor (760, 816 and 872 KB for 8.3, 8.4 and 8.5) and in each extension's
  descriptor: see [PHP versions and extensions](./php.md).
- With less than the minimum, the boot log says `PSRAM too small for PHP` and lists the numbers.

The boot prints the result: the first line contains `memory_limit <n> KB`, and the next one the free PSRAM after
the start. It adds `firmware reserve`, `opcache` or `PHP stack` only when they differ from the defaults.

Measured memory limits (PHP 8.4 unless noted):

| Board | Configuration | `memory_limit` | Free PSRAM after start |
| --- | --- | --- | --- |
| `waveshare-esp32-s3-eth` (8 MB PSRAM) | blink | 5632 KB | 1493 KB |
| `waveshare-esp32-s3-eth` | all thirteen extensions, `opcache-memory` 1024 | 4352 KB | 1562, 1477 and 1407 KB on 8.3, 8.4 and 8.5 |
| `waveshare-esp32-p4-eth` (32 MB PSRAM) | blink | 30208 KB | 1493 KB |
| `waveshare-esp32-s3-zero` (2 MB PSRAM, small profile) | blink | 1024 KB | 85 KB |

### Outside memory_limit

Some allocations come from the PSRAM left to the firmware, not from `memory_limit`:

- what PHP allocates as persistent;
- the garbage collector's root buffer: 16 KB at start, it grows and does not shrink;
- the SQLite page cache: up to 256 KB per open connection;
- the `memory` session store: at most 64 sessions and 64 KB;
- the zlib tables.

Large fragmented allocations can fail below `memory_limit`. A script that exhausts the limit ends with the usual
`Allowed memory size of N bytes exhausted` fatal error.

### Small PSRAM boards

A board with 2 MB of PSRAM (`waveshare-esp32-s3-zero`, `espressif-esp32-s3-devkitc-1-n8r2`) gets the small
profile: no firmware reserve, a 128 KB stack, OPcache off. If the pool is under 1024 KB the boot logs a warning.

## The PHP task stack

PHP runs on one task, pinned to core 1, with its stack in PSRAM: 256 KB (128 KB on a small board). The firmware
sets `zend.max_allowed_stack_size` to the stack minus 16 KB, and `zend.reserved_stack_size` to a quarter of the
stack, at least 48 KB, so a deep recursion ends in a PHP error and not in a crash.

A task with its stack in PSRAM must not touch the flash. That is the reason for the rules in
[Writing native packages](../development/firmware-rules.md).

| Task | Core | Stack |
| --- | --- | --- |
| `main` | 0 | 32 KB, internal RAM |
| `php` | 1 | 256 KB, PSRAM (128 KB under 4 MB of PSRAM) |
| executor | 0 | 8 KB, internal RAM |

## Flash layout

The partition table is generated at every build. The flash size comes from the board file; the partition offsets
are left to ESP-IDF, so the application sits at `0x10000`.

| Partition | Type | Size |
| --- | --- | --- |
| `nvs` | data, `nvs` | 24K |
| `phy_init` | data, `phy` | 4K |
| `factory` | app | the flash, minus 128K, minus the `storage` partition and the package partitions, rounded down to a multiple of 64K |
| package partitions | data | as the packages declare them, after `factory` |
| `storage` | data, `fat` | only with `embedded` storage |

For example `factory` is 3840K on a 4 MB board, 7936K on 8 MB and 16128K on 16 MB or 32 MB, each with the smallest
`storage` (128K) and no package partition.

### Package partitions

A native package declares data partitions in its `extra.baremetal.partitions`: see
[Writing native packages](../development/firmware-rules.md). `php-baremetal/esp32-store-hal`, which backs
ext-store, declares `phpstore` (`nvs`, 24K). The names `factory`, `nvs`, `phy_init` and `storage` are reserved, two
packages cannot declare the same name, and each size is `<n>K` with `n` a multiple of 4.

### The embedded storage image

With `embedded` storage the PHP sources are packed into a read-only FAT image in the `storage` partition, written
together with the application. Its size is computed from the source directory:

- each file counts in 4096-byte units, each directory and the root count 4096 bytes: `used`;
- the image is `used` plus 64 KB plus an eighth of `used`, rounded up to 64K, and at least 128K.

At boot the partition is mapped into memory and mounted read-only at `/app`. With `microsd` storage there is no
`storage` partition: the sources live on the card and `phpbm sync` copies them. See [Storage](../runtime/storage.md).

### Flash sizes

| Board | Flash | PSRAM |
| --- | --- | --- |
| `generic-esp32s3-quad`, `waveshare-esp32-s3-zero` | 4 MB | quad |
| `espressif-esp32-s3-devkitc-1-n8r2` | 8 MB | quad |
| `espressif-esp32-s3-devkitc-1-n8r8`, `generic-esp32s3-octal`, `waveshare-esp32-s3-zero-n8r8` | 8 MB | octal |
| `espressif-esp32-s3-devkitc-1-n16r8`, `waveshare-esp32-s3-eth`, `waveshare-esp32-s3-touch-lcd-1.85b` | 16 MB | octal |
| `generic-esp32p4`, `waveshare-esp32-p4-eth`, `waveshare-esp32-p4-pico`, `waveshare-esp32-p4-wifi6` | 32 MB | hex |

<!-- @callout variant="note" title="Only 16 MB are used on a 32 MB board" -->
With the default quad flash mode, the cache maps only the first 16 MB of the flash, so the partitions stay below
that limit: the upper 16 MB of a 32 MB ESP32-P4 board stay unused. A board can set `flash-mode` to `octal` on an
ESP32-S3 (see [Board files](../boards/board-files.md)); no board of the SDK does.
<!-- @endcallout -->

When the sources and the package partitions leave less than 1024K for the firmware, the build stops with
`the project sources (...K) and the package partitions (...K) leave ...K for the firmware (at least 1024K): use a
board with more flash or the microsd storage`.

## Firmware size and the optimization option

`optimization` sets the compiler optimization of the whole firmware. Measured on `waveshare-esp32-s3-eth` with PHP
8.4.26 and the blink project:

| Value | Flag | Firmware | PHP benchmark (lower is faster) |
| --- | --- | --- | --- |
| `size` | `-Os` | 2480560 B | 2271 ms |
| `performance` | `-O2` | 2808512 B | 2136 ms |
| `debug` | `-Og` | 2785744 B | 2477 ms |

`performance` makes the firmware about 13 percent bigger and the PHP benchmark about 6 percent faster. `debug` keeps
the code close to the source for debugging with gdb: it is bigger and slower than `size`.

Size of the blink firmware by PHP minor (`-Os`):

| Chip | 8.3 | 8.4 | 8.5 |
| --- | --- | --- | --- |
| ESP32-S3 | about 2270 KB | about 2355 KB | about 2425 KB |
| ESP32-P4 | 2516 KB | 2612 KB | 2692 KB |

With all the optional extensions, the firmware is 4767 KB on `waveshare-esp32-s3-eth` and 5221 KB on
`waveshare-esp32-p4-eth`.
