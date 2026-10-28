# Contributing to php-esp32-sdk

Thanks for being here. This repository is the ESP32 SDK of php-baremetal: it describes the boards, chips,
runtimes and storages, carries the build-time patches that run the real PHP engine on ESP32-S3 and
ESP32-P4, and the driver [`phpbm`](https://github.com/php-baremetal/phpbm) calls to build and flash. It's
built in spare time — contributions are genuinely welcome, and a lot of the work is self-contained.

Extensions, HALs and drivers are Composer packages in their own `php-baremetal/<name>` repositories, not
here: a GPIO feature goes to `ext-gpio` / `esp32-gpio-hal`, a sensor driver to its own package.

## Ways to contribute

Good first areas, roughly easiest first — each is self-contained:

- **A board.** Support a board that isn't covered yet — one JSON file under `boards/`.
- **Documentation and examples.** Fixes and recipes; the runnable examples live in
  [samples](https://github.com/php-baremetal/samples).
- **A PHP extension.** Make an `ext/*` of PHP available for a minor: `php/<minor>/extensions/<name>.json`
  (and its `bm-php-ext` package in `php-extensions`).
- **Bug fixes and hardening.** Especially anything found while running on real hardware.

Bigger things (a new PHP minor, a runtime, a storage, a network, a new chip family, the driver) are very
welcome too — just **open an issue or a discussion first** so we can agree on the shape before you write
a lot of code.

> **Testing on a real board is mandatory before opening any PR.** This is firmware — a change that
> compiles is not a change that works. Every PR that affects behaviour must be flashed to real
> hardware and confirmed working, and the PR must state which board(s) and PHP version(s) you tested on,
> with the relevant serial output. PRs without hardware verification will not be merged. (Docs-only,
> comment-only and test-only changes are the exception.)

## The principles the project holds to

- **Real PHP, unmodified.** The engine is the pristine release from [php.net](https://www.php.net/),
  downloaded and sha256-checked at build time and **never edited in place**. Every target-specific change
  is a build-time patch under `php/<minor>/patches/`. If you think you need to edit the engine directly,
  that's a sign to open a discussion.
- **The descriptors are the source of truth.** `sdk.json`, `boards/`, `chips/`, `runtimes/`, `storages/`
  and `php/<minor>/extensions/` describe what the SDK can build; phpbm validates projects against them and
  only ever offers real options. A new option or extension changes its descriptor, and the test suite
  must stay green.
- **Opt-in weight.** PHP extensions and features are off by default and cost only the flash they add.
- **Releases are immutable.** A published version is never rebuilt with different contents: a fix is a
  new version.
- **Verified on hardware.** A change is done when it runs on a real board, not when it compiles.
- **Follow the surrounding code.** Match the style, naming and comment density of the files you touch.

## Building and testing

You need PHP 8.4+, Composer, `git` and [phpbm](https://github.com/php-baremetal/phpbm).

```bash
composer install
vendor/bin/phpunit                      # descriptors, driver, patches, release tooling
phpbm sdk:link esp32 .                  # every project now builds with this checkout
cd ../some-project && phpbm build       # or phpbm flash, phpbm monitor
phpbm sdk:remove esp32@dev              # back to the releases
```

`phpbm toolchain:install`, run in a project, installs the ESP-IDF version the SDK needs. CI runs the
test suite on push and PR; keep it green, and add a test with the change.

## How-to, by area

- **Add a board** — `boards/<vendor>-<model>.json` with the name, chip, PSRAM mode, flash size, console and
  the peripheral blocks the chip supports (`network`, `microsd`) with their pins. Copy the closest existing board. Two
  hard requirements: the part needs **external PSRAM** and **at least 4 MB of flash**, or the engine
  won't fit.
- **Add a chip variant** — `chips/<chip>/` (`chip.json` and its `sdkconfig.*` fragments).
- **Make a PHP extension available** — `php/<minor>/extensions/<name>.json` for every minor that supports
  it, plus patches if it needs them, then its `php-<name>` package in
  [php-extensions](https://github.com/php-baremetal/php-extensions).
- **Add a PHP minor** — a new `php/<minor>/` (patches, `sources.cmake`, `compat`, `extensions`) and its
  release entry in `sdk.json`. Minors coexist.
- **A runtime, storage or network** — `runtimes/<id>/`, `storages/<id>/`, `networks/<id>/`; open a
  discussion first.

## Pull requests

- **Branch** off `master`; keep a PR focused on one thing.
- **Explain** what and why in the description, and **state your hardware verification**: board(s), PHP
  version(s), and the serial output that shows it working.
- **Keep the tree clean** — tests green, no build artifacts, no editing the PHP source in place (use a
  patch).
- Small, reviewable commits with clear messages are appreciated.

## AI assistance

Using an AI assistant to help write a contribution is fine — it's a tool. What matters is that the code
is correct, that **you understand it and stand behind it**, and that it's verified on hardware like any
other change. Please don't open PRs of unreviewed generated code you haven't run. The full policy is in
[AI_USAGE.md](AI_USAGE.md).

## Reporting bugs and security issues

- **Bugs:** open a GitHub issue with the board id, the PHP version, the SDK version (from `phpbm.lock`),
  your `composer.json` and a minimal `index.php`, and the serial output. Redact secrets first (see the
  log-sharing guidance in [SECURITY.md](SECURITY.md)).
- **Security vulnerabilities:** do **not** open a public issue — follow [SECURITY.md](SECURITY.md).
  Note that vulnerabilities in PHP proper or in ESP-IDF go to those projects, not here.

## License

By contributing, you agree that your contributions are licensed under this project's
[MIT License](LICENSE), the same as the rest of the project's own code. (The PHP source the build
fetches stays under the [PHP License](https://www.php.net/license/); it is not part of this repository.)
