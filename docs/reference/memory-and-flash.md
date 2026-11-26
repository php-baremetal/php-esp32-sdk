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

Every board of the SDK and of its board packages has PSRAM, and the firmware refuses to build without it: PHP's memory, the PHP task stack
and the OPcache cache all live there.

## memory_limit

At boot the firmware reserves one block of PSRAM for PHP's memory manager, the pool, in chunks of 256 KB: at least
512 KB and at most 64 MB (256 chunks). `memory_limit` is the pool less a headroom of 2 chunks (1 chunk on a small
PSRAM board, none when the pool has only 2 chunks), so it is always a multiple of 256 KB.

<!-- @code-block language="text" label="how the block is sized" -->
```text
keep   = firmware reserve + display framebuffers + startup + opcache-memory
chunks = (free PSRAM - keep) / 256 KB, at most 256, at least 2
memory_limit = (chunks - headroom) x 256 KB     headroom = 2 chunks (small profile: 1), at most chunks - 2
```
<!-- @endcode-block -->

| Profile | When | Startup | Firmware reserve | PHP stack | OPcache |
| --- | --- | --- | --- | --- | --- |
| Large | total PSRAM of 4 MB or more | `startup-kb` of the PHP minor, plus the `startup-kb` of each optional extension, plus 208 KB for packages | the `firmware-reserve` option, at most a quarter of the free PSRAM, plus the display framebuffers | 256 KB | `opcache-memory` KB taken from the block |
| Small | total PSRAM under 4 MB | `startup-kb` of the minor plus extensions, without the 208 KB | the display framebuffers only (`firmware-reserve` is logged as not applied) | 128 KB | disabled at boot |

- `firmware-reserve` (256 to 8192 KB, default 1024; at most a quarter of the free PSRAM) is the PSRAM kept for the
  rest of the firmware: the network, the HTTP server, the native extensions, Slate's caches and images. Raise it when
  a package runs out of PSRAM; lower it to give PHP more.
- The display framebuffers: a display block of the board whose driver is installed and keeps its framebuffers in
  PSRAM (types `rgb` and `dsi`: `width` x `height` x 2 bytes x `frames`, plus one more for a `dsi` panel with
  `rotation` 90, 180 or 270, whose drawing surface keeps its own framebuffer; 2400 KB for the two frames of the
  Waveshare ESP32-S3-Touch-LCD-5B, 5400 KB for the two frames and the turned surface of the Waveshare
  ESP32-P4-WIFI6-Touch-LCD-5) is reserved on top of `firmware-reserve` and outside its cap. The boot line adds
  `(display framebuffers <n> KB)`. The turned panel's surface framebuffer is left out when ext-display's
  `display-surface` option is `false` (a project that draws only with a renderer such as Slate): 3600 KB on that
  board, 1800 KB more for the rest of the firmware and PHP.
- `startup-kb` is in `sdk.json` per minor (760, 816, 872 and 984 KB for 8.3, 8.4, 8.5 and 8.6) and in each extension's
  descriptor: see [PHP versions and extensions](./php.md).
- With less than the minimum, the boot log says `PSRAM too small for PHP` and lists the numbers.

The boot prints the result: the first line contains `memory_limit <n> KB`, and the next one the free PSRAM after
the start. It adds `firmware reserve`, `opcache` or `PHP stack` only when they differ from the defaults, and always
`firmware reserve` with its display framebuffers when the board has some.

Measured pools (PHP 8.4 unless noted); `memory_limit` is the pool less the headroom:

| Board | Configuration | Pool | `memory_limit` | Free PSRAM after start |
| --- | --- | --- | --- | --- |
| `waveshare-esp32-s3-eth` (8 MB PSRAM) | blink | 5632 KB | 5120 KB | 1493 KB |
| `waveshare-esp32-s3-eth` | all thirteen extensions, `opcache-memory` 1024 | 4352 KB | 3840 KB | 1562, 1477 and 1407 KB on 8.3, 8.4 and 8.5 |
| `waveshare-esp32-p4-eth` (32 MB PSRAM) | blink | 30208 KB | 29696 KB | 1493 KB |
| `waveshare-esp32-s3-zero` (2 MB PSRAM, small profile) | blink | 1024 KB | 768 KB | 85 KB |
| `waveshare-esp32-s3-zero` | eleven extensions (all but mbstring and opcache), PHP 8.5 | 768 KB | 512 KB | 9 KB |

