---
eyebrow: 'SDK development'
lede:    'For authors of native packages: the PHP task has its stack in PSRAM and must never touch the flash. Work that does goes through the executor task, and the package declares what it needs in extra.baremetal.'
see_also:
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/esp32-executor', meta: 'Library', label: 'esp32-executor' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c', meta: 'Package', label: 'ext-i2c' }
prev: { label: 'Adding a PHP extension or minor', href: './adding-php.md' }
next: { label: 'Releases and previews', href: './releases.md' }
---

# Writing native packages

A native package is C code compiled into the firmware: an extension (`bm-ext`) with a PHP API, a HAL (`bm-hal`), a
device driver or a support library (`bm-lib`). This page lists the rules the firmware imposes on it and the keys the
SDK reads from the package.

## The flash rule

PHP runs on a task whose stack is in PSRAM. With ESP-IDF 6.1, a task with a PSRAM stack asserts as soon as it
touches the flash, because the flash cache is disabled during the operation. Code on the PHP task therefore must
never:

- read, write or erase the flash;
- use NVS, OTA or the partition API;
- call `esp_light_sleep_start()`, which suspends the cache;
- call `esp_wifi_*`, or start the PHY calibration (both touch the flash).

Mounting, unmounting, restarting and deep sleep happen in `app_main`, which has its stack in internal RAM. The
`embedded` storage reads its partition through a memory-mapped read-only disk driver, never with direct flash
reads. Writes to the microSD card are allowed from the PHP task: the card does not use the flash cache. I2C
transfers do not touch the flash either.

## The executor

Work that may touch the flash goes through `esp32-executor`, one task pinned to core 0 with an 8 KB stack in
internal RAM. A package that uses it requires it in its own `composer.json` and includes `executor.h`:

<!-- @code-block language="c" label="executor.h" -->
```c
esp_err_t executor_run_sync(esp_err_t (*fn)(void *arg), void *arg, uint32_t enqueue_timeout_ms);
bool executor_on_task(void);
```
<!-- @endcode-block -->

