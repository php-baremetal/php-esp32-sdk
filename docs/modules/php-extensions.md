---
eyebrow: 'SDK · Modules'
lede:    'Optional PHP extensions are added with composer require php-baremetal/php-name and cost flash and PSRAM only when required. How to use the common ones on the board, and how pure-PHP Composer libraries travel inside the firmware.'
see_also:
  - { href: '../reference/php.md', meta: 'Reference' }
  - { href: '../reference/options.md', meta: 'Reference' }
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/php-sqlite3', meta: 'Package', label: 'php-sqlite3' }
prev: { label: 'System and power', href: './system-and-power.md' }
next: { label: 'PHP frameworks', href: './frameworks.md' }
---

# PHP extensions

The firmware runs the real PHP engine. Eight modules are always built in (`core`, `hash`, `json`, `pcre`,
`random`, `reflection`, `spl`, `standard`; PHP 8.5 adds `uri`), with no package to require. Every other extension is
optional: a project that does not require it pays nothing in flash or PSRAM.

## Enable an extension

Require the package named after the extension, then build:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/php-mbstring
composer require php-baremetal/php-pdo php-baremetal/php-pdo_sqlite
```
<!-- @endcode-block -->

- The package name is `php-baremetal/php-<extension>`: `php-baremetal/php-pdo_sqlite` adds `pdo_sqlite`.
- The thirteen optional extensions are `bcmath`, `ctype`, `date`, `filter`, `mbstring`, `opcache`, `openssl`,
  `pdo`, `pdo_sqlite`, `session`, `sqlite3`, `tokenizer` and `zlib`. They exist on PHP 8.3, 8.4 and 8.5. Sizes
  and the versions are in [PHP versions and extensions](../reference/php.md).
- `pdo_sqlite` needs `pdo`. Without it the build stops with
  `php extension 'pdo_sqlite' needs 'pdo': composer require php-baremetal/php-pdo`.
- Some extensions have options in `extra.baremetal.options` (`date-timezones`, `mbstring-cjk`, `mbstring-regex`,
  `opcache-memory`, `opcache-validate`). They are accepted only once the extension is required; see
  [Options](../reference/options.md).
- Extensions are compiled in. There are no `.so` files and no `zend_extension`.
- Not available at all: `intl`, `iconv`, `fileinfo`, `gd`, `curl`, the full `openssl` and TLS.
- Check at run time with `extension_loaded('mbstring')` or `function_exists('mb_strlen')`.

The examples of this page are in `02-extensions/`. Each one is a full project; copy its `composer.json`.

## Memory outside memory_limit

Several extensions allocate from the PSRAM the firmware keeps for itself, not from `memory_limit`: the SQLite page
cache (up to 256 KB per open connection), the `memory` session handler (up to 64 KB), the garbage collector's
buffer. Each extension also takes a fixed share of PSRAM at boot. If a script runs out of memory with a free
`memory_limit`, raise the `firmware-reserve` option. The split is explained in
[Memory, flash and partitions](../reference/memory-and-flash.md).

## SQLite

Packages: `php-baremetal/php-pdo` and `php-baremetal/php-pdo_sqlite` for PDO, `php-baremetal/php-sqlite3` for the
`SQLite3` class. Both embed the SQLite 3.54.1 engine, so with both installed the engine is compiled once.

The example `02-extensions/sqlite` writes one row on each boot into a database on the microSD, so the row survives
resets. It needs a board with a microSD slot and `storage` set to `microsd`; the project uses
`waveshare-esp32-p4-pico`.

<!-- @code-block language="php" label="02-extensions/sqlite/project-src/index.php (excerpt)" -->
```php
<?php
$dbfile = '/sdcard/notes.db';

$pdo = new PDO('sqlite:file:' . $dbfile . '?nolock=1');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA journal_mode = MEMORY');
$pdo->exec('PRAGMA synchronous = OFF');

$pdo->exec('CREATE TABLE IF NOT EXISTS boots (
    id   INTEGER PRIMARY KEY AUTOINCREMENT,
    note TEXT NOT NULL
)');

$pdo->prepare('INSERT INTO boots (note) VALUES (?)')
    ->execute(['booted PHP ' . PHP_VERSION]);

