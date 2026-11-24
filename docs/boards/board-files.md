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
| `flash-mode` | no | Flash bus and mode. `quad` is the default (quad bus, DIO commands); `qio` (quad bus, QIO commands: faster reads) is accepted on the ESP32-S3 and the ESP32-P4, `octal` on the ESP32-S3. |
| `revision` | no | Silicon revision family the firmware targets. ESP32-P4 only: `v1` (default) or `v3`. |
| `console` | no | `uart` (default) or `usb-jtag`. Selects `host/sdkconfig/console-<value>`. |
| any other key | no | A peripheral block (below). |

Every other key of the file must be an object with a non-empty `type`.

### PSRAM, flash mode and revision per chip

| Chip | `psram` | `flash-mode` | `revision` |
| --- | --- | --- | --- |
| `esp32s3` | `quad`, `octal` | `quad`, `qio`, `octal` | none: a `revision` is an error |
| `esp32p4` | `hex` | `quad`, `qio` | `v1`, `v3` |

- `psram`: the PSRAM wiring of the module (for example the N16R8 DevKitC has octal, the N8R2 quad).
- `flash-mode`: `qio` reads the flash with four data lines for commands and addresses too
  (`chips/esp32s3/sdkconfig.flash-qio`, `chips/esp32p4/sdkconfig.flash-qio`); the flash chip must support it (the boot log then says `SPI Mode : QIO`; on the ESP32-P4, whose bootloader logs only warnings, the application's `spi_flash: flash io: qio`).
  `octal` is for modules with octal flash (`chips/esp32s3/sdkconfig.flash-octal`).
- `revision`: `v1` builds for P4 chips older than revision 3, `v3` for revision v3.1 and later. A chip of the
  other family refuses to boot the image. `phpbm discover` prints the revision of the connected chip.
- `console`: `usb-jtag` uses the chip's built-in USB serial, `uart` the UART pins.

<!-- @callout variant="note" title="Values are checked, not guessed" -->
A `revision` or a `flash-mode` the chip has no `sdkconfig` fragment for is rejected at build time, and a `psram`
the chip does not list is rejected when the SDK is loaded. Messages are listed at the end of this page.
<!-- @endcallout -->

## Peripheral blocks

A block is named after a peripheral, and its `type` says which kind the board has. Two kinds of block exist:

- **Blocks the SDK implements**, `microsd` and `network`: the firmware has its own code for each type, so the type
  must be one the chip supports. `chips/<chip>/chip.json` lists them, and any other type is an error.
- **Blocks read by packages**, such as `display`, `i2c`, `touch`, `expander` and `led`: the type is the name of a driver, checked by
  the driver's `onboard()` when the script runs. The chip does not list them, so a new driver needs no SDK change.
  Two blocks of the same kind take different names, for example `led` and `led-2`.

| Chip | `microsd` types | `network` types |
| --- | --- | --- |
| `esp32s3` | `spi` | `w5500`, `wifi` |
| `esp32p4` | `sdio` | `emac`, `wifi-c6` |

The `microsd` and `network` blocks also make runtimes and storages available: `storage: microsd` needs a
`microsd` block, `runtime: web-server` needs a `network` block.

A device wired to the board by hand is not a board block: build it with its constructor and explicit pins, for
example `new Ws2812(pin: 5, count: 30)`.

All keys of a block other than `type` reach the build as `PHPBM_<BLOCK>_<KEY>` (upper case). A value must be a
number, a string or a boolean.

Packages read a block through `bm_board.h` (bm-common): the build writes every block into `phpbm_board.c`, and a
package calls `bm_board_type()`, `bm_board_int()` or `bm_board_str()` with the block and key. Values must be
integers (a boolean becomes 1 or 0) or strings there. Drivers offer `onboard()` to build from the board's block.

A pin key holds a GPIO number, or a string naming a pin of the board's IO expander (`"exio2"`, see [expander](#expander)); drivers resolve it through `bm_pin.h` (bm-common). Pins of the `rgb` and `gt911` rows below can be given this way.

### microsd

| Type | Keys | Notes |
| --- | --- | --- |
| `spi` | `cs`, `sclk`, `mosi`, `miso` | SPI card on explicit pins. |
| `sdio` | `clk`, `cmd`, `d0`, `d1`, `d2`, `d3`, `pwr_en` | SDMMC slot 0, 4-bit. Defaults when absent: clk 43, cmd 44, d0 39, d1 40, d2 41, d3 42. `pwr_en` is the card power switch; absent or `-1` means none. |

Every pin of a `spi` slot is required. Every pin given must be a GPIO number of the chip: an integer from 0 to 48
on the ESP32-S3, from 0 to 54 on the ESP32-P4 (`pwr_en` may also be `-1`). The build checks this before it compiles
anything, whether the project uses the card or not.

### network

| Type | Keys | Notes |
| --- | --- | --- |
| `wifi` | `buffers`, `static-buffers` | The chip's own radio. The script connects with `php-baremetal/ext-wifi`. `buffers` is where Wi-Fi and lwIP take their memory: `internal` (default) or `psram` (see below). `static-buffers` (optional, with `buffers: "psram"`) is the number of static Wi-Fi RX and TX buffers and the AMPDU RX window, from 2 to 25 (see below). |
| `wifi-c6` | none | Wi-Fi through a companion ESP32-C6. Also connected with `ext-wifi`. |
| `w5500` | `cs`, `int`, `rst`, `sclk`, `mosi`, `miso` | SPI Ethernet controller; the pins are those of its SPI wiring. |
| `emac` | `rst` | The P4's on-chip Ethernet MAC; `rst` is the PHY reset pin. |

Every key listed for `w5500` and `emac` is required, and each must be a GPIO number of the chip (0-48 on the
ESP32-S3, 0-54 on the ESP32-P4); `rst` may also be `-1` when the reset pin is not wired. The build checks this
before it compiles anything, whether the network is on or not.

`buffers: "psram"` adds `host/sdkconfig/wifi-buffers-psram` (`CONFIG_SPIRAM_TRY_ALLOCATE_WIFI_LWIP=y`): Wi-Fi and
lwIP allocate in PSRAM first and use internal RAM only when PSRAM has no room; mbedTLS (the WPA3-SAE handshake, TLS)
allocates in PSRAM too (`CONFIG_MBEDTLS_EXTERNAL_MEM_ALLOC=y`). Set it on a board whose display
drivers leave too little internal RAM to start Wi-Fi: Waveshare ESP32-S3-Touch-LCD-5B has it, because its RGB panel
and Slate leave about 35 KB of internal RAM and starting Wi-Fi needs about 42-45 KB. The key is accepted only on a
`wifi` block (not on `wifi-c6`, `w5500` or `emac`), and `psram` only on a board with `psram`. The build checks it
whether the network is on or not. The trade-off is in [Wi-Fi](../modules/wifi.md#memory).

`static-buffers: N` writes a generated fragment, `wifi-static-buffers.sdkconfig` in the build directory, that sets
`CONFIG_ESP_WIFI_STATIC_RX_BUFFER_NUM`, `CONFIG_ESP_WIFI_RX_BA_WIN` and `CONFIG_ESP_WIFI_STATIC_TX_BUFFER_NUM` to
N. It comes after `wifi-buffers-psram`. With `buffers: "psram"`, ESP-IDF raises these to 16, 16 and 16, and the
static buffers stay in internal DMA RAM, so a board that is short of internal RAM can lower them. Without the key,
ESP-IDF keeps its own values. N is a whole number from 2 to 25: the ESP-IDF v6.1 ranges of the three options are
2-25, 2-32 and 1-64 (wider on Wi-Fi 6 chips), and one N for all three keeps the static RX buffers at least as many
as the BA window, as the Kconfig help asks. The key needs `buffers: "psram"`, because ESP-IDF has static TX buffers
only in that mode (otherwise the TX option does not exist). Like `buffers`, it is accepted only on a `wifi` block,
and the build checks it whether the network is on or not. Changing N rebuilds `sdkconfig`. Waveshare
ESP32-S3-Touch-LCD-5B sets `4`; the trade-off is in [Wi-Fi](../modules/wifi.md#memory).

[Network](../runtime/network.md) describes how each type is brought up.

### display

| Type | Keys | Notes |
| --- | --- | --- |
| `st77916` | `sclk`, `d0`, `d1`, `d2`, `d3`, `cs`, `rst`, `bl`, `width`, `height`, `te` | QSPI panel: clock, four data lines, chip select, reset, backlight, the size in pixels and the panel's TE (tearing effect) pin, which pulses once per refresh; `te` is optional; `onVsync()` and `St77916::onboard(vsync: true)` need it. Used by Waveshare ESP32-S3-Touch-LCD-1.85B (TE on GPIO 18). |
| `rgb` | `width`, `height`, `pclk`, `de`, `hsync`, `vsync`, `data`, `pclk-hz`, `pclk-neg`, `hsync-pulse`, `hsync-back`, `hsync-front`, `vsync-pulse`, `vsync-back`, `vsync-front`, `bounce-rows`, `frames`, `bl`, `rst`, `mirror-x`, `mirror-y`, `de-idle-high`, `hsync-idle-low`, `vsync-idle-low` | Parallel RGB panel (16-bit RGB565) on the chip's LCD peripheral, driven by `esp32-display-rgb`: the clock, sync and data pins (`data`: the 16 GPIOs of DATA0 to DATA15 as one string, B first), the pixel clock and the porches, the bounce buffer rows (`0`: none; by default the largest divisor of `height` up to 6), `frames` (1 or 2: two framebuffers for renderers such as Slate; 2 needs `bounce-rows` above 0), and `bl` / `rst`, which may name a pin of the board's `expander` (`"exio2"`). The framebuffers (`width` x `height` x 2 bytes each) are reserved by the build when `esp32-display-rgb` is installed, on top of `firmware-reserve`. Used by Waveshare ESP32-S3-Touch-LCD-5B (1024 x 600, pixel clock 26 MHz, about 29.8 Hz, two frames, bounce 6, QIO flash). |
| `dsi` | `width`, `height`, `lanes`, `lane-mbps`, `dpi-hz`, `hsync-pulse`, `hsync-back`, `hsync-front`, `vsync-pulse`, `vsync-back`, `vsync-front`, `init`, `phy-ldo`, `phy-mv`, `rst`, `rst-high`, `bl`, `bl-pwm`, `rotation`, `frames` | MIPI-DSI panel in video (DPI) mode on the ESP32-P4, driven by `esp32-display-dsi`: `width` and `height` are the panel's own (portrait 720 x 1280), `rotation` (0, 90, 180, 270) turns what drawings and renderers see; `init` is the panel's command sequence as text (see the package docs); `phy-ldo` / `phy-mv` power the MIPI PHY from an on-chip LDO channel; `bl-pwm` 1 dims the backlight by PWM. The framebuffers (`width` x `height` x 2 bytes each, one more with `rotation` 90, 180 or 270 for the drawing surface's own, unless ext-display's `display-surface` option is `false`) are reserved by the build when `esp32-display-dsi` is installed. Used by Waveshare ESP32-P4-WIFI6-Touch-LCD-5 (720 x 1280 HX8394, 2 lanes at 700 Mbps, 58 MHz, about 55 Hz, turned to 1280 x 720, two frames). |

### i2c

| Type | Keys | Notes |
| --- | --- | --- |
| `i2c` | `sda`, `scl` | The board's on-board I2C bus (touch controller, sensors). Used by Waveshare ESP32-S3-Touch-LCD-1.85B and ESP32-S3-Touch-LCD-5B (SDA 8, SCL 9), and Waveshare ESP32-P4-WIFI6-Touch-LCD-5 (SDA 7, SCL 8). |

### touch

| Type | Keys | Notes |
| --- | --- | --- |
| `cst816s` | `width`, `height`, `int`, `rst`, `address`, `mirror-x`, `mirror-y`, `swap-xy`, `double-tap` | CST816S capacitive touch controller on the board's `i2c` bus: the screen size in pixels, the INT and reset GPIOs and the orientation; `address` defaults to `0x15`, `double-tap` (bool, default `true`) turns the chip's double tap detection on or off (on: a single tap is reported about 260 ms after the release), and only `width` and `height` are required. `events()` needs `int`. Used by Waveshare ESP32-S3-Touch-LCD-1.85B (INT on GPIO 4, reset on GPIO 1, 360 x 360). See [Touch input](../modules/touch.md). |
| `gt911` | `width`, `height`, `int`, `rst`, `address`, `mirror-x`, `mirror-y`, `swap-xy`, `poll-ms` | GT911 capacitive touch controller (up to 5 fingers) on the board's `i2c` bus; `width` and `height` in the chip's axes (before `swap-xy`; absent: the chip's resolution); `rst` may be an expander pin; `address` 0x5D (default) or 0x14. With `int` on a GPIO, `events()` follows the INT line; without `int` (a line that is not wired) or with `int` on an expander pin, `events()` reads the chip every `poll-ms` milliseconds (5 to 100, default 10). Used by Waveshare ESP32-S3-Touch-LCD-5B (INT on GPIO 4, reset on the expander's EXIO1) and Waveshare ESP32-P4-WIFI6-Touch-LCD-5 (INT not wired: polled every 10 ms; reset on GPIO 23; turned with `swap-xy` and `mirror-y` for the display's `rotation` 90, `swap-xy` and `mirror-x` for 270). |

### expander

| Type | Keys | Notes |
| --- | --- | --- |
| `ch422g` | `inputs` | CH422G I/O expander on the board's `i2c` bus, driven by `io-ch422g`. Its pins are named `exio0` to `exio7` and `oc0` to `oc3` in other blocks. `inputs` is a comma-separated string of the EXIO pins wired to inputs (e.g. `"exio0,exio5"`; they stay low and cannot be set or read). Used by Waveshare ESP32-S3-Touch-LCD-5B (backlight on `exio2`, touch reset on `exio1`). |

### led

| Type | Keys | Notes |
| --- | --- | --- |
| `ws2812` | `pin`, `order` | One addressable LED on `pin`; `order` is the colour order (`rgb`, `grb`). Used by Waveshare ESP32-S3-Zero (GPIO 48, `rgb`), Espressif ESP32-S3-DevKitC-1 and Freenove ESP32-S3 WROOM Board Lite (GPIO 48, `grb`). |
| `sk6812` | `pin`, `order` | Same wiring as `ws2812`, for SK6812 LEDs. |

## Error messages

| Message | Cause |
| --- | --- |
| `boards/x.json: 'microsd' must be an object` | A key other than the board keys holds something that is not an object. |
| `boards/x.json: 'microsd.type' must be a non-empty string` | A peripheral block without a `type`. |
| `boards/x.json: chip 'abc' does not exist` | `chip` names no directory in `chips/`. |
| `boards/x.json: chip 'esp32s3' does not support psram 'hex'` | The `psram` is not in the chip's list. |
| `boards/x.json: chip 'esp32s3' does not support microsd 'sdio'` | A `microsd` or `network` type the chip does not list. |
| `board x: unsupported flash size '16' (use e.g. 4MB)` | `flash` is not a whole number followed by `MB`. |
| `board x: chip 'esp32s3' has no revision 'v1'` | `revision` with no matching fragment for the chip. |
| `board x: chip 'esp32p4' does not support flash-mode 'octal'` | `flash-mode` with no matching fragment for the chip. |
| `board x: display.frames must be 1 or 2` | `frames` of a display block that an installed driver keeps framebuffers for is not 1 or 2. |
| `board x: display.width and display.height must be positive integers` | The same block without a valid size, which the build needs to reserve its framebuffers. |
| `board x: chip 'esp32s3' does not support microsd 'sdio' (it supports spi)` | A `microsd` or `network` block added by [board overrides](./board-overrides.md) with a type the chip does not list. |
| `board x: microsd.cs is missing (a 'spi' microsd needs cs, sclk, mosi, miso)` | A pin the block's type needs is not there (here a `spi` slot without `cs`). |
| `board x: microsd.clk must be a GPIO number from 0 to 54` | A pin that is not an integer in the chip's range. |
| `board x: network.rst must be a GPIO number from 0 to 54, or -1 for none` | The same, for a pin that may also be `-1`. |
| `board x: network.buffers must be 'internal' or 'psram'` | `buffers` holds another value, or not a string. |
| `board x: network.buffers applies only to network type 'wifi' (the chip's own radio), not to 'w5500'` | `buffers` on a `wifi-c6`, `w5500` or `emac` block. |
| `board x: network.buffers 'psram' needs PSRAM, and the board has none` | `buffers: "psram"` on a board without a `psram` key. |
| `board x: network.static-buffers must be a whole number from 2 to 25` | `static-buffers` out of range, or not an integer (a string such as `"4"` too). |
| `board x: network.static-buffers applies only to network type 'wifi' (the chip's own radio), not to 'w5500'` | `static-buffers` on a `wifi-c6`, `w5500` or `emac` block. |
| `board x: network.static-buffers needs network.buffers 'psram' (ESP-IDF has static Wi-Fi TX buffers only in that mode)` | `static-buffers` without `buffers: "psram"`. |
| `board x: network.cs must be a number or a string` | A block value that is an array or an object. |
| `board x: led.gain must be an integer or a string` | A block value that is a decimal number: `phpbm_board.c` holds integers and strings only. |

To change a pin in a project without editing the board file, use [board overrides](./board-overrides.md).
