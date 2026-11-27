# Changelog

## [2.0.0] - Unreleased (previews: 2.0.0-alpha.N)

2.0 is a rewrite: the firmware is a Composer project built by [phpbm](https://github.com/php-baremetal/phpbm),
and this repository is the ESP32 SDK that phpbm downloads and drives. Until 2.0.0 is released, every push to
the `sdk` branch publishes a preview `2.0.0-alpha.N` on the `sdk-preview` pre-release, which phpbm installs by
default. Previews are not ready for use: anything can still change.

### Changed
- **The Waveshare and Freenove boards are in board packages.** The 9 `waveshare-*` board files moved, unchanged, to
  `php-baremetal/boards-waveshare` and the 2 `freenove-*` board files to `php-baremetal/boards-freenove` (Composer
  type `bm-boards`, with their catalog and the Freenove photos); the SDK keeps the Espressif and generic boards. A
  project on a Waveshare board must `composer require php-baremetal/boards-waveshare`, one on a Freenove board
  `composer require php-baremetal/boards-freenove` (`phpbm init` adds it); the board ids stay the same. `sdk.json`
  lists the official board packages in `board-packages`, and the driver accepts `bm-boards` packages and compiles
  nothing of them. A board package's boards are checked like the SDK's;
  an id defined by two sources is picked with `<vendor>/<package>:<id>` or `sdk:<id>`
  ([Board files](docs/boards/board-files.md#board-packages)). Needs php-baremetal/spec 1.5.
- **The SDK has a board catalog.** `boards/catalog.json` gives the documentation page and product photo of the
  Espressif boards, and marks the generic boards with `"generic": true`, in the format of a board
  package's catalog ([Board catalog](docs/boards/board-files.md#board-catalog)). `catalog.json` is a reserved name in
  `boards/`: the SDK does not read it as a board (php-baremetal/spec 1.4.1 and later).
- **PHP 8.3.37, 8.4.28 and 8.5.13** (were 8.3.35, 8.4.26 and 8.5.11). The patches apply as before;
  `zend-mm-platform-storage` is rebased and also gives the firmware's heap the default hard memory limit that
  these releases add (`zend_mm_startup_ex()` leaves it unset).
- **Running out of PHP memory is a fatal error again, not a reboot.** `memory_limit` is the pool less 2 chunks (1
  on small-PSRAM boards) that serve the allocation crossing it, huge blocks count as the whole chunks they take
  (patch `zend-mm-huge-chunk-align`), and an allocation larger than the pool stops at PHP's hard limit with its file
  and line.
- **`firmware-reserve` goes up to 8192 KB** (was 4096), still at most a quarter of the free PSRAM: a large-screen app
  on a 32 MB board can leave more PSRAM to Slate's caches and images (the Waveshare ESP32-P4-WIFI6-Touch-LCD-5 gets
  about 7.7 MB, a quarter of its free PSRAM).
- **The turned MIPI-DSI panel's drawing surface can be left out**: with ext-display's `display-surface` option `false`
  (`extra.baremetal.options`) the build does not reserve the surface framebuffer of a `dsi` block with `rotation`
  90, 180 or 270 (`width` x `height` x 2 bytes: 1800 KB on the Waveshare ESP32-P4-WIFI6-Touch-LCD-5, whose display
  framebuffers go from 5400 to 3600 KB). By default, and with any other panel, nothing changes.
- **Native packages can be written in C++.** A package without `sources` compiles `src/*.c` and `src/*.cpp`;
  `phpbm.h` has C linkage when included from C++. The rules (C linkage for the module entry and the registered
  symbols) are in [Writing native packages](docs/development/firmware-rules.md#writing-the-package-in-c).
- **A project is a `composer.json`.** `extra.baremetal` names the SDK (`"sdk": "esp32@^2.0"`), the `board`,
  the `runtime` and the `storage`, plus optional `src`, `entry`, `env`, `options` and `board-overrides`.
  `config.platform.php` is the PHP version of the firmware.
- **Features are packages.** Extensions (`ext-gpio`, `ext-i2c`, `ext-wifi`, …), drivers and PHP extensions
  (`php-baremetal/php-<name>`) come from the [registry](https://repository.php-baremetal.com) with
  `composer require`. The `php-baremetal/conductor` plugin installs them and adds the ESP32 HAL each one
  needs.
- **Boards are JSON files** under `boards/` of the SDK or of a board package, selected by id (e.g.
  `espressif-esp32-s3-devkitc-1-n8r8`). `phpbm discover` lists the boards that match the connected chip.
- **ESP-IDF v6.1.1**, installed by `phpbm toolchain:install` into `esp-idf-v6.1.1` (`Toolchain::IDF_VERSION`;
  v6.1.1 or a later v6.1.x is required). It has fixes for the ESP32-P4 v3 memory protection (PMP) set-up. An
  older v6 folder next to it (`esp-idf-v6.1`, from a preview) is reported as too old and can be deleted.
- **The ESP32-P4 app CPU waits for the scheduler with a pause between reads** (`host/components/phpbm/startup.c`;
  `host/post-project.cmake` compiles ESP-IDF's `esp_startup_start_app_other_cores()` under another name). ESP-IDF
  polls the scheduler flag on core 1 with no pause; on the P4, whose cores share the L1 data cache, this could stall
  core 0 until its data bus timed out, a store access fault while the first task is created, before `app_main`.
  Whether it happened depended on the firmware's layout: some images faulted on every start, on v6.1 and on v6.1.1
  alike (one image on the Waveshare ESP32-P4-WIFI6-Touch-LCD-5: all 102 starts in 12 cold resets faulted before,
  none in 20 cold resets with the fix). It adds at most 10 µs to the start. The configure fails if ESP-IDF's
  function runs new code before its wait (`host/cmake/idf-startup.cmake`), rather than risk a silent hang at boot.
- **PHP's memory chunks start with a zeroed header** (`host/components/phpbm/memory.c`). The Zend memory manager
  takes every chunk to be zero-filled, as `mmap()` returns it; the PSRAM pool held whatever was in the PSRAM (random
  bytes after power-on), so a stale page bitmap could hand out pages that were in use. A large app then often
  crashed while its scripts compiled at start (`zend_mm_heap corrupted`, load faults in the compiler): on the
  Waveshare ESP32-P4-WIFI6-Touch-LCD-5, 9 of 20 cold starts of one app and up to 20 of 20 of another, 0 of 20 with
  the fix. The first page of each chunk (4 KB) is cleared when PHP takes it.
- **ESP32-S3 runs at 240 MHz** (`chips/esp32s3/sdkconfig`); the ESP-IDF default is 160 MHz.
- **ESP32-S3 has a 32 KB instruction cache** (`chips/esp32s3/sdkconfig`); the ESP-IDF default is 16 KB. Code
  that runs from flash (PHP, drawing) is faster; it takes 16 KB of internal RAM.
- **ESP32-S3 main task stack 8 KB** (`chips/esp32s3/sdkconfig`; 32 KB elsewhere, `host/sdkconfig.defaults`). The
  main task only boots, mounts and unmounts the storage, restarts and sleeps (PHP has its own task); it uses about
  1 KB of its stack (measured on the board, also after a PHP script with deep recursion, PCRE, JSON and sorting, and
  before a deep sleep). The 24 KB go back to internal RAM, which Wi-Fi needs next to a large display.
- **SDK releases are versioned archives** listed in a release index with their sha256. A project locks the
  version it uses in `phpbm.lock`.

### Added
- **Pin names of the board.** A board file can name its GPIOs in a top-level `pins` object (`"A3": 4`, several names
  may share a GPIO), and a block value equal to a pin name is that pin's GPIO (`"sda": "A4"`); `board-overrides`
  cannot change `pins` ([Board files](docs/boards/board-files.md#pin-names)). Every pin must be a GPIO number of
  the chip (0-48 on the ESP32-S3, 0-54 on the ESP32-P4), checked before anything is written. The build writes the
  pins into `phpbm_board.c` (`phpbm_board_pins`, bm-common unchanged), and the firmware registers
  `final class Baremetal\Board\Pin` with one int constant per pin at module startup, once, as a persistent internal
  class (it always exists, empty when the board has no `pins`): `Pin::A3 === 4`. The new SDK option
  `board-constants` (`class`, default, or `global`) also registers the names as global constants (`A3`); with
  `global`, a name that is already a constant of PHP (of the PHP running the build, plus `TRUE`, `FALSE`, `NULL`) or
  of an installed package's IDE stubs is a build error, and a name that still exists at startup is skipped with a
  warning in the log. A pin named `CLASS` is refused. Needs php-baremetal/spec 1.5.
- `sdk.json` lists `php-baremetal/boards-arduino` (Arduino Nano ESP32, with its pin names) in `board-packages`.
- Board `php-baremetal-s3-discover` (php-baremetal S3-DISCOVER, coming soon): ESP32-S3R8, 16 MB flash, USB serial
  console, Wi-Fi, microSD in SPI mode, I2C bus with a QMI8658A IMU and an MMC5603NJ compass, WS2812 on GPIO 21. Its catalog entry
  links a picture and the datasheet poster (`assets/boards/`) and sets the new key `"coming-soon": true`, which
  keeps it out of the `phpbm init` board list; the catalog also takes a new key `poster`
  ([Board catalog](docs/boards/board-files.md#board-catalog)).
- PHP 8.6 (8.6.0): the 8.5 patches ported, the same thirteen optional extensions (`date` adds `Time\Duration`),
  and the new `Io\Poll` API on ESP-IDF's `poll()`. Without `php-date`, `Io\Poll\Context::wait()` takes only a
  `null` timeout; as on 8.5, the WHATWG URL parser is not built (`Uri\WhatWg\Url`, `UrlBuilder::build()` and
  `url_percent_encode()` throw). `phpbm init` writes the newest release, now 8.6.0.
- Board `waveshare-esp32-s3-touch-lcd-5b` (Waveshare ESP32-S3-Touch-LCD-5B): 1024 x 600 RGB panel at a pixel
  clock of 26 MHz (29.8 Hz; a margin under 26.67 MHz, the highest measured that loses no bounce refill on this
  board) with two framebuffers and 6 bounce rows, QIO flash, Wi-Fi buffers in PSRAM, GT911 touch, CH422G expander;
  block types `rgb`, `gt911`, `ch422g`, and pins of an expander named in other blocks.
- Board key `flash-mode: qio` (ESP32-S3): the flash read with QIO commands (`chips/esp32s3/sdkconfig.flash-qio`).
- Board `waveshare-esp32-p4-wifi6-touch-lcd-5` (Waveshare ESP32-P4-WIFI6-Touch-LCD-5): 720 x 1280 MIPI-DSI panel
  (block type `dsi`, driven by `esp32-display-dsi`) turned to 1280 x 720 with two framebuffers, GT911 touch, Wi-Fi
  through the ESP32-C6, microSD, QIO flash, revision v3. The GT911's INT line is not wired on this board, so the
  `touch` block has no `int` and touch-gt911 reads the chip every 10 ms (`poll-ms`); its points are turned
  with the display (`swap-xy` and `mirror-y` for `rotation` 90).
- `flash-mode: qio` on the ESP32-P4 (`chips/esp32p4/sdkconfig.flash-qio`).
- Display type `dsi` in the framebuffer reserve: its framebuffers are kept out of PHP's pool like those of `rgb`; with
  `rotation` 90, 180 or 270 one more, for the drawing surface's own framebuffer (5400 KB on the Waveshare
  ESP32-P4-WIFI6-Touch-LCD-5).
- ESP32-P4: the bootloader logs warnings and errors only (`chips/esp32p4/sdkconfig`), so that it fits before the
  partition table at 0x8000 with a 32 MB flash on v3 silicon.
- Board key `network.buffers` for the chip's own Wi-Fi (`type: wifi`): `internal` (default) or `psram`, which adds
  `host/sdkconfig/wifi-buffers-psram` (`CONFIG_SPIRAM_TRY_ALLOCATE_WIFI_LWIP=y`: Wi-Fi and lwIP allocate in PSRAM
  first; `CONFIG_MBEDTLS_EXTERNAL_MEM_ALLOC=y`: mbedTLS, and with it the WPA3-SAE handshake, allocates in PSRAM).
  Refused on other network types and on boards without PSRAM. Waveshare ESP32-S3-Touch-LCD-5B sets it, so Wi-Fi can
  start next to its RGB panel and Slate.
- Board key `network.static-buffers` (with `network.buffers: "psram"`, type `wifi` only): N from 2 to 25 sets the
  static Wi-Fi RX buffers, the AMPDU RX BA window and the static TX buffers
  (`CONFIG_ESP_WIFI_STATIC_RX_BUFFER_NUM`, `CONFIG_ESP_WIFI_RX_BA_WIN`, `CONFIG_ESP_WIFI_STATIC_TX_BUFFER_NUM`)
  through a fragment generated in the build directory; changing N rebuilds `sdkconfig`. Waveshare
  ESP32-S3-Touch-LCD-5B sets 4: with ESP-IDF's 16/16/16, `esp_wifi_init()` failed with `ESP_ERR_NO_MEM` next to
  Slate on the double-buffered panel. Fewer buffers mean lower throughput for large transfers.
- The framebuffers of a board's display block driven by an installed package (type `rgb`: `width` x `height` x 2 x
  `frames`) are reserved by the build on top of `firmware-reserve`, outside its quarter-of-PSRAM cap
  (`PHPBM_DISPLAY_FB_KB`), so a project needs no option for a large panel.
- Project assets (`extra.baremetal.assets`, needs php-baremetal/spec 1.3): the flash image holds the sources and the `embedded` assets (`BuildPlan::imageDir()`, sized with them); with `microsd` assets the firmware mounts the microSD card also when the code is in flash (`PHPBM_ASSETS_MICROSD`), and `phpbm flash` and `phpbm sync` copy the assets to the card. The sync server changes only the folders the host manages (`/sdcard/app` with the code on the card, and each `microsd` asset folder) and never the rest of the card. Its protocol is now version 2: a board flashed by an earlier preview must be flashed again before syncing. A phpbm that still vendors spec 1.2 (phpbm 0.2.x) keeps building projects without assets: the driver then reads no assets and puts the sources in the image.
- Package options: native packages declare options; the build defines PHPBM_OPTION_<NAME> for each.
- `board-overrides` can add a block the board does not have, written whole with its `type` (needs php-baremetal/spec 1.3.1): for example a `spi` microSD slot on `generic-esp32s3-octal`. Before writing anything the build checks the SDK's blocks (`microsd`, `network`) as they are after the overrides, used or not: a type the chip supports, every pin the type needs (`spi` microSD: `cs`, `sclk`, `mosi`, `miso`; `w5500`: `cs`, `int`, `rst`, `sclk`, `mosi`, `miso`; `emac`: `rst`) and each pin a GPIO number of the chip (0-48 on the ESP32-S3, 0-54 on the ESP32-P4; `-1` for none on `rst` and the `sdio` `pwr_en`). A missing pin is an error that names it, not a C compile error. A block key whose variable the build sets itself (`network.component`, or `extensions` of an added `php` block) is an error too.
- Option type `set` (a list of allowed values): the build defines PHPBM_OPTION_<NAME>_<VALUE> as 1 or 0 for each allowed value and PHPBM_OPTION_<NAME> as the comma-separated chosen values (needs php-baremetal/spec 1.2).
- init-loop runtime: calls bm_events_service() of ext-events (when the firmware has it) after each loop(), so object releases run, the slots of undelivered events go back and event warnings are printed. Docs: events, web and I2C pages describe the event slots of ext-events 1.0.3 instead of a 32-message queue.
- Fonts: the build compiles the fonts phpbm resolves (BuildPlan::$fonts) into the display_fonts[] table read by ext-display; packages may declare extra.baremetal.fonts.
- Board blocks display, i2c, touch, expander and led (Waveshare ESP32-S3-Touch-LCD-1.85B, Waveshare ESP32-S3-Zero), readable by packages through bm_board.h; the build writes them into phpbm_board.c.
- Runtimes `init-loop`, `event-driven` and `web-server`; storages `embedded` and `microsd`, the latter
  synced over the serial port with `phpbm sync`.
- PHP 8.3, 8.4 and 8.5, each with the optional extensions bcmath, ctype, date, filter, mbstring, opcache,
  openssl (partial API), pdo, pdo_sqlite, session, sqlite3, tokenizer and zlib.
- Options `network`, `on-error`, `restart-delay`, `firmware-reserve`, `optimization`, `log` (the firmware's
  log level), `power-save` (automatic light sleep) and `request-timeout`, plus `loop-interval`
  (`init-loop`) and `init` / `port` (`web-server`).
- Networks: on-chip Wi-Fi, Ethernet through the ESP32-P4 EMAC or a W5500, and Wi-Fi through an ESP32-C6
  companion.
- Boards: Espressif ESP32-S3-DevKitC-1 (N8R2, N8R8, N16R8), Freenove ESP32-S3 WROOM Board Lite
  (N16R8, N16R16), Waveshare ESP32-S3-Zero (and N8R8), ESP32-S3-ETH, ESP32-S3-Touch-LCD-1.85B, ESP32-P4-ETH,
  ESP32-P4-Pico, ESP32-P4-WIFI6, and generic ESP32-S3 (quad or octal PSRAM) and ESP32-P4 boards.
- `phpbm_runtime_request_scoped()` in the host API (`phpbm.h`), implemented by each runtime: true in
  `web-server`. esp32-events-hal 1.0.2 passes it to ext-events, which refuses event sources there.
- Packages may declare extra.baremetal.board-blocks (used by phpbm to offer board features).
- [Documentation](docs/overview.md) for users and for SDK development.

### Removed
- The PHP patch `closure-runtime-cache-arena` (every minor). It allocated a closure's non-shared run-time cache from
  the request arena to avoid a heap corruption seen when PHP ran on `malloc` (`USE_ZEND_ALLOC=0`). With the Zend
  memory manager on the PSRAM pool (and its zeroed chunk headers) it is not needed, and in an app whose request
  never ends it leaked the cache of every `bind`/`bindTo` to another scope and of every first-class callable of a
  method not yet called: about 49 bytes a round in a closure stress on the Waveshare ESP32-P4-WIFI6-Touch-LCD-5,
  until the memory limit after about 470 000 rounds. Without it, 9 000 000 rounds (10 minutes) ran with
  `memory_get_usage()` constant, and a host debug build (Zend's debug allocator) ran 3 000 000 rounds without an
  error or a leak.
- The TOML project configuration and the `phpflash` tool: use phpbm.
- The extensions built into the firmware: each one is now its own package.

---

The 1.x firmware (v1.5.0 and older) has its own changelog:
[legacy CHANGELOG](https://github.com/php-baremetal/php-esp32-sdk/blob/v1.5.0/CHANGELOG.md).