`executor_run_sync()` runs `fn(arg)` on the executor and blocks the caller until it returns; from the executor
itself it runs `fn` inline. It returns `ESP_ERR_TIMEOUT` when the work queue stays full for the timeout. The
executor also runs the background samplers of devices (`executor_poll()`); both share one task and run one after
the other. The [esp32-executor package](https://repository.php-baremetal.com/packages/php-baremetal/esp32-executor)
documents the whole header.

Existing users of the rule:

| Package | Goes through the executor |
| --- | --- |
| esp32-store-hal | every NVS operation, including listing keys |
| esp32-wifi-hal | every `esp_wifi_*`, `esp_netif_*` and `nvs_flash_*` call |
| esp32-sys-hal | `sys_light_sleep()` |
| esp32-i2c-hal | every transaction of a bus owned by the executor (`Bus::CORE1`) |

## Calling PHP

The build is not thread-safe. The rules follow:

- Only the `php` task calls into Zend, and that includes `emalloc` and the other allocators.
- Never call PHP from the executor or from another task. A callback that must reach PHP posts a message (a queue
  send) and the PHP task runs the listener. In the `event-driven` runtime that is what the event bus of ext-events is
  for.
- The function you pass to `executor_run_sync()` and a sampler must not call PHP or the Zend allocator.
- `sys_restart()` and `sys_deep_sleep()` never run from the PHP task: the firmware records the action, ends the
  script like `exit()`, and `app_main` performs it after PHP has shut down.

## Declaring what the package needs

A package is a Composer package with a `type` and an `extra.baremetal` object in its `composer.json`. The SDK
reads only the keys below; any other key is an error (`package <name>: unknown key '<key>' in extra.baremetal`).
All the native code of a project is compiled into one ESP-IDF component with its own dependencies merged.

| Type | Role | Keys it may use |
| --- | --- | --- |
| `bm-ext` | PHP extension in C | `module` (required), `entry`, `registries`, and the common keys |
| `bm-hal` | Platform layer under an extension | `platform` (required), and the common keys |
| `bm-lib` | Support library or device driver | the common keys |
| `bm-php-ext` | Selects an optional PHP extension of the SDK | only `php-ext`, the extension name |

The common keys are `sources`, `include-dirs`, `defines`, `idf-requires`, `idf-dependencies`, `sdkconfig`,
`partitions`, `register` and `priority`.

| Key | Meaning |
| --- | --- |
| `module` | The PHP module name (`[a-z_][a-z0-9_]*`), `bm-ext` only. It must not be a built-in module nor the name of an optional extension. |
| `entry` | The C symbol of the `zend_module_entry`, `bm-ext` only. Default `<module>_module_entry`. |
| `platform` | `bm-hal` only: the SDK name, `esp32`. The package name must start with `<vendor>/esp32-`. |
| `priority` | Integer, default 100. Modules register in order of priority, then module name. |
| `sources` | Source files relative to the package, `*` and `?` allowed in the last path segment; every pattern must match. Default `src/*.c`. |
| `include-dirs` | Include directories relative to the package. Default `include` and `src`, where they exist. |
| `defines` | Object of C defines, each an integer or a string without `;`, compiled into the package's sources. |
| `idf-requires` | List of ESP-IDF component names the package uses, for example `esp_timer` or `nvs_flash`. Use `phpbm` to include `phpbm.h` and the option header; `php` is always required. |
| `idf-dependencies` | Object of ESP-IDF registry components and constraints, for example `{ "espressif/led_strip": "==3.1.0~1" }`. A name without a namespace is read as `espressif/<name>`. Two packages that need the same component with different constraints stop the build. |
| `sdkconfig` | A file of `CONFIG_` lines, relative to the package, added to the project's sdkconfig. |
| `partitions` | List of `{ "name", "subtype", "size" }`: data partitions. |
| `registries` | `bm-ext` only: object of `name` to `{ "type", "header" }`, declaring a table of pointers to the C type that other packages fill. The SDK generates `<name>[]`, `NULL`-terminated, and `<name>_count`. |
| `register` | Object of `registry` to a list of C symbols (`const` objects of the registry's type) this package adds to a registry some package declares. |

Paths in `sources`, `include-dirs`, `sdkconfig` and `registries` must be relative, without `..`, `;`, `=` or quotes.

<!-- @code-block language="json" label="extra.baremetal of a HAL" -->
```json
{
  "platform": "esp32",
  "idf-requires": ["nvs_flash"],
  "partitions": [
    { "name": "phpstore", "subtype": "nvs", "size": "24K" }
  ]
}
```
<!-- @endcode-block -->

### Partitions

`name` is `[a-z_][a-z0-9_]*`, at most 16 characters, and not `factory`, `nvs`, `phy_init` or `storage`. `subtype` is
one of `nvs`, `nvs_keys`, `fat`, `spiffs`, `littlefs`, `coredump`, `efuse`, `undefined`, `phy`. `size` is `<n>K` with
`n` a multiple of 4. Two packages cannot declare the same partition name. The partitions are placed after `factory`:
see [Memory, flash and partitions](../reference/memory-and-flash.md).

### Registries

A registry lets a package collect objects from others without knowing them. ext-i2c, for example, declares the
registry `i2c_drivers`, and each I2C device driver registers its descriptor there. Two packages cannot declare the
same registry, and a package that registers into a registry nobody declares stops the build with
`registers into 'name', which no installed package declares`. See the ext-i2c page
[Writing a driver](https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c) for a worked example.

### Reading options

The generated `phpbm_options.h` carries the project's options as `PHPBM_OPTION_*` macros, and the PHP extensions
present as `PHPBM_PHP_EXT_<NAME>`: see [Options](../reference/options.md).
