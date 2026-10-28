# Changelog

## [2.0.0] - Unreleased (previews: 2.0.0-alpha.N)

2.0 is a rewrite: the firmware is a Composer project built by [phpbm](https://github.com/php-baremetal/phpbm),
and this repository is the ESP32 SDK that phpbm downloads and drives. Until 2.0.0 is released, every push to
the `sdk` branch publishes a preview `2.0.0-alpha.N` on the `sdk-preview` pre-release, which phpbm installs by
default. Previews are not ready for use: anything can still change.

### Changed
- **A project is a `composer.json`.** `extra.baremetal` names the SDK (`"sdk": "esp32@^2.0"`), the `board`,
  the `runtime` and the `storage`, plus optional `src`, `entry`, `env`, `options` and `board-overrides`.
  `config.platform.php` is the PHP version of the firmware.
- **Features are packages.** Extensions (`ext-gpio`, `ext-i2c`, `ext-wifi`, …), drivers and PHP extensions
  (`php-baremetal/php-<name>`) come from the [registry](https://repository.php-baremetal.com) with
  `composer require`. The `php-baremetal/conductor` plugin installs them and adds the ESP32 HAL each one
  needs.
- **Boards are JSON files** under `boards/`, selected by id (e.g. `waveshare-esp32-s3-eth`). `phpbm discover`
  lists the boards that match the connected chip.
- **ESP-IDF v6.1**, installed by `phpbm toolchain:install`.
- **SDK releases are versioned archives** listed in a release index with their sha256. A project locks the
  version it uses in `phpbm.lock`.

### Added
- Runtimes `init-loop`, `event-driven` and `web-server`; storages `embedded` and `microsd`, the latter
  synced over the serial port with `phpbm sync`.
- PHP 8.3, 8.4 and 8.5, each with the optional extensions bcmath, ctype, date, filter, mbstring, opcache,
  openssl (partial API), pdo, pdo_sqlite, session, sqlite3, tokenizer and zlib.
- Options `network`, `on-error`, `restart-delay`, `firmware-reserve`, `optimization`, `power-save` (automatic
  light sleep) and `request-timeout`, plus `loop-interval` (`init-loop`) and `init` / `port` (`web-server`).
- Networks: on-chip Wi-Fi, Ethernet through the ESP32-P4 EMAC or a W5500, and Wi-Fi through an ESP32-C6
  companion.
- Boards: Espressif ESP32-S3-DevKitC-1 (N8R2, N8R8, N16R8), Waveshare ESP32-S3-Zero (and N8R8),
  ESP32-S3-ETH, ESP32-S3-Touch-LCD-1.85B, ESP32-P4-ETH, ESP32-P4-Pico, ESP32-P4-WIFI6, and generic
  ESP32-S3 (quad or octal PSRAM) and ESP32-P4 boards.
- [Documentation](docs/overview.md) for users and for SDK development.

### Removed
- The TOML project configuration and the `phpflash` tool: use phpbm.
- The extensions built into the firmware: each one is now its own package.

---

The 1.x firmware (v1.5.0 and older) has its own changelog:
[legacy CHANGELOG](https://github.com/php-baremetal/php-esp32-sdk/blob/v1.5.0/CHANGELOG.md).
