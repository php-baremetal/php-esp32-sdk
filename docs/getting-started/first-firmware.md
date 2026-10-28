---
eyebrow: 'SDK · Overview'
lede:    'Blink an LED in an SOS pattern: install the SDK, create the project, build, flash and watch the serial console.'
see_also:
  - { href: '../boards/supported-boards.md', meta: 'Boards' }
  - { href: '../reference/troubleshooting.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/getting-started.md', meta: 'CLI', label: 'phpbm: Getting started' }
prev: { label: 'Overview', href: '../overview.md' }
next: { label: 'Supported boards', href: '../boards/supported-boards.md' }
---

# Your first firmware

This page follows the `blink-sos` example (`04-gpio-led/blink-sos` in the examples). It needs a board from the
[supported list](../boards/supported-boards.md), an LED with a resistor of about 330 ohm between GPIO2 and GND,
and phpbm with the conductor installed ([phpbm docs](https://github.com/php-baremetal/phpbm/blob/master/docs/overview.md)).

<!-- @callout variant="warning" title="Preview" -->
SDK 2.0 is available only as previews (`2.0.0-alpha.N`); phpbm reads them by default. See the
[overview](../overview.md).
<!-- @endcallout -->

## Install the SDK

<!-- @code-block language="Bash" label="once per machine" -->
```bash
phpbm sdk:install esp32
```
<!-- @endcode-block -->

phpbm downloads the newest `esp32` release, checks its sha256 and unpacks it into `~/.phpbm/sdks/esp32/<version>`.

## Choose the board

Connect the board and run `phpbm discover`. For each serial port it prints the chip and the boards of the SDK
built on that chip.

<!-- @code-block language="Text" label="phpbm discover" -->
```text
/dev/ttyACM0  USB JTAG/serial debug unit
  chip    ESP32-S3 (revision v0.2)
  flash   8MB
  mac     xx:xx:xx:xx:xx:xx
  radio   WiFi, BLE
  sdk     esp32 2.0.0
  board   waveshare-esp32-s3-eth  Waveshare ESP32-S3-ETH
  board   waveshare-esp32-s3-zero  Waveshare ESP32-S3-Zero
  …       one line per ESP32-S3 board of the SDK
```
<!-- @endcode-block -->

The chip does not tell the board apart: pick the id whose name and flash size match the board in your hand.
The example uses `waveshare-esp32-s3-zero-n8r8`. If your board is not listed, use the matching generic board
(see [Supported boards](../boards/supported-boards.md)).

## Create the project

<!-- @code-block language="Bash" label="new project" -->
```bash
phpbm init blink --board=waveshare-esp32-s3-zero-n8r8 --runtime=init-loop --storage=embedded
cd blink
composer require php-baremetal/ext-sys php-baremetal/ext-gpio
phpbm toolchain:install
phpbm flash
phpbm monitor
```
<!-- @endcode-block -->

- `phpbm init` writes `composer.json`, `project-src/index.php` and `.gitignore`.
- `composer require` runs the conductor, which installs the packages and writes `.phpbm/manifest.json`.
- `phpbm toolchain:install` installs ESP-IDF v6.1 once per machine (about 6 GB).
- `phpbm flash` builds, checks the chip on the port and flashes. The first build compiles PHP and takes a while.
- `phpbm monitor` opens the serial console; leave it with Ctrl + ].

## composer.json

<!-- @code-block language="JSON" label="composer.json" -->
```json
{
  "name": "php-baremetal-examples/blink-sos",
  "type": "project",
  "license": "proprietary",
  "description": "Blink an external LED in an SOS pattern via GPIO.",
  "repositories": [
    {
      "type": "composer",
      "url": "https://repository.php-baremetal.com",
      "canonical": true
    }
  ],
  "require": {
    "php-baremetal/conductor": "^1.0",
    "php-baremetal/ext-sys": "^1.0",
    "php-baremetal/ext-gpio": "^1.0"
  },
  "config": {
    "allow-plugins": {
      "php-baremetal/conductor": true
    },
    "platform": {
      "php": "8.5.11"
    }
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-s3-zero-n8r8",
      "runtime": "init-loop",
      "storage": "embedded"
    }
  }
}
```
<!-- @endcode-block -->

`extra.baremetal` is the SDK's input: `sdk` (name and constraint), `board` (a file in `boards/`), `runtime` and
`storage`. The full format is in the
[phpbm project guide](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md).

## project-src/index.php

<!-- @code-block language="PHP" label="project-src/index.php" -->
```php
<?php
// Blinks "SOS" in Morse code on an LED, forever.
// LED + ~330 ohm resistor between GPIO2 and GND.

define('LED', 2);
const UNIT = 200;   // milliseconds; a dot is one unit, a dash is three

function pulse(int $units): void {
    gpio_write(LED, 1);
    delay(UNIT * $units);
    gpio_write(LED, 0);
    delay(UNIT);            // gap between symbols
}

function setup(): void {
    gpio_mode(LED, GPIO_OUTPUT);
    echo "blinking SOS on GPIO " . LED . "\n";
}

function loop(int $tick): void {
    foreach ([1, 1, 1, 3, 3, 3, 1, 1, 1] as $units) {   // S O S
        pulse($units);
    }
    delay(UNIT * 6);        // longer gap before repeating
}
```
<!-- @endcode-block -->

With the `init-loop` runtime the script runs once, then `setup()` once and `loop($tick)` forever
([init-loop](../runtime/init-loop.md)). `gpio_mode()` and `gpio_write()` come from `ext-gpio`, `delay()` from
`ext-sys`.

## What the console shows

The example does not record its console output. The firmware prints two lines at start, then your script's
output. The first has the form below, with the project name and version, the PHP version, the board name,
the ESP-IDF version and the memory limit:

<!-- @code-block language="Text" label="serial console, form of the output" -->
```text
<project> <version>: PHP 8.5.11 on Waveshare ESP32-S3-Zero (N8R8) (esp32s3), ESP-IDF v6.1, memory_limit … KB, core 1
phpbm: PSRAM free after start … KB (largest … KB)
blinking SOS on GPIO 2
```
<!-- @endcode-block -->

The LED then repeats three short, three long, three short flashes. Edit `project-src/index.php` and run
`phpbm flash` again: phpbm rebuilds what changed.

<!-- @callout variant="tip" title="The board does not answer" -->
Hold BOOT while you press RESET (or plug the cable in while holding BOOT), release, and flash again: the chip
then waits in download mode. More in [Troubleshooting](../reference/troubleshooting.md).
<!-- @endcallout -->
