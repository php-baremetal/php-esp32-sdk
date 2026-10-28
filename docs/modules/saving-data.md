---
eyebrow: 'SDK · Modules'
lede:    'Keep data across resets: short strings in a flash key-value store with ext-store, or ordinary files on the microSD card. The embedded sources are read-only.'
see_also:
  - { href: '../runtime/storage.md', meta: 'Runtime' }
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
  - { href: './php-extensions.md', meta: 'Module' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-store', meta: 'Extension', label: 'ext-store' }
prev: { label: 'HTTP and the web', href: './web.md' }
next: { label: 'System and power', href: './system-and-power.md' }
---

# Saving data

A script's variables vanish at every reset. Two places keep data across resets:

| | ext-store | Files on the microSD card |
| --- | --- | --- |
| What | Short string values under short keys, in flash | Ordinary files and directories |
| Needs | `composer require php-baremetal/ext-store` | A board with a microSD slot and `"storage": "microsd"` |
| Size | Keys 1 to 15 bytes, values up to 4000 bytes, 24 KB in all | The card |
| Best for | Settings, counters, calibration, Wi-Fi credentials | Logs, measurements, pages, databases |
| Wear | Every write erases flash: write rarely | Card wear applies as for any card |

The `embedded` storage is not a place for data: it is a read-only image of your sources (see
[Storage](../runtime/storage.md)). For fast-changing values that need not survive a reset, `ext-mem` keeps them
in RAM without wearing flash.

## Key-value store: ext-store

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/ext-store
```
<!-- @endcode-block -->

The conductor adds the ESP32 HAL (`esp32-store-hal`), which keeps the pairs in an NVS partition named
`phpstore` that the build adds to the partition table. There is no size to set in the project.

| Function | Meaning |
| --- | --- |
| `store_set(string $key, string $value)` | Write and commit; `true` once the value is in flash. |
| `store_get(string $key, ?string $default = null)` | The value, or `$default` when absent. |
| `store_has(string $key)` | Whether the key is stored. |
| `store_delete(string $key)` | Remove one key; `false` when it was absent. |
| `store_clear()` | Remove every key of the script. |
| `store_keys()` | The keys, in no particular order. |
| `store_available()` | Whether the store opened at boot. |

`new_examples/03-storage/store-demo` is a boot counter. Reset the board and the count keeps climbing:

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
function setup(): void
{
    echo "\n=== store-demo :: persistent boot counter ===\n";
    if (!store_available()) {
        echo "  persistence is OFF -- require php-baremetal/ext-store\n";
        return;
    }

    $boots = (int) store_get('boots', '0') + 1;   // values are strings; cast to use them
    store_set('boots', (string) $boots);
    if ($boots === 1) {
        store_set('first_msg', 'hello from boot #1');
    }

    printf("  boot count : %d   (survives resets)\n", $boots);
    printf("  first_msg  : %s\n", store_get('first_msg', '(none)'));
    printf("  keys       : %s\n", implode(', ', store_keys()));
}

function loop(int $tick): void
{
    delay(5000);
}
```
<!-- @endcode-block -->

Its `composer.json` requires `ext-store` and `ext-sys` on the Waveshare ESP32-S3-Zero (N8R8), with the
`init-loop` runtime and `embedded` storage. The store works in every runtime and with either storage.

### Rules on the board

- **Strings only.** Cast numbers on the way in and out; store arrays with `json_encode()` and bytes with
  `base64_encode()`. A value ends at its first NUL byte, and a value over 4000 bytes makes `store_set()` return
  `false`.
- **Keys of 1 to 15 bytes.** Any other length is treated as a missing key: `store_set()` returns `false`.
  Short prefixes such as `wifi.ssid` or `boot.count` keep keys readable.
- **No exceptions** except `store_keys()`, which throws `store_keys(): cannot list the keys (out of memory or NVS
  error)`. The other functions return `false`, the default or an empty list. Check the result of `store_set()`
  while developing.
- **Committed on return.** There is no flush call: a value is safe the moment `store_set()` returns `true`, and
  a reset or power cut during a write leaves the old or the new value.
- **Which task.** Every flash call runs on the executor task, and the PHP task waits. A call that cannot be
  queued within 1 s returns `false`. `sys_light_sleep()` of ext-sys occupies the executor for its whole
  duration, so a store call made meanwhile waits.
- **No wake source needed.** The store works with `power-save` on.

<!-- @callout variant="warning" title="Flash wears out" -->
A `store_set()` on every `loop()` tick or every request will eventually exhaust the partition. Write only when a
value changes, and keep fast-changing values in RAM.
<!-- @endcallout -->

<!-- @callout variant="danger" title="The store can be wiped at boot" -->
If NVS reports at boot that the partition has no free pages or a newer format, the HAL erases the whole
`phpstore` partition and formats it again: every key is lost. Keep a way to rebuild what you store, such as
defaults or re-provisioning.
<!-- @endcallout -->

Other recipes (settings, factory reset) are in the
[ext-store package](https://repository.php-baremetal.com/packages/php-baremetal/ext-store).

## Files on the microSD card

With `"storage": "microsd"` the firmware mounts the card at `/sdcard`, and PHP's own file functions work on it
with no extension. Your sources are in `/sdcard/app`; the rest of the card is free for the script's own data.
Use a path under `/sdcard`, for example `/sdcard/log.txt`.

`new_examples/03-storage/sd-write` writes a file, reads it back and appends to it. Its `composer.json` requires
only the conductor, on `waveshare-esp32-p4-pico` with `"storage": "microsd"`:

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
$path = '/sdcard/test.txt';
$data = "hello from PHP " . PHP_VERSION . " tick\n";

echo "writing $path ...\n";
$n = file_put_contents($path, $data);
echo "file_put_contents returned: " . var_export($n, true) . "\n";

clearstatcache();
echo "exists: " . (file_exists($path) ? "yes" : "no") . "\n";
echo "size:   " . (file_exists($path) ? filesize($path) : "-") . "\n";

$back = file_get_contents($path);
echo "matches what we wrote: " . ($back === $data ? "YES" : "NO") . "\n";

// append test, to exercise a second open/write
file_put_contents($path, "second line\n", FILE_APPEND);
echo file_get_contents($path);
```
<!-- @endcode-block -->

Boards with a card slot: `waveshare-esp32-s3-eth`, `waveshare-esp32-s3-touch-lcd-1.85b`,
`waveshare-esp32-p4-eth`, `waveshare-esp32-p4-wifi6` and `waveshare-esp32-p4-pico`. Pins and slot types are in
[Storage](../runtime/storage.md).

### Rules on the board

- **Write outside `/sdcard/app`.** That folder is managed by `phpbm sync`, which deletes what is no longer in
  your source directory. The env file is never copied to the card.
- **File names.** The card is FAT: printable ASCII, no trailing `.` or space, none of `\ : " * < > ? |`, and
  names that differ only in case are the same file. Names ending in `.part` or `.old` are refused by
  `phpbm sync`; keep them out of your own files too.
- **Locks do nothing.** `fcntl` locks have no effect on FAT, so do not rely on them between writers.
- **No card, no program.** The firmware stops with `--- stopped: no microSD card ---` when the card cannot be
  mounted, then restarts after `restart-delay`.

## Sessions and SQLite

The `session` and SQLite extensions can use these places. The session `files` handler writes to
`/sdcard/sessions` and needs the `microsd` storage; the default `memory` handler is lost at reset. SQLite
databases go on the card, for example `/sdcard/data.db`. Both are PHP extensions, listed with their limits in
[PHP extensions](./php-extensions.md).

## What survives what

| Data | Reset or power cut | `phpbm flash` | `phpbm sync` |
| --- | --- | --- | --- |
| Variables, `mem_*` values, sessions in memory | Lost | Lost | Lost (the board restarts) |
| ext-store keys | Kept | Kept while the partition table is unchanged (see below) | Kept |
| Files on the card outside `/sdcard/app` | Kept | Kept | Kept |
| Sources of `embedded` storage | Kept | Replaced by the new build | Not applicable |
| Sources in `/sdcard/app` | Kept | Replaced by the copy that follows | Replaced |

`phpbm flash` writes the bootloader, the partition table, the application and, with `embedded` storage, the
sources image. The `phpstore` partition sits after the application in the table, and the application gets what
the sources image and the package partitions leave. A change in the size of the embedded sources can therefore
move `phpstore`, and the store then starts empty. Treat the store as lost after a reflash that changes the
layout, and rebuild what you keep there. With `microsd` storage the layout does not depend on your sources.
See [Memory, flash and partitions](../reference/memory-and-flash.md).
