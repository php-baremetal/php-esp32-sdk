---
eyebrow: 'SDK · Reference'
lede:    'The esp32 SDK builds with ESP-IDF v6.1 for the ESP32-S3 and the ESP32-P4. What phpbm toolchain:install does for it, where things go, and the variables and path rules that matter.'
see_also:
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/toolchains.md', meta: 'phpbm', label: 'phpbm toolchains guide' }
  - { href: './troubleshooting.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/reference/files-and-environment.md', meta: 'phpbm', label: 'Files and environment' }
prev: { label: 'Power saving', href: './power-save.md' }
next: { label: 'Troubleshooting', href: './troubleshooting.md' }
---

# Toolchain

The toolchain of the `esp32` SDK is ESP-IDF v6.1 (`v6.1` and any `v6.1.x`), with its compiler, CMake, Ninja and
Python environment, for the targets `esp32s3` and `esp32p4`. The general behaviour of `phpbm toolchain:install` is in
the [phpbm toolchains guide](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/toolchains.md); this page
adds what is specific to this SDK.

## What toolchain:install does

Run inside a project, `phpbm toolchain:install` does three things, each only when it is missing:

1. Clones ESP-IDF v6.1 from `https://github.com/espressif/esp-idf.git`, shallow and with its submodules, into
   `~/.phpbm/toolchains/esp32/esp-idf-v6.1`. An incomplete or wrong-version checkout in that folder is deleted first.
2. Installs the ESP-IDF tools with `idf_tools.py` (`required`, `cmake` and `ninja`) for `esp32s3,esp32p4`, into
   `~/.phpbm/toolchains/esp32/tools`.
3. Creates the ESP-IDF Python environment, `install-python-env`, in
   `~/.phpbm/toolchains/esp32/tools/python_env/idf6.1_py<version>_env`.

It needs `git` and a Python 3.10 or newer on the machine. A failed step stops with its last output line; run the
command again to resume.

`phpbm build`, `flash`, `sync` and `monitor` run through that environment: the build calls `idf.py`, the flash calls
`esptool` at 460800 baud, the monitor runs `esp_idf_monitor` at 115200 baud. Each activates the toolchain first and
stops with the reason when it is missing.

## Python

phpbm looks for a Python 3.10 or newer in this order and takes the first that answers:

| System | Candidates |
| --- | --- |
| any | the command in `PHPBM_PYTHON` |
| Linux and macOS | `python3`, `python3.13`, `python3.12`, `python3.11`, `python3.10`, `python` |
| Windows | `py -3`, `python` |

Without one the build stops with `ESP-IDF needs Python 3.10 or newer`. On Windows install Python from python.org and
tick "Add python.exe to PATH".

## Where things are

| What | Default | Moved by |
| --- | --- | --- |
| phpbm home | `~/.phpbm` (`%USERPROFILE%\.phpbm` on Windows) | `PHPBM_HOME` |
| ESP-IDF | `<home>/toolchains/esp32/esp-idf-v6.1` | `PHPBM_ESP32_IDF_PATH` |
| ESP-IDF tools and Python environment | `<home>/toolchains/esp32/tools` | `PHPBM_ESP32_IDF_TOOLS_PATH` or `IDF_TOOLS_PATH`, with `PHPBM_ESP32_IDF_PATH` |
| Build of a project | `.phpbm/build` | |

## Environment variables

| Variable | Meaning |
| --- | --- |
| `PHPBM_PYTHON` | The Python to use, tried before the others. |
| `PHPBM_ESP32_IDF_PATH` | An ESP-IDF v6.1 that you manage yourself. phpbm uses it in place and never installs into it: install its tools there with its own `tools/idf_tools.py install` and `install-python-env`. `phpbm toolchain:install` refuses with this variable set. |
| `PHPBM_ESP32_IDF_TOOLS_PATH` | With `PHPBM_ESP32_IDF_PATH`: where that ESP-IDF's tools live. |
| `IDF_TOOLS_PATH` | Read when the previous one is not set. With neither, the tools are in `~/.espressif` (`%USERPROFILE%\.espressif` on Windows). |
| `PHPBM_HOME` | Where phpbm keeps SDKs, toolchains and cache; set it to a short path without spaces on Windows. |

Whichever ESP-IDF is used, phpbm sets `IDF_PATH`, `IDF_TOOLS_PATH` and `IDF_PYTHON_ENV_PATH` for the commands it runs,
so your shell does not need `export.sh`.

## Version check

phpbm reads the version from `components/esp_common/include/esp_idf_version.h` of the ESP-IDF in use. Only `v6.1` and
`v6.1.x` are accepted. Otherwise the toolchain counts as not installed:

- `ESP-IDF not found in <path>`: there is no ESP-IDF there;
- `ESP-IDF <version> in <path>, the esp32 sdk needs v6.1`: another version is there.

With the managed toolchain, `phpbm toolchain:install` replaces a wrong version; with `PHPBM_ESP32_IDF_PATH` you replace
it yourself. A change of ESP-IDF path or version also starts the next build from scratch.

## Path rules

<!-- @callout variant="warning" title="Windows: no spaces, no non-ASCII" -->
ESP-IDF cannot work from a path with spaces or non-ASCII characters. On Windows phpbm checks the ESP-IDF path, the
tools path, the project folder, its `.phpbm/build` folder and the SDK folder, and stops with `ESP-IDF cannot work from
a path with spaces or non-ASCII characters (<path>)`. Move the project, or set `PHPBM_HOME` to a path without them.
<!-- @endcallout -->

On every system the project, the SDK, the PHP sources, the build folder and the packages must not contain `;` or `=`:
the build passes them through CMake lists and `-ffile-prefix-map`. The error is `<what> (<path>) contains ';' or '=',
which the build cannot handle: move it to a path without them`.
