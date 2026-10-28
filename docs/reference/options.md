---
eyebrow: 'SDK · Reference'
lede:    'Every option of the esp32 SDK, of its runtimes and of its optional PHP extensions: owner, type, range, default and effect. A project sets them in extra.baremetal.options.'
see_also:
  - { href: './memory-and-flash.md', meta: 'Reference' }
  - { href: './power-save.md', meta: 'Reference' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md', meta: 'phpbm', label: 'The project' }
prev: { label: 'PHP frameworks', href: '../modules/frameworks.md' }
next: { label: 'PHP versions and extensions', href: './php.md' }
---

# Options

Options tune the firmware without touching its code. Each has an owner: the SDK itself, the runtime or the storage
of the project, or an optional PHP extension. Every option has a default, so a project sets only the ones it
changes.

## Setting options

Put them in an `options` object next to `sdk`, `board`, `runtime` and `storage`:

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "sdk": "esp32@^2.0",
  "board": "waveshare-esp32-s3-eth",
  "runtime": "init-loop",
  "storage": "embedded",
  "options": {
    "loop-interval": 100,
    "on-error": "continue",
    "mbstring-regex": true
  }
}
```
<!-- @endcode-block -->

Values are typed: an integer option takes a JSON number, a boolean option `true` or `false`, the others a string.
The firmware reads each option at build time, so changing one means building and flashing again.

<!-- @callout variant="note" title="Which options a project accepts" -->
A project accepts the SDK options, the options of its own runtime and storage, and the options of the PHP
extensions it requires (`php-baremetal/php-<name>`). `loop-interval` is an error under `event-driven`, and
`mbstring-regex` is an error until `php-baremetal/php-mbstring` is required.
<!-- @endcallout -->

## SDK options

These apply to every runtime.

| Option | Type | Values or range | Default | Effect |
| --- | --- | --- | --- | --- |
| `network` | enum | `off`, `on` | `off` | Brings up the board's wired network (DHCP) before PHP starts. On a Wi-Fi board the script connects with ext-wifi. A runtime that needs a network turns it on by itself. |
| `on-error` | enum | `stop`, `continue` | `stop` | An uncaught exception in `loop()` or in an event handler: `stop` ends the program, like the php CLI; `continue` prints it as a warning and goes on. Fatal errors always stop. In `web-server` an error ends only that request. |
| `restart-delay` | int | 0 to 3600 | `5` | Seconds the board waits before it restarts once the program has ended. |
| `firmware-reserve` | int | 256 to 4096 | `1024` | PSRAM in KB left to the rest of the firmware (network, HTTP server, native extensions); PHP gets the rest as `memory_limit`. See [Memory, flash and partitions](./memory-and-flash.md). |
| `optimization` | enum | `size`, `performance`, `debug` | `size` | Compiler optimization of the whole firmware: `size` is `-Os`, `performance` is `-O2`, `debug` is `-Og`. |
| `power-save` | enum | `off`, `on` | `off` | Automatic light sleep while every task waits. See [Power saving](./power-save.md). |
| `request-timeout` | int | 0 to 3600 | `30` | Seconds allowed for one HTTP request, in `web-server` or for ext-web requests in `event-driven`: PHP gets `max_execution_time` equal to this value, and the server answers 503 after it plus 5 s. `0` means no limit. |

## Runtime options

`event-driven` and the two storages (`embedded`, `microsd`) declare no option.

### init-loop

| Option | Type | Values or range | Default | Effect |
| --- | --- | --- | --- | --- |
| `loop-interval` | int | 0 to 60000 | `0` | Milliseconds to wait between two `loop()` calls. `0` runs `loop()` again right away, which also keeps the board from sleeping. |

### web-server

| Option | Type | Values or range | Default | Effect |
| --- | --- | --- | --- | --- |
| `init` | string | a relative `.php` path such as `boot/wifi.php`, or empty | empty | Script run once at boot, before the server starts (for example to connect to Wi-Fi with ext-wifi). Relative to the source directory. Empty for none. |
| `port` | int | 1 to 65535 | `80` | TCP port of the HTTP server. |

The runtimes are described in [Runtimes](../runtime/runtimes.md).

## PHP extension options

Only the extensions below declare options, and only for projects that require them. Each is the same on PHP 8.3,
8.4 and 8.5. Their cost and the rest of the extensions are in [PHP versions and extensions](./php.md).

| Option | Owner | Type | Values or range | Default | Effect |
| --- | --- | --- | --- | --- | --- |
| `date-timezones` | `php-date` | enum | `full`, `utc` | `full` | Timezone database: `full` has every named zone (about 350 KB); `utc` has UTC only, and named zones such as `Europe/Rome` are rejected. |
| `mbstring-cjk` | `php-mbstring` | bool | `true`, `false` | `true` | The Chinese, Japanese and Korean encodings (SJIS, EUC-*, Big5, GB18030, ISO-2022-*) and `mb_convert_kana()`. Off saves most of the extension's flash and makes `mb_language()` reject `ja`, `ko`, `zh-cn` and `zh-tw`; UTF-8/16/32 and the Latin and Cyrillic encodings stay. |
| `mbstring-regex` | `php-mbstring` | bool | `true`, `false` | `false` | The `mb_ereg*()` and `mb_split()` functions, with the oniguruma regex library. |
| `opcache-memory` | `php-opcache` | int | 256 to 16384 | `1024` | PSRAM in KB for the bytecode cache, taken from `memory_limit` (OPcache allocates about 64 KB more besides it). No JIT, nothing is written to flash. Needs a board with 4 MB of PSRAM or more: below that OPcache is disabled at boot. |
| `opcache-validate` | `php-opcache` | bool | `true`, `false` | `false` | `microsd` storage only: checks the scripts' timestamps (`opcache.validate_timestamps`), for PHP files the program itself writes on the card. Off, the sources change only with a flash or a sync, which restart the board. Always off with `embedded` storage. |

<!-- @callout variant="warning" title="opcache-validate and FAT timestamps" -->
FAT stores modification times with a 2-second resolution, and without a set clock every file gets the same 1980
time: a file rewritten within 2 seconds, or while the clock is unset, may not be detected.
<!-- @endcallout -->

## Validation errors

phpbm checks `extra.baremetal.options` before it builds, and reports every problem at once under
`the project does not fit sdk esp32 <version>`:

| Message | Meaning |
| --- | --- |
| `unknown option 'x' in extra.baremetal.options (accepted: a, b, ...)` | The name is not declared by the SDK, the runtime, the storage or a required PHP extension. The message lists the names that are accepted for this project. |
| `extra.baremetal.options.x: must be one of off, on, got "maybe"` | An enum option got a value outside its list. |
| `extra.baremetal.options.x: must be an integer between 0 and 3600, got 4000` | An integer option got a non-integer or a value outside its range. |
| `extra.baremetal.options.x: must be true or false, got "yes"` | A boolean option got something else. |
| `extra.baremetal.options.init: must be a string that must match ..., got "..."` | The `init` path is not a relative `.php` path. |

A name declared by two owners (`option 'x' is declared by both ... and ...`) is reported too, but it is a defect
of the SDK, not of the project.

## In C code

Each option reaches the firmware as a `PHPBM_OPTION_<NAME>` macro in the generated `phpbm_options.h` (`NAME` is
the option name in capitals with `_` for `-`): a string for an enum or a string option, `0` or `1` for a boolean,
a number for an integer. An enum option also gets one `PHPBM_OPTION_<NAME>_<VALUE>` macro per value, `1` for the
chosen one. A native package that lists `phpbm` in its `idf-requires` can include the header. See
[Writing native packages](../development/firmware-rules.md).
