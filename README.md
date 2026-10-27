<div align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/logo-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset="assets/logo-light.svg">
    <img alt="php-esp32" src="assets/logo-light.svg" width="440">
  </picture>
</div>

<p align="center">
  <img alt="Status: preview, not ready for use" src="https://img.shields.io/badge/status-preview%20%E2%80%94%20not%20ready%20for%20use-DC2626?style=flat-square">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/github/license/php-baremetal/php-esp32-sdk?style=flat-square&color=475569"></a>
  <img alt="PHP 8.3 | 8.4 | 8.5" src="https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white">
  <img alt="ESP-IDF v6.1" src="https://img.shields.io/badge/ESP--IDF-v6.1-E7352C?style=flat-square&logo=espressif&logoColor=white">
  <img alt="Targets: ESP32-P4 | S3" src="https://img.shields.io/badge/targets-ESP32--P4%20%7C%20S3-475569?style=flat-square&logo=espressif&logoColor=white">
  <a href="https://github.com/php-baremetal/php-esp32-sdk/stargazers"><img alt="Stars" src="https://img.shields.io/github/stars/php-baremetal/php-esp32-sdk?style=flat-square&color=475569"></a>
</p>

# PHP on ESP32

> [!CAUTION]
> **PREVIEW — NOT READY FOR USE.**
>
> This branch is a preview of the 2.0 SDK. It is not released, not stable and not meant for real
> projects: anything (boards, options, the project format, the package APIs) can still change without
> notice, and a build can fail or misbehave on the board. Use it only to try things out and report
> problems. If you need something that works today, stay on the 1.x firmware (tag `v1.5.0`).

The ESP32 SDK of [php-baremetal](https://repository.php-baremetal.com): it runs the real, unmodified PHP
engine on ESP32-S3 and ESP32-P4 boards. You do not use this repository directly:
[phpbm](https://github.com/php-baremetal/phpbm) downloads a release of it, and your project picks it with
`"sdk": "esp32@^2.0"`.

> [!NOTE]
> 2.0 is a rewrite: the firmware is now a Composer project built by phpbm, and hardware features are
> packages from the registry. Until 2.0.0 is released, phpbm installs the previews built from the
> [`sdk`](https://github.com/php-baremetal/php-esp32-sdk/tree/sdk) branch (`2.0.0-alpha.N`). The 1.x
> firmware is in the `v1.5.0` and older tags.

## Quick start

```bash
composer global require php-baremetal/phpbm php-baremetal/conductor
phpbm sdk:install esp32
phpbm init blink && cd blink
composer require php-baremetal/ext-gpio php-baremetal/ext-sys
phpbm toolchain:install          # ESP-IDF v6.1, once per machine
phpbm flash && phpbm monitor
```

See the [phpbm documentation](https://github.com/php-baremetal/phpbm/blob/master/docs/overview.md) for the
project format, the options and every command.

## What the SDK provides

| | |
| --- | --- |
| PHP | 8.3, 8.4, 8.5 (built from the php.net release plus build-time patches) |
| Runtimes | `init-loop`, `event-driven`, `web-server` |
| Storage | `embedded` (in flash), `microsd` |
| Options | `network`, `on-error`, `restart-delay`, `firmware-reserve`, `optimization`, `power-save`, `request-timeout` |
| PHP extensions | bcmath, ctype, date, filter, mbstring, opcache, openssl, pdo, pdo_sqlite, session, sqlite3, tokenizer, zlib, each enabled by its `php-baremetal/php-<name>` package |

## Boards

| Board id | Board | Chip | Network |
| --- | --- | --- | --- |
| `espressif-esp32-s3-devkitc-1-n8r2` | Espressif ESP32-S3-DevKitC-1 (N8R2) | ESP32-S3 | Wi-Fi |
| `espressif-esp32-s3-devkitc-1-n8r8` | Espressif ESP32-S3-DevKitC-1 (N8R8) | ESP32-S3 | Wi-Fi |
| `espressif-esp32-s3-devkitc-1-n16r8` | Espressif ESP32-S3-DevKitC-1 (N16R8) | ESP32-S3 | Wi-Fi |
| `waveshare-esp32-s3-zero` | Waveshare ESP32-S3-Zero | ESP32-S3 | Wi-Fi |
| `waveshare-esp32-s3-zero-n8r8` | Waveshare ESP32-S3-Zero (N8R8) | ESP32-S3 | Wi-Fi |
| `waveshare-esp32-s3-eth` | Waveshare ESP32-S3-ETH | ESP32-S3 | Ethernet (W5500) |
| `waveshare-esp32-s3-touch-lcd-1.85b` | Waveshare ESP32-S3-Touch-LCD-1.85B | ESP32-S3 | Wi-Fi |
| `waveshare-esp32-p4-eth` | Waveshare ESP32-P4-ETH | ESP32-P4 | Ethernet |
| `waveshare-esp32-p4-pico` | Waveshare ESP32-P4-Pico | ESP32-P4 | — |
| `waveshare-esp32-p4-wifi6` | Waveshare ESP32-P4-WIFI6 | ESP32-P4 | Wi-Fi (ESP32-C6) |
| `generic-esp32s3-quad`, `generic-esp32s3-octal`, `generic-esp32p4` | Any ESP32-S3 (quad or octal PSRAM) or ESP32-P4 | | |

Every board needs external PSRAM. `phpbm discover` shows which boards match the connected chip.

## Contributing

Boards, PHP extensions and fixes are welcome: see [CONTRIBUTING.md](CONTRIBUTING.md), the
[AI usage policy](AI_USAGE.md) and the [security policy](SECURITY.md). Extensions, HALs and drivers live
in their own repositories, linked from the [registry](https://repository.php-baremetal.com).

## AI usage

AI is used as a tool here, on top of human engineering — the architecture, the design decisions and the
hardware bring-up are done by hand; AI mostly helps with the docs/website prose and with mechanical,
verifiable work. The full policy, and what's expected of contributions made with it, is in
**[AI_USAGE.md](AI_USAGE.md)**.

Not for you? No worries at all — nobody's forcing it on you, and a free, open-source project you
didn't spend a minute or a cent on owes you no explanation anyway. Take it or leave it, no hard feelings.

And for everyone who's on board: when contributing, you're kindly asked to follow the guidelines in
[AI_USAGE.md](AI_USAGE.md). Thanks!

## License

MIT, see [LICENSE](LICENSE). The PHP source fetched at build time stays under the
[PHP License](https://www.php.net/license/).