$count = $pdo->query('SELECT COUNT(*) FROM boots')->fetchColumn();
echo "boots recorded so far: $count\n";
```
<!-- @endcode-block -->

On the board:

- Put the database on the card (`/sdcard/...`). With `embedded` storage the sources are in a read-only image, so a
  database cannot live there.
- The card is a FAT file system, which has no `fcntl` locking. SQLite opens databases exclusively, which keeps a
  reset in the middle of a transaction from corrupting the file. The example opens a `file:` URI with `?nolock=1`
  and keeps the journal in RAM (`journal_mode = MEMORY`, `synchronous = OFF`), as a single-process device needs
  no locking.
- The page cache is allocated outside `memory_limit`, up to 256 KB per open connection. Close connections you no
  longer use.
- The `pdo_sqlite` busy timeout is 5 s by default; `PDO::ATTR_TIMEOUT` changes it.

## Sessions

Package: `php-baremetal/php-session`. The default `memory` handler keeps up to 64 sessions, 64 KB in all, in PSRAM;
they are gone after a reset. The `files` handler needs the `microsd` storage and writes to `/sdcard/sessions`.

The example `02-extensions/session` uses the `files` handler on the card, so the visit counter survives resets:

<!-- @code-block language="php" label="02-extensions/session/project-src/index.php (excerpt)" -->
```php
<?php
$dir = '/sdcard/sessions';
@mkdir($dir);
ini_set('session.save_path', $dir);
ini_set('session.use_cookies', '0');   // no HTTP here -- set the id ourselves

session_id('demo');
session_start();
$visits = ($_SESSION['visits'] ?? 0) + 1;
$_SESSION['visits'] = $visits;
session_write_close();
echo "session 'demo': visit #$visits (persisted to $dir)\n";
```
<!-- @endcode-block -->

In the `web-server` runtime the same handlers carry a session across HTTP requests.

## Strings and text

`php-baremetal/php-mbstring` gives the `mb_*` functions. It is the replacement for `iconv`, which is not available:
`mb_convert_encoding()` does the conversions. Two options shape its size:

- `mbstring-cjk` (default `true`): `false` drops the Chinese, Japanese and Korean encodings and saves most of the
  extension's flash. UTF-8, UTF-16, UTF-32, Latin and Cyrillic stay.
- `mbstring-regex` (default `false`): `true` adds `mb_ereg*()` and `mb_split()` with the oniguruma library, about
  445 KB more flash.

<!-- @code-block language="php" label="02-extensions/mbstring/project-src/index.php (excerpt)" -->
```php
<?php
$s = 'Città di Località — こんにちは';

echo "strlen (bytes):    " . strlen($s) . "\n";
echo "mb_strlen (chars): " . mb_strlen($s) . "\n";
echo "mb_strtoupper:     " . mb_strtoupper('città') . "\n";
$latin = mb_convert_encoding('Città', 'ISO-8859-1', 'UTF-8');
echo "mb_str_split('café'): " . implode('|', mb_str_split('café')) . "\n";
```
<!-- @endcode-block -->

The example sets `"mbstring-regex": true` in its `options` and guards the regex part with
`function_exists('mb_ereg')`. `ctype` (`02-extensions/ctype`) adds `ctype_alpha()`, `ctype_digit()` and the other
character-class checks in about 3 KB; `filter` (`02-extensions/filter`) adds `filter_var()` for validating and
sanitizing input:

<!-- @code-block language="php" label="02-extensions/filter/project-src/index.php (excerpt)" -->
```php
<?php
var_dump(filter_var('dev@example.com', FILTER_VALIDATE_EMAIL));
var_dump(filter_var('999.1.1.1', FILTER_VALIDATE_IP));   // bad IP -> false
$v = filter_var('150', FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 100]]);
```
<!-- @endcode-block -->

## Date and time

Without `php-baremetal/php-date` the firmware has a small stand-in: `time()` and a minimal formatting, in the UTC
zone. With it you get `DateTime`, `DateTimeImmutable`, `DateTimeZone`, `DateInterval` and named zones.

`date-timezones` sets how much of the zone database is built: `full` (the default, about 610 KB of flash) knows
every named zone; `utc` (about 255 KB) knows UTC only, and a named zone such as `Europe/Rome` is rejected. The
example `02-extensions/date` uses the full database:

<!-- @code-block language="php" label="02-extensions/date/project-src/index.php (excerpt)" -->
```php
<?php
$utc = new DateTimeImmutable('2026-07-29 20:00:00', new DateTimeZone('UTC'));
foreach (['Europe/Rome', 'America/New_York', 'Asia/Tokyo'] as $zone) {
    $local = $utc->setTimezone(new DateTimeZone($zone));
    printf("  %-18s %s\n", $zone, $local->format('Y-m-d H:i:s T (P)'));
}
$later = $utc->add(new DateInterval('P1Y2M10DT2H30M'));
```
<!-- @endcode-block -->

## Tokenizer

`php-baremetal/php-tokenizer` gives `token_get_all()` and `PhpToken`, the engine's own lexer, which frameworks
lean on. It works in memory and needs no file system (`02-extensions/tokenizer`).

## OpenSSL

`php-baremetal/php-openssl` is a partial API on top of the mbedTLS of ESP-IDF: AES-CBC, AES-CTR and AES-GCM
through `openssl_encrypt()` and `openssl_decrypt()`, digests (`openssl_digest()`) and `openssl_random_pseudo_bytes()`. Only the
`OPENSSL_RAW_DATA`, `OPENSSL_ZERO_PADDING` and `OPENSSL_DONT_ZERO_PAD_KEY` constants exist. There are no keys,
signatures, X.509 or TLS. The output is byte-for-byte the one of desktop OpenSSL, so data encrypted on the board
decrypts elsewhere.

<!-- @code-block language="php" label="02-extensions/openssl-compat/project-src/index.php (excerpt)" -->
```php
<?php
$key = random_bytes(32);                 // a 256-bit key (real hardware RNG)
$iv12 = random_bytes(12);
$gcm  = openssl_encrypt('Secret telemetry', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv12, $tag, '', 16);
$dec2 = openssl_decrypt($gcm, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv12, $tag);
```
<!-- @endcode-block -->

<!-- @callout variant="warning" title="Not a full OpenSSL" -->
A library that needs RSA keys, signatures, certificates or TLS does not work with `php-openssl`. The compact build
covers what a framework's encrypter needs: symmetric ciphers.
<!-- @endcallout -->

## Other extensions

| Extension | Use on the board |
| --- | --- |
| `zlib` | Deflate needs about 260 KB of `memory_limit` while it runs. `gzopen()` and `compress.zlib://` work. |
| `bcmath` | Arbitrary precision arithmetic, about 25 KB. |
| `opcache` | A bytecode cache in PSRAM, no JIT. `opcache-memory` (256 to 16384 KB, default 1024) is taken from `memory_limit`; a board with less than 4 MB of PSRAM disables it at boot. `opcache-validate` is for `microsd` storage. |

