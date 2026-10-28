---
eyebrow: 'SDK · Reference'
lede:    'PHP 8.3, 8.4 and 8.5 run on the board, each pinned to one patch release. Eight modules are always built in; thirteen optional extensions are added by requiring a php-baremetal/php-name package.'
see_also:
  - { href: './options.md', meta: 'Reference' }
  - { href: './memory-and-flash.md', meta: 'Reference' }
  - { href: '../development/adding-php.md', meta: 'SDK development' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/php-mbstring', meta: 'Package', label: 'php-mbstring' }
prev: { label: 'Options', href: './options.md' }
next: { label: 'Memory, flash and partitions', href: './memory-and-flash.md' }
---

# PHP versions and extensions

The firmware runs the real PHP engine from php.net, with a small series of patches for the board. The PHP version
of a project is the minor in `config.platform.php` of its `composer.json` (`phpbm init` writes the newest).

## Versions

| Minor | Release in this SDK | Engine startup (`startup-kb`) | Patches |
| --- | --- | --- | --- |
| 8.3 | 8.3.35 | 760 KB | 13 |
| 8.4 | 8.4.26 | 816 KB | 14 |
| 8.5 | 8.5.11 | 872 KB | 15 |

The SDK carries one patch release per minor; an older one disappears with the next SDK release. The sources are
downloaded from php.net and checked against a sha256. `startup-kb` is the persistent PSRAM the engine takes at
startup: the firmware keeps it out of `memory_limit` (see [Memory, flash and partitions](./memory-and-flash.md)).

## Always built in

`core`, `hash`, `json`, `pcre`, `random`, `reflection`, `spl` and `standard`; PHP 8.5 adds `uri`. They cannot be
removed and have no package. Everything else is optional, and a project that does not require it pays nothing.

Without `php-date` the firmware builds with a small stand-in for the date code: `php_time()` and a minimal
formatting, in the UTC zone.

## Optional extensions

A project adds an extension with `composer require php-baremetal/php-<name>`. The package name is the extension
name: `php-baremetal/php-pdo_sqlite` adds `pdo_sqlite`. All thirteen exist on PHP 8.3, 8.4 and 8.5, with the same
cost.

`Flash` is the size the extension adds to the firmware, `Startup` the persistent PSRAM it takes at boot
(`startup-kb`). The numbers are approximate, measured with `optimization` set to `size`.

| Extension | Flash | Startup | Needs | Options |
| --- | --- | --- | --- | --- |
| `bcmath` | about 25 KB | 8 KB | | |
| `ctype` | about 3 KB | 2 KB | | |
| `date` | about 610 KB, about 255 KB with `date-timezones` `utc` | 60 KB | | `date-timezones` |
| `filter` | about 20 KB | 22 KB | | |
| `mbstring` | about 950 KB, about 200 KB with `mbstring-cjk` off; `mbstring-regex` adds about 445 KB | 13 KB, 21 KB with `mbstring-regex` | oniguruma 6.9.10 source | `mbstring-cjk`, `mbstring-regex` |
| `opcache` | about 280 KB, and `opcache-memory` KB of PSRAM taken from `memory_limit` | 62 KB | | `opcache-memory`, `opcache-validate` |
| `openssl` | about 85 KB with the mbedTLS code it links | 5 KB | ESP-IDF component `mbedtls` | |
| `pdo` | about 55 KB | 47 KB | | |
| `pdo_sqlite` | about 530 KB with `pdo` and the SQLite engine | 21 KB | extension `pdo`, SQLite 3.54.1 source | |
| `session` | about 45 KB | 18 KB | | |
| `sqlite3` | about 480 KB with the SQLite engine; with `pdo_sqlite` both come to about 550 KB with `pdo` | 26 KB | SQLite 3.54.1 source | |
| `tokenizer` | about 15 KB | 29 KB | | |
| `zlib` | about 56 KB with the zlib library | 26 KB | ESP-IDF component `espressif/zlib` 1.3.2 | |

The option names are described in [Options](./options.md). The sources an extension needs are fetched by phpbm and
checked by sha256; they are not part of your project. If a project requires `php-pdo_sqlite` without `php-pdo`,
the build stops with `php extension 'pdo_sqlite' needs 'pdo': composer require php-baremetal/php-pdo`.

Notes on single extensions:

- `session`: the default `memory` handler keeps up to 64 sessions, 64 KB in all, in PSRAM outside `memory_limit`.
  The `files` handler needs the `microsd` storage and writes to `/sdcard/sessions`.
- `sqlite3` and `pdo_sqlite`: SQLite allocates outside `memory_limit`, up to 256 KB of page cache per open
  connection. The `pdo_sqlite` busy timeout is 5 s by default; `PDO::ATTR_TIMEOUT` changes it.
- `zlib`: deflate needs about 260 KB of `memory_limit` while it runs.
- `openssl` is a partial API: AES-CBC, AES-CTR and AES-GCM, digests and random bytes, with only the
  `OPENSSL_RAW_DATA`, `OPENSSL_ZERO_PADDING` and `OPENSSL_DONT_ZERO_PAD_KEY` constants. It has no keys,
  signatures, X.509 or TLS.
- `opcache` has no JIT and keeps the cache in PSRAM; it accepts `opcache.memory_consumption` in KB with a `K`
  suffix (at least 256 KB).

## Patches

Every PHP minor gets the patches below, applied to the downloaded sources before the build. One line each:

| Patch | What it does |
| --- | --- |
| `closure-runtime-cache-arena` | Allocates a closure's run-time cache from the request arena instead of freeing it one by one, which corrupted the heap with scope-bound closures. |
| `ext-date-optional-minimal-tz` | With `date-timezones` `utc`, swaps the 350 KB timezone database for a UTC-only one (about 2.7 KB). |
| `mbstring-optional-no-cjk` | With `mbstring-cjk` off, drops the CJK encodings, the CJK languages of `mb_language()` and `mb_convert_kana()`. |
| `csprng-esp-getrandom` | Declares `getrandom()` for ESP-IDF, where it uses the hardware random generator. |
| `session-files-no-cloexec-warn` | The `files` session handler ignores the result of `FD_CLOEXEC`, which the FAT driver refuses. |
| `opcache-static-embed` | Links OPcache into the firmware and accepts the `embed` SAPI. |
| `opcache-malloc-shm-backend` | An OPcache shared-memory backend on a plain allocation in PSRAM, with the file locks turned off. |
| `phpinfo-baremetal-section` | A "PHP Baremetal" table in `phpinfo()`: project, board, firmware version and ESP-IDF version. |
| `zend-mm-platform-storage` | Lets the firmware hand the Zend memory manager its chunks from the PSRAM pool; keeps the page size when `sysconf` reports none. |
| `zend-small-startup-buffers` | Starts the garbage collector's root buffer at 4K entries (collection threshold 4001) and skips the optimizer's function table without OPcache. |
| `opcache-board-fixes` | `opcache.memory_consumption` in KB; falls back to `stat()` when the FAT driver reports a modification time of 0. |
| `zlib-gzopen-without-dup` | `gzopen()` and `compress.zlib://` work without `dup()`, which the ESP-IDF file system lacks. |
| `pdo-sqlite-busy-timeout` | The default `pdo_sqlite` busy timeout is 5 s instead of 60 s. |

Two more depend on the minor:

| Patch | Minors | What it does |
| --- | --- | --- |
| `zend-portability-sigsetjmp-guard` | 8.4, 8.5 | Uses plain `setjmp` again, because the C library has no `sigsetjmp`. |
| `uri-drop-lexbor-module-dep` | 8.5 | `ext/uri` no longer requires the `lexbor` module, which the firmware does not build. The RFC 3986 parser and `parse_url()` stay. |

## What is different on the board

The engine is the same; the board around it is not a Linux machine.

- **One PHP task.** The build is not thread-safe: PHP runs on one task, and native code never calls PHP from another.
- **No loadable extensions.** Extensions are compiled in; there are no `.so` files and no `zend_extension` to load.
- **No fork and no exec.** `FD_CLOEXEC` has no meaning here, and the file system has no `dup()`.
- **No `mmap`, no System V shared memory.** OPcache keeps its cache in PSRAM instead.
- **No signals and no `setitimer`.** `max_execution_time` still works, through a timer on the real clock: `sleep()`
  counts, and a loop inside C code is not interrupted.
- **No JIT.**
- **FAT file system.** Locks with `fcntl` do nothing on it. SQLite therefore opens databases exclusively, which
  keeps a reset in the middle of a transaction from corrupting the file.
- **`Uri\WhatWg\Url` (PHP 8.5) throws an `Error`** when constructed: the WHATWG parser is not built.
- **`PHP_OS` is `Linux`**, for the scripts that test it; `PHP_UNAME` and `PHP_BUILD_SYSTEM` are `ESP-IDF`.

Extensions that are not available at all: `intl`, `iconv`, `fileinfo`, `gd`, `curl`, the full `openssl` and TLS.
`mbstring` covers the conversions `iconv` would do.