### Outside memory_limit

Some allocations come from the PSRAM left to the firmware, not from `memory_limit`:

- what PHP allocates as persistent;
- the garbage collector's root buffer: 16 KB at start, it grows and does not shrink;
- the SQLite page cache: up to 256 KB per open connection;
- the `memory` session store: at most 64 sessions and 64 KB;
- the zlib tables.

### When the memory runs out

- **A script that reaches `memory_limit`** ends with the usual `Allowed memory size of N bytes exhausted` fatal error,
  and the runtime's `on-error` applies. Since PHP 8.3.37, 8.4.28, 8.5.13 and 8.6 the engine raises that error a little
  after the limit is crossed: the allocation that crosses it is still served, from the headroom, and the error comes
  at the next instruction of the script.
- **A single allocation larger than what is left plus the headroom** (for example doubling a 4 MB string near the
  limit) cannot be served. PHP's hard memory limit is the pool: the firmware prints
  `Hard memory limit of N bytes exhausted (tried to allocate X bytes) in <file> on line <n>` and restarts (an
  `abort()`, as after any panic). Fragmentation can also leave no run of free chunks for a block larger than 256 KB:
  the firmware then prints `Out of memory` and restarts.
- A block larger than about 252 KB (a string, an array's table) takes whole 256 KB chunks, and `memory_limit` counts
  them whole: a 256 KB string counts as 512 KB in `memory_get_usage()`.

### Small PSRAM boards

A board with 2 MB of PSRAM (`waveshare-esp32-s3-zero`, `espressif-esp32-s3-devkitc-1-n8r2`) gets the small
profile: no firmware reserve, a 128 KB stack, OPcache off. If the pool is under 1024 KB the boot logs a warning.

Measured on an ESP32-S3 with 4 MB of flash and 2 MB of quad PSRAM (board `waveshare-esp32-s3-zero`, PHP 8.5):

- **Eleven extensions** (all but mbstring and opcache, see [What fits in 4 MB of flash](#what-fits-in-4-mb-of-flash)):
  the pool is 768 KB (`memory_limit` 512 KB) and the boot warns that the pool is under 1024 KB. A script starts with about 375 KB
  already used, and 9 KB of PSRAM stay for the rest of the firmware. A light check of each of the eleven passes,
  but a long script such as `12-extension-checks` runs out of memory: it needs a board with more PSRAM.
- **zlib:** `gzuncompress()` works, and so does compressing with a small window, for example
  `deflate_init($encoding, ['window' => 9, 'memory' => 1])`. `gzcompress()` with the default
  parameters fails with `Allowed memory size of N bytes exhausted (tried to allocate 65536 bytes)`, even as the first
  call of the script.
- **Wi-Fi:** in `08-network-web/wifi-ap-s3-rgb-manage` about 20 KB of PSRAM stay free after the
  start. Once a station joins the board's access point, the Wi-Fi buffers bring that down to about 1.2 KB.
  esp32-web-hal 1.0.2 and later then put HTTP requests in internal RAM; earlier versions answered every request
  with 503.

## The PHP task stack

PHP runs on one task, pinned to core 1, with its stack in PSRAM: 256 KB (128 KB on a small board). The firmware
sets `zend.max_allowed_stack_size` to the stack minus 16 KB, and `zend.reserved_stack_size` to a quarter of the
stack, at least 48 KB, so a deep recursion ends in a PHP error and not in a crash.

A task with its stack in PSRAM must not touch the flash. That is the reason for the rules in
[Writing native packages](../development/firmware-rules.md).

| Task | Core | Stack |
| --- | --- | --- |
| `main` | 0 | 16 KB on the ESP32-S3, 32 KB on the ESP32-P4; internal RAM |
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
| `storage` | data, `fat` | only with `embedded` storage: the sources and the `embedded` assets |

For example `factory` is 3840K on a 4 MB board, 7936K on 8 MB and 16128K on 16 MB or 32 MB, each with the smallest
`storage` (128K) and no package partition.

### Package partitions

A native package declares data partitions in its `extra.baremetal.partitions`: see
[Writing native packages](../development/firmware-rules.md). `php-baremetal/esp32-store-hal`, which backs
ext-store, declares `phpstore` (`nvs`, 24K). The names `factory`, `nvs`, `phy_init` and `storage` are reserved, two
packages cannot declare the same name, and each size is `<n>K` with `n` a multiple of 4.

### The embedded storage image

With `embedded` storage the PHP sources and the `embedded` assets are packed into a read-only FAT image in the
`storage` partition, written together with the application. Its size is computed from the folder phpbm prepares
(the sources plus those assets, converted; see [Assets](../runtime/storage.md#assets)):

- each file counts in 4096-byte units, each directory and the root count 4096 bytes: `used`;
- the image is `used` plus 64 KB plus an eighth of `used`, rounded up to 64K, and at least 128K.

At boot the partition is mapped into memory and mounted read-only at `/app`. Large images make the image, and the
flash it takes from `factory`, grow: put them in a `microsd` asset folder to keep them out of flash. With `microsd`
storage there is no `storage` partition: the sources live on the card and `phpbm sync` copies them. See
[Storage](../runtime/storage.md).

### Flash sizes

The `freenove-*` boards are in the board package `php-baremetal/boards-freenove`, the `waveshare-*` boards in
`php-baremetal/boards-waveshare`.

| Board | Flash | PSRAM |
| --- | --- | --- |
| `generic-esp32s3-quad`, `waveshare-esp32-s3-zero` | 4 MB | quad |
| `espressif-esp32-s3-devkitc-1-n8r2` | 8 MB | quad |
| `espressif-esp32-s3-devkitc-1-n8r8`, `generic-esp32s3-octal`, `waveshare-esp32-s3-zero-n8r8` | 8 MB | octal |
| `espressif-esp32-s3-devkitc-1-n16r8`, `freenove-esp32-s3-wroom-lite-n16r8`, `freenove-esp32-s3-wroom-lite-n16r16`, `waveshare-esp32-s3-eth`, `waveshare-esp32-s3-touch-lcd-1.85b`, `waveshare-esp32-s3-touch-lcd-5b` | 16 MB | octal |
| `generic-esp32p4`, `waveshare-esp32-p4-eth`, `waveshare-esp32-p4-pico`, `waveshare-esp32-p4-wifi6`, `waveshare-esp32-p4-wifi6-touch-lcd-5` | 32 MB | hex |

<!-- @callout variant="note" title="Only 16 MB are used on a 32 MB board" -->
With the quad flash bus (`quad` or `qio`), the cache maps only the first 16 MB of the flash, so the partitions stay below
that limit: the upper 16 MB of a 32 MB ESP32-P4 board stay unused. A board can set `flash-mode` to `octal` on an
ESP32-S3 (see [Board files](../boards/board-files.md)); no board of the SDK, of boards-freenove or of boards-waveshare does.
<!-- @endcallout -->

When the sources and the package partitions leave less than 1024K for the firmware, the build stops with
`the project sources (...K) and the package partitions (...K) leave ...K for the firmware (at least 1024K): use a
board with more flash or the microsd storage`.

### What fits in 4 MB of flash

On a 4 MB board the flash is the limit before the PSRAM. The `factory` partition is 3840K, and the app with
every optional extension does not fit. Measured on `waveshare-esp32-s3-zero` with PHP 8.5.11 and `optimization`
`size`:

| What | App size |
| --- | --- |
| The engine: Zend, standard, pcre | about 853, 530 and 255 KB |
| mbstring, plus oniguruma for `mbstring-regex` | 919 KB, plus 414 KB |
| date | 534 KB |
| SQLite, plus pdo, pdo_sqlite and sqlite3 | 387 KB, plus 70 KB |
| opcache | 63 KB |
| All thirteen extensions with `mbstring-regex` | 5.18 MB: does not fit |
| Eleven extensions, all but mbstring and opcache | 3.82 MB: fits, 108 KB left |

opcache is disabled at boot under 4 MB of PSRAM anyway, so leaving it out costs nothing. To fit mbstring, leave
out others that weigh as much: date and the SQLite group together weigh more than mbstring. The
[`log` option](./options.md#the-log-option) saves a little more: 75 KB at most with `none` in one measured
project.

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