## Composer libraries in your project

The extension packages above are installed by the conductor into `.phpbm/packages/` and compiled into the
firmware. A pure-PHP library is different: it is plain PHP, so it travels with your sources. The build and
`phpbm sync` ship the contents of the source directory (`project-src` by default) to the board, and nothing
outside it. The `vendor/` folder next to your root `composer.json` belongs to the conductor and is not shipped.

To use a library such as `illuminate/collections`, give the source directory its own Composer project:

<!-- @code-block language="bash" label="in the project folder" -->
```bash
cd project-src
composer require illuminate/collections
composer install --no-dev -o
cd ..
phpbm flash
```
<!-- @endcode-block -->

The example `01-language/composer-collections` has a `project-src/composer.json`:

<!-- @code-block language="json" label="01-language/composer-collections/project-src/composer.json" -->
```json
{
    "name": "php-baremetal-examples/composer-collections",
    "require": {
        "php": ">=8.2",
        "illuminate/collections": "^11.0"
    },
    "config": {
        "optimize-autoloader": true
    }
}
```
<!-- @endcode-block -->

and its `index.php` loads the autoloader and uses the library:

<!-- @code-block language="php" label="01-language/composer-collections/project-src/index.php (excerpt)" -->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Support\Collection;

$readings = new Collection([
    ['sensor' => 'temp',     'value' => 21.4],
    ['sensor' => 'humidity', 'value' => 48.0],
]);
$averages = $readings->groupBy('sensor')->map(fn (Collection $g) => round($g->avg('value'), 2));
```
<!-- @endcode-block -->

- Run `composer install --no-dev -o` in `project-src` before `phpbm build` or `phpbm flash`. The `vendor/` folder
  is packed with the sources (about 1.1 MB for this example).
- With `embedded` storage the sources and the vendor folder go into the read-only image in flash, so a large
  `vendor/` shrinks the firmware's flash budget; with `microsd` they are copied to the card
  ([Storage](../runtime/storage.md)). Keep `--no-dev` and the optimized autoloader.
- The nested Composer does not see the extensions the firmware has. If it refuses a library for a missing `ext-*`,
  declare the extension under `config.platform` of `project-src/composer.json`, as the Symfony example does
  ([PHP frameworks](./frameworks.md)).
- A library that needs an extension the firmware does not have (`intl`, `curl`, `gd`) will not run.

Without Composer, `require` works as on any PHP. The example `01-language/require-demo` splits a program across
`index.php`, `config.php` and `lib/shapes.php` with `require_once __DIR__ . '/lib/shapes.php'`: the whole source
directory is packed with its layout.

## Common errors

| Symptom | Cause |
| --- | --- |
| `php extension 'pdo_sqlite' needs 'pdo': composer require php-baremetal/php-pdo` | A required extension package is missing. |
| `unknown option 'mbstring-regex' in extra.baremetal.options` | The option belongs to an extension that is not required yet. |
| `Call to undefined function mb_strlen()` | The extension package is not required, or the build did not run again. |
| `Uri\WhatWg\Url` throws an `Error` (PHP 8.5) | The WHATWG parser is not built; `parse_url()` and the RFC 3986 parser work. |
| `mb_split()` undefined with `mbstring` required | `mbstring-regex` is off. |
| A named time zone is rejected | `date-timezones` is `utc`. |
| Out of memory while `memory_limit` is free | SQLite, sessions or another extension use the PSRAM outside `memory_limit`; raise `firmware-reserve`. |
| `Class not found` for a library | `vendor/autoload.php` is missing from `project-src`: run `composer install` there. |
