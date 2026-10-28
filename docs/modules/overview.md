---
eyebrow: 'SDK · Modules'
lede:    'The features of a firmware are Composer packages. This page lists every package you can require for an ESP32 board, what it does, and where it is covered.'
see_also:
  - { href: './gpio.md', meta: 'Module' }
  - { href: './php-extensions.md', meta: 'Module' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/index.md', meta: 'phpbm', label: 'phpbm documentation' }
  - { href: 'https://repository.php-baremetal.com', meta: 'Registry', label: 'Package registry' }
prev: { label: 'Network', href: './../runtime/network.md' }
next: { label: 'GPIO', href: './gpio.md' }
---

# Available modules

A module is a Composer package. To use one, require it in your project; to remove it, remove it from
`composer.json`.

- `composer require php-baremetal/<package>` adds the package. Requiring a driver (for example
  `imu-qmi8658`) also brings the extension it needs (`ext-i2c`).
- The conductor, the Composer plugin that `phpbm init` puts in your project, installs the packages into
  `.phpbm/packages/` and adds the esp32 HAL (the hardware layer) of each extension by itself. You never require a
  HAL.
- A package only reaches the board when the firmware is rebuilt: run `phpbm flash`. Without the package, its
  functions and classes do not exist, so `function_exists()` and `class_exists()` tell you what a firmware has.
- Every package page on the [registry](https://repository.php-baremetal.com) has the full PHP API. The pages of
  this group cover what is specific to ESP32 boards.

How to create a project and flash it is in [Your first firmware](../getting-started/first-firmware.md).

## Packages

All packages are `php-baremetal/<name>`. The example folders are in the `new_examples` tree of the
SDK repository.

| Package | What it does | Page | Examples |
| --- | --- | --- | --- |
| [ext-gpio](https://repository.php-baremetal.com/packages/php-baremetal/ext-gpio) | Digital pins: `gpio_mode()`, `gpio_write()`, `gpio_read()` | [GPIO](./gpio.md) | `04-gpio-led/blink-sos`, `04-gpio-led/button-led`, `07-event-driven/gpio-interrupt` |
| [ext-led](https://repository.php-baremetal.com/packages/php-baremetal/ext-led) | `Baremetal\Led\Device`: pixel buffer, colours, `show()` | [LED strips](./led-strips.md) | `04-gpio-led/s3-rgb-show` |
| [led-ws2812](https://repository.php-baremetal.com/packages/php-baremetal/led-ws2812) | `Ws2812` class for WS2812 strips, rings and on-board LEDs; requires ext-led | [LED strips](./led-strips.md) | `04-gpio-led/s3-rgb-show` |
| [led-sk6812](https://repository.php-baremetal.com/packages/php-baremetal/led-sk6812) | `Sk6812` class for SK6812 RGBW LEDs; requires ext-led | [LED strips](./led-strips.md) | none |
| [ext-i2c](https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c) | I2C master: `Bus`, `Device`, scan, background sampling; requires ext-events | [I2C and sensors](./i2c-and-sensors.md) | `05-i2c-sensors/i2c-scan`, `05-i2c-sensors/i2c-core1` |
| [imu-qmi8658](https://repository.php-baremetal.com/packages/php-baremetal/imu-qmi8658) | `Qmi8658` accelerometer and gyroscope; requires ext-i2c | [I2C and sensors](./i2c-and-sensors.md) | `05-i2c-sensors/imu-events`, `05-i2c-sensors/imu-ws-stream` |
| [mag-mmc5603](https://repository.php-baremetal.com/packages/php-baremetal/mag-mmc5603) | `Mmc5603` magnetometer and compass heading; requires ext-i2c | [I2C and sensors](./i2c-and-sensors.md) | `05-i2c-sensors/mmc5603`, `05-i2c-sensors/compass` |
| [rtc-pcf85063](https://repository.php-baremetal.com/packages/php-baremetal/rtc-pcf85063) | `Pcf85063` real-time clock; requires ext-i2c | [I2C and sensors](./i2c-and-sensors.md) | `05-i2c-sensors/rtc-pcf85063`, `05-i2c-sensors/rtc-clock` |
| [ext-spi](https://repository.php-baremetal.com/packages/php-baremetal/ext-spi) | SPI master: `Bus` (up to four data lines) and raw `Device` | [SPI and displays](./spi-and-displays.md) | `06-display/display-hello` (through the driver) |
| [esp32-display-st77916](https://repository.php-baremetal.com/packages/php-baremetal/esp32-display-st77916) | `St77916` QSPI round display; requires ext-spi; ESP32 only | [SPI and displays](./spi-and-displays.md) | `06-display/display-hello` |
| [ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events) | Events, listeners, timers, pin and button watchers, `power_hold()`; required by the `event-driven` runtime | [Events and timers](./events-and-timers.md) | `07-event-driven/*` |
| [ext-wifi](https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi) | Scan, join a network, open an access point | [Wi-Fi](./wifi.md) | `08-network-web/wifi-connect`, `08-network-web/wifi-ap` |
| [ext-web](https://repository.php-baremetal.com/packages/php-baremetal/ext-web) | HTTP and WebSocket server for the `event-driven` runtime | [HTTP and the web](./web.md) | `08-network-web/sensor-webpage`, `07-event-driven/http-events` |
| [ext-store](https://repository.php-baremetal.com/packages/php-baremetal/ext-store) | Key-value store in flash that survives a reset | [Saving data](./saving-data.md) | `03-storage/store-demo` |
| [ext-mem](https://repository.php-baremetal.com/packages/php-baremetal/ext-mem) | Key-value store in RAM, shared by all requests of one boot; no HAL | [Saving data](./saving-data.md) | `08-network-web/web-init-mem` |
| [ext-sys](https://repository.php-baremetal.com/packages/php-baremetal/ext-sys) | `sys_*` functions: `delay()`, uptime, restart, sleep, chip identity, memory | [System and power](./system-and-power.md) | `04-gpio-led/blink-sos`, `10-power/power-bench` |
| [ext-hello](https://repository.php-baremetal.com/packages/php-baremetal/ext-hello) | `bm_hello()`, the smallest possible extension; no HAL | Package page only | `00-hello` |
| `php-bcmath`, `php-ctype`, `php-date`, `php-filter`, `php-mbstring`, `php-opcache`, `php-openssl`, `php-pdo`, `php-pdo_sqlite`, `php-session`, `php-sqlite3`, `php-tokenizer`, `php-zlib` | The matching standard PHP extensions, switched on in the firmware | [PHP extensions](./php-extensions.md) | `02-extensions/*` |

`php-pdo_sqlite` requires `php-pdo`. The PHP extension packages need no HAL.

<!-- @callout variant="note" title="Boards decide what works" -->
A package only does something when the board has the hardware: ext-wifi needs a board with a Wi-Fi radio, and the
pins in an example are those of the board named in its `composer.json`. See
[Supported boards](../boards/supported-boards.md).
<!-- @endcallout -->
