---
eyebrow: 'SDK · Reference'
lede:    'The messages the esp32 SDK prints when a build, a flash, a sync or the toolchain fails, and the fix for each.'
see_also:
  - { href: './toolchain.md', meta: 'Reference' }
  - { href: './options.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/reference/troubleshooting.md', meta: 'phpbm', label: 'phpbm troubleshooting' }
prev: { label: 'Toolchain', href: './toolchain.md' }
next: { label: 'Working on the SDK', href: './../development/working-on-the-sdk.md' }
---

# Troubleshooting

These are the errors that come from the SDK. The ones that come from phpbm itself (project, lock, ports, SDK
download) are in the [phpbm troubleshooting page](https://github.com/php-baremetal/phpbm/blob/master/docs/reference/troubleshooting.md).
A message with `<...>` stands for a value that changes. Run a command with `-vv` for the full trace.

## Build

| Message | Fix |
| --- | --- |
| `the firmware build failed (exit N): see the output above` | The lines before it are ESP-IDF's: the first `error:` is the cause. `will not fit in region` or `region ... overflowed` means the firmware is too big: drop optional PHP extensions or use a board with more flash. After a toolchain or SDK change, `phpbm build --clean`. |
| `<file> is missing or invalid: the build did not complete, run phpbm build --clean` | The build left no flash list. Build again with `--clean`. |
| `the project sources (...K) and the package partitions (...K) leave ...K for the firmware (at least 1024K): use a board with more flash or the microsd storage` | The sources of the `embedded` storage and the package partitions take too much flash. Pick a board with more flash, or use `microsd`. See [Memory, flash and partitions](./memory-and-flash.md). |
| `project sources not found in <path>` | `extra.baremetal.src` points to a folder that does not exist. |
| `invalid env variable name '<name>'` | A variable in the project's `env` file does not match `[A-Za-z_][A-Za-z0-9_]*`. |
| `<what> (<path>) contains ';' or '=', which the build cannot handle: move it to a path without them` | The project, SDK, build or package folder has a `;` or `=` in its path. Move it. |
| `ESP-IDF cannot build from a path with spaces or non-ASCII characters (<path>): move the project, or set PHPBM_HOME for the sdk` | Windows only. See [Toolchain](./toolchain.md). |

## Options and packages

| Message | Fix |
| --- | --- |
| `unknown option '<name>' in extra.baremetal.options (accepted: ...)` | A typo, or an option of another runtime or of a PHP extension the project does not require. Use a listed name. See [Options](./options.md). |
| `extra.baremetal.options.<name>: must be ...` | The value is outside the type or range of the option. |
| `php extension '<name>' needs '<other>': composer require php-baremetal/php-<other>` | `pdo_sqlite` needs `php-pdo`, for example. Run the `composer require`. |
| `<package> requires the PHP extension '<name>', which sdk ... does not provide for PHP ...` | The SDK has no such extension for the PHP minor in `config.platform.php`. See [PHP versions and extensions](./php.md). |
| `package <name>: unknown key '<key>' in extra.baremetal (allowed: ...)` and the other `package <name>: ...` messages | A package declares something the SDK does not read, or a wrong value. See [Writing native packages](../development/firmware-rules.md). |
| `package <name>: module '<module>' is a built-in PHP extension` | The `module` of a package is `json`, `standard` or another built-in name. Rename it. |
| `package <name>: module '<module>' is the name of PHP's optional extension <ext> (php-baremetal/php-<ext>): rename it` | Same, with an optional extension's name. |
| `packages <a> and <b> both declare the partition '<name>'` | Two packages declare the same partition. Rename one. |
| `packages <a> and <b> require the ESP-IDF component <c> with different constraints (...)` | Two packages pin a different version of one ESP-IDF component. Align them. |

## Board and network

| Message | Fix |
| --- | --- |
| `option network=on: board '<id>' has no network` | The board has no wired network. Remove the option, or pick a board with one. |
| `runtime '<id>' needs a network, but board '<id>' has none` | `web-server` needs a network. Same for a storage. Choose another runtime, or a board with a network. |
| `network type '<type>' of board '<id>' is not supported by this sdk` | The board file names a network type that has no folder in the SDK's `networks/`. Fix the board file, or use a supported board. See [Board files](../boards/board-files.md). |
| `board '<id>' connects over Wi-Fi from PHP: composer require php-baremetal/ext-wifi` | A Wi-Fi board needs ext-wifi: the script connects. |
| `board '<id>' has no Wi-Fi: remove php-baremetal/ext-wifi` | The board has no radio. Remove the package. |
| `board <id>: chip '<chip>' does not support flash-mode '<mode>'` | `flash-mode` in the board file is a mode the chip has no fragment for. Only `octal` on the ESP32-S3 exists besides `quad`. |
| `board <id>: chip '<chip>' has no revision '<rev>'` | The `revision` in the board file is not `v1` or `v3` for the ESP32-P4. |
| `board <id>: unsupported flash size '<size>' (use e.g. 4MB)` | `flash` in the board file must be whole megabytes, like `16MB`. |
| `board <id>: microsd.<pin> must be a GPIO number from 0 to 54` | A pin of the `sdio` microSD slot in the board file is not a GPIO of the ESP32-P4. |
| `board <id>: <peripheral>.<key> must be a number or a string` | A value in a board file's peripheral is neither. |

Boards with ESP32-P4 silicon of the wrong revision refuse to boot a build for the other revision: the board file's
`revision` (default `v1`) must match the chip. phpbm checks the chip before it flashes: see the next section.

## Flash

| Message | Fix |
| --- | --- |
| `flashing <port> failed: <last line>; check the cable, or hold BOOT while pressing RESET and try again` | Check that the cable carries data and that no monitor holds the port. If it still fails, enter download mode: hold BOOT, press RESET, release both, flash again. |
| `board '<id>' uses esp32s3, but the chip on <port> is esp32p4 (use --force to flash anyway)` | The board id in `extra.baremetal.board` does not match the connected chip, or the port is another board's. `phpbm discover` shows what is connected. |
| `no esp32 chip answered on <port> (use --force to flash anyway)` | The board is not in download mode or the cable is power-only. Hold BOOT while pressing RESET, then flash again. |

The last two come from phpbm and are listed in its own troubleshooting page.

## Sync (microsd storage)

`phpbm sync` ends with `syncing to the microSD card on <port> failed: <reason>`. The reasons:

| Reason | Fix |
| --- | --- |
| `the board did not answer the sync: is it running a microSD firmware? (flash it first)` | The board does not run a `microsd` firmware, or it is not running. Flash first; check the port. |
| `the firmware on <port> does not use the microSD (storage <name>): flash it first` | The board runs an `embedded` build. Flash a `microsd` build. |
| `the board says: stopped: no microSD card` | Insert a formatted card and run `phpbm sync` again. |
| `the board stopped answering` and `the board stopped answering while listing the card` | The board reset or hung during the transfer. Retry; if it repeats, flash again. |
| `the board could not list the card: ...` | The card cannot be read: check it and its format, then retry. |
| `not enough space on the card: <n> KB to send, <m> KB free` | Free space on the card or send less. |
| `cannot sync <file>: unsupported file name (...)` | The card's FAT cannot hold the name: rename the file to printable ASCII, without a trailing `.` or space or any of `\ : " * < > ? \|`. |
| `cannot sync <a> and <b>: the card's file names are case-insensitive` | Two files differ only by case. Rename one. |
| `error: <n> file(s) could not be synced` | The lines above name each file and the card's answer. |

The `env` file of the project is never sent to the card.

## Toolchain

| Message | Fix |
| --- | --- |
| `ESP-IDF needs Python 3.10 or newer: install it ... or set PHPBM_PYTHON` | See [Toolchain](./toolchain.md). |
| `ESP-IDF not found in <path>` and `ESP-IDF <version> in <path>, the esp32 sdk needs v6.1` | Run `phpbm toolchain:install`, or point `PHPBM_ESP32_IDF_PATH` at a v6.1 checkout. |
| `ESP-IDF tools are not installed in <path>: run phpbm toolchain:install` | The checkout is there but its tools are not. With your own ESP-IDF, run its `idf_tools.py install` and `install-python-env`. |
| `cannot activate ESP-IDF in <path>: <last line>; ...` | `idf_tools.py export` failed. Read the last line; usually the tools are missing or the Python environment is broken. |
| `PHPBM_ESP32_IDF_PATH points to an ESP-IDF managed outside phpbm: ...` | `toolchain:install` does not install into your own ESP-IDF. Unset the variable, or install its tools yourself. |
| `git is required to install ESP-IDF: install git and run phpbm toolchain:install again` | Install git. |
| `cannot clone ESP-IDF v6.1: <last line>; check the network and run ... again` | A network or disk problem. Run the command again. |
| `installing ESP-IDF tools for esp32s3,esp32p4 failed (exit N): ...` | Same: fix the cause and run again. |
| `cannot remove the incomplete ESP-IDF checkout in <path>: delete it by hand ...` | Delete the folder. |
| `ESP-IDF cannot work from a path with spaces or non-ASCII characters (<path>): set PHPBM_HOME to a path without them` | Windows only. |

## On the board

The firmware prints these on the console, and then restarts.

| Message | Meaning and fix |
| --- | --- |
| `--- stopped: no microSD card ---` | The `microsd` storage found no card. Insert it. |
| `--- stopped: no <entry> on the microSD card (run phpbm sync) ---` | The card has no entry script. Run `phpbm sync`. |
| `PSRAM too small for PHP: ...` | `firmware-reserve`, the PHP startup and `opcache-memory` leave less than 512 KB. Lower them, drop extensions, or use a board with more PSRAM. |
| `opcache disabled: needs >= 4 MB PSRAM` | A board with under 4 MB of PSRAM cannot run OPcache. Informational. |
| `no PSRAM for the <n> KB PHP stack` | The PSRAM is exhausted before PHP starts. |
| `Allowed memory size of <n> bytes exhausted` | The script used up `memory_limit`. See [Memory, flash and partitions](./memory-and-flash.md). |
| `phpbm: warning: power save could not be configured, light sleep stays off` | `power-save` is on but ESP-IDF refused the configuration. See [Power saving](./power-save.md). |

A board that no longer answers on its serial port: hold BOOT, press RESET, and flash again. See
[Power saving](./power-save.md).
