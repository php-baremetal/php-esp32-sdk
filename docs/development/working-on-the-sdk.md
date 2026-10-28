---
eyebrow: 'SDK development'
lede:    'The repository is a set of descriptors, the build-time patches of PHP, a CMake firmware host and a PHP driver. Run the tests, link your checkout into phpbm, build a project with it.'
see_also:
  - { href: './adding-a-board.md', meta: 'SDK development' }
  - { href: './releases.md', meta: 'SDK development' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/sdks.md', meta: 'phpbm', label: 'SDKs and phpbm.lock' }
prev: { label: 'Troubleshooting', href: '../reference/troubleshooting.md' }
next: { label: 'Adding a board or chip', href: './adding-a-board.md' }
---

# Working on the SDK

Nobody uses this repository directly: `phpbm` downloads a release (a tarball of the directories below), loads
the descriptors, and calls the driver to build, flash, sync and monitor. Working on the SDK means changing
those files and building a real project with your checkout.

## Repository layout

| Path | What it holds | Who reads it |
|---|---|---|
| `sdk.json` | SDK name, version, driver class, the SDK options, the PHP releases (`version`, `url`, `sha256`, `startup-kb`) and the external `sources` (`url`, `sha256`). | phpbm, the driver |
| `boards/<id>.json` | One file per board: chip, PSRAM mode, flash, console and peripheral blocks. | phpbm (validation), `BuildInputs` |
| `chips/<chip>/` | `chip.json` (target, capabilities, PSRAM modes) and the `sdkconfig.*` fragments. | phpbm, `BuildInputs`, `host/cmake/sdkconfig.cmake` |
| `runtimes/<id>/` | `runtime.json` and the C of a runtime (`init-loop`, `event-driven`, `web-server`), an ESP-IDF component. | phpbm, CMake host |
| `storages/<id>/` | `storage.json` and the C of a storage (`embedded`, `microsd`), an ESP-IDF component. | phpbm, CMake host |
| `networks/<id>/` | The network drivers (`emac`, `w5500`, `wifi-c6`): `sdkconfig` and a `phpbm_<id>` component. | `BuildInputs`, CMake host |
| `php/<minor>/` | `patches/`, `sources.cmake`, `compat/` and `extensions/*.json` of one PHP minor (8.3, 8.4, 8.5). Minors coexist. | phpbm (patches, descriptors), `PhpExtensions`, CMake host |
| `sources/<name>/patches/` | Patches of the external sources declared in `sdk.json` (`oniguruma`, `sqlite`). | phpbm |
| `host/` | The firmware host: `project.cmake`, `post-project.cmake`, `cmake/`, `sdkconfig.defaults`, `sdkconfig/` fragments, and the components `php` and `phpbm`. | idf.py |
| `driver/` | The PHP driver (`PhpBaremetal\Esp32\Driver`) and `tools/sync.py`. | phpbm |
| `tools/release.php` | Builds the release tarball and index. | CI, you |
| `tests/` | PHPUnit tests. | CI, you |
| `.github/workflows/` | `tests.yml`, `release.yml`, `preview.yml`. | GitHub |
| `docs/`, `assets/`, `CONTRIBUTING.md`, `AI_USAGE.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md` | Documentation and project files; not part of a release. | people |

The driver does not know boards or PHP minors by name: it reads whatever the descriptors under `boards/`,
`chips/`, `php/<minor>/extensions/` and `runtimes/`, `storages/`, `networks/` declare. Adding a board or a
PHP extension is a data change, which is why the test suite loads every descriptor.

## The driver contract

The driver implements `PhpBaremetal\Spec\Sdk\Driver` from the `php-baremetal/spec` package. `sdk.json`
names the class and its autoload path, and carries `contract: 1`; phpbm refuses an SDK whose contract it
does not support.

| Method | What `PhpBaremetal\Esp32\Driver` does |
|---|---|
| `toolchain()` | The `Toolchain` of ESP-IDF: finding it, installing it, the environment for `idf.py`. |
| `build(BuildPlan, Output)` | Writes the build inputs, then runs `idf.py build`; returns the `Artifacts` (the flash files). |
| `flash(Artifacts, BuildPlan, Port, Output)` | Runs esptool `write-flash` with the files of the build. |
| `sync(BuildPlan, Port, Output)` | With `microsd` storage, copies the project sources to the card (`driver/tools/sync.py`); returns `false` and does nothing for other storages. |
| `monitor(Port)` | Runs `esp_idf_monitor` on the port at 115200 baud and returns its exit code. |
| `identify(Port)` | Asks esptool for the chip on the port; `null` when it is not an ESP32. |
| `ports()` | Lists the serial ports (pyserial, with a fallback listing). |

The `BuildPlan` carries everything phpbm resolved: the project, the SDK, the chip, board (with any
`board-overrides`), runtime, storage, the PHP release and its source directory, the optional PHP
extensions, the external source directories, the option values, the build directory and the `clean` flag.
The driver never reads the project's `composer.json`; phpbm hands it over.

## Running the tests

You need PHP 8.4 or newer, Composer and `git`. `composer.json` takes `php-baremetal/spec` from a path
repository, `../packages/spec`, so a checkout needs the spec package next to it. CI checks it out into
`packages/spec` beside `php-esp32-sdk`.

<!-- @code-block language="Bash" label="tests" -->
```bash
composer install
vendor/bin/phpunit
```
<!-- @endcode-block -->

PHPUnit runs the `tests/` directory as one suite, `php-esp32-sdk`, and fails on warnings and risky tests.

| Tests | What they cover |
|---|---|
| `SdkDescriptorsTest` | The real tree loads with the spec `SdkLoader`: SDK identity and options, the PHP releases and their patches, the extensions of every minor, the chips, every board. |
| `DriverConformanceTest` | The spec's conformance suite against this driver and the real tree. |
| `BuildInputsTest` | `inputs.cmake` and the generated headers: board, peripherals, flash mode, revision, network, options, errors for invalid boards. |
| `PhpExtensionsTest` | How an extension descriptor turns into sources, include dirs, defines, the module table and startup memory. |
| `PackagesTest`, `HostComponentsTest` | The `bm-*` packages (`PackageSpec`, partitions, sdkconfig fragments, ESP-IDF dependencies) and the `php` host component. |
| `DriverTest`, `ToolchainTest` | The `build`, `flash`, `sync` flow and the ESP-IDF discovery, with a fake `idf.py` and a recorded process. |
| `FlashFilesTest`, `SerialPortsTest`, `ChipProbeTest`, `BuildLogTest`, `IdfExportTest` | The parsers and helpers of the driver. |
| `ReleaseTest` | `tools/release.php`: tarball content, index merge, immutability, version rules. |

<!-- @callout variant="warning" title="Passing tests are not a working firmware" -->
The tests never run ESP-IDF or a board. A change that affects behaviour is done when it has been flashed to
real hardware; the pull request states the board and PHP version, with the serial output.
<!-- @endcallout -->

## Building with your checkout

Link the checkout, then build any project as usual. Every project that uses `esp32` then builds with that
directory.

<!-- @code-block language="Bash" label="a linked checkout" -->
```bash
phpbm sdk:link esp32 .              # from the checkout; a linked SDK has the version dev
cd ../some-project
phpbm toolchain:install             # once, installs the ESP-IDF the SDK needs
phpbm build                         # or phpbm flash, phpbm monitor
phpbm sdk:remove esp32@dev          # back to the releases
```
<!-- @endcode-block -->

A linked SDK never enters `phpbm.lock`, and each command warns that it is in use. Edit the checkout and
build again: the driver hashes the host, the PHP minor, the runtime, storage and network in use and the
external-source patches, and starts the ESP-IDF build from scratch when the hash changes. A change to an
sdkconfig fragment only drops the generated `sdkconfig`. Pass `--clean` to phpbm to force a clean build.

## How a build flows

1. phpbm resolves the project against the descriptors and builds the `BuildPlan`: board, runtime, storage,
   PHP release, extensions, options, directories. It downloads the PHP source, checks its sha256 and applies
   `php/<minor>/patches/` plus the patches of the external sources.
2. `Driver::build()` checks the toolchain, the network settings (`BuildInputs::network()`), and loads the
   `bm-*` packages (`Packages::fromPlan()`).
3. `BuildInputs::write()` writes the inputs into the build directory:

   | File | Content |
   |---|---|
   | `inputs.cmake` | One `set(PHPBM_...)` per fact: `PHPBM_BOARD`, `PHPBM_TARGET`, `PHPBM_PSRAM`, `PHPBM_FLASH_MODE`, `PHPBM_REVISION`, `PHPBM_RUNTIME`, `PHPBM_STORAGE`, `PHPBM_NETWORK`, `PHPBM_PHP_MINOR`, `PHPBM_PHP_SRC`, the PHP extension lists, and one `PHPBM_<BLOCK>_<KEY>` per peripheral key (`PHPBM_NETWORK_CS`, `PHPBM_MICROSD_PWR_EN`). |
   | `include/phpbm_options.h` | The option values as `PHPBM_OPTION_*` macros, the active extensions as `PHPBM_PHP_EXT_*`, the startup memory, the network state. |
   | `board.sdkconfig` | The flash size of the board. |
   | `env.c` | The project `env` as a C table. |
   | `php_extensions.c` | The module table of the optional PHP extensions (only when there are some). |
   | the packages component | The generated ESP-IDF component of the `bm-*` packages. |

4. `BuildInputs::project()` writes a tiny `project/CMakeLists.txt` that includes `host/project.cmake` and
   `host/post-project.cmake`.
5. `host/project.cmake` reads `inputs.cmake` (the path comes in as `-DPHPBM_INPUTS`), sets the target, the
   component directories (`host/components`, the runtime, the storage, the packages, the network driver)
   and the sdkconfig defaults; `host/cmake/sdkconfig.cmake` stacks the fragments in this order, later
   ones winning: `host/sdkconfig.defaults`, `chips/<chip>/sdkconfig`, `sdkconfig.<psram>`,
   `sdkconfig.rev-<revision>`, `sdkconfig.flash-<mode>`, `host/sdkconfig/console-<console>`,
   `optimization-<value>`, `power-save` (option on), the runtime's, the storage's and the network's
   `sdkconfig`, the packages' fragments, then `board.sdkconfig`.
6. The driver runs `idf.py -C <build>/project -B <build>/idf -DSDKCONFIG=<build>/sdkconfig -DPHPBM_INPUTS=... -DIDF_TARGET=<target> build`
   and reads the flash files (`FlashFiles::read()`).

The build directory is `.phpbm/build` in the project. It holds `idf/` (the ESP-IDF build tree),
`sdkconfig`, `inputs.cmake`, `include/`, `project/` and `fingerprint.json`, which records what the last build
was made from; a different board, runtime, storage, PHP minor, ESP-IDF version, SDK content or sdkconfig
fragment invalidates the cached `sdkconfig`, and a different target, SDK directory or version, SDK content or ESP-IDF
starts `idf/` over. A project copied to another directory is detected through its CMake cache and rebuilt
from scratch.

## Continuous integration

`.github/workflows/tests.yml` runs on every push, on pull requests, and when another workflow calls it (the
release and preview workflows do, with the spec tag they build against).

| Axis | Values |
|---|---|
| Operating system | `ubuntu-latest`, `macos-latest`, `windows-latest` |
| PHP | 8.4, 8.5 |

Each job checks out the SDK into `php-esp32-sdk` and `php-baremetal/spec` into `packages/spec`, installs
PHP with Composer, runs `composer install` and `vendor/bin/phpunit`. `fail-fast` is off, so every cell of the
matrix reports. See [Releases and previews](./releases.md) for what happens after the tests pass.
