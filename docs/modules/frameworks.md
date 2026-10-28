---
eyebrow: 'SDK · Modules'
lede:    'Eloquent, Laravel and Symfony run on an ESP32-P4 board with a microSD card: what each example does, the extensions and options it needs, and the limits to expect.'
see_also:
  - { href: './php-extensions.md', meta: 'Modules' }
  - { href: '../runtime/web-server.md', meta: 'Runtime' }
  - { href: '../runtime/storage.md', meta: 'Runtime' }
  - { href: '../reference/memory-and-flash.md', meta: 'Reference' }
prev: { label: 'PHP extensions', href: './php-extensions.md' }
next: { label: 'Options', href: './../reference/options.md' }
---

# PHP frameworks

Real framework code runs on the board: no port, no stripped-down fork. The examples in `09-frameworks/` show how.
All four target an ESP32-P4 board with a microSD card (32 MB of PSRAM), keep the framework in a nested Composer
project inside `project-src`, and run PHP 8.5.11.

| Example | Framework | Runtime | Board | What it does |
| --- | --- | --- | --- | --- |
| `eloquent` | Eloquent (`illuminate/database` 11, no Laravel) | `init-loop` | `waveshare-esp32-p4-pico` | Creates a SQLite database on the card, inserts one row per boot, lists the last five. |
| `laravel-demo` | Laravel | `web-server` | `waveshare-esp32-p4-eth` | The stock Laravel application, browsable over HTTP. |
| `laravel-demo-optimized` | Laravel with OPcache and cached routes | `web-server` | `waveshare-esp32-p4-eth` | The same application, tuned for warm requests. |
| `symfony-demo` | Symfony 7.4 | `web-server` | `waveshare-esp32-p4-eth` | A Symfony application with one controller, served over HTTP. |

## What every framework needs

- **A board with a microSD slot and `storage` set to `microsd`.** A framework's `vendor/` folder is large; it lives
  on the card with the sources ([Storage](../runtime/storage.md)). Of the boards of the SDK, the examples use the
  two P4 boards with `sdio` slots.
- **Plenty of PSRAM.** The P4 boards have 32 MB, which leaves PHP a large `memory_limit`. See
  [Memory, flash and partitions](../reference/memory-and-flash.md).
- **The framework in `project-src`**, installed with its own Composer, as described in
  [PHP extensions](./php-extensions.md):

<!-- @code-block language="bash" label="before phpbm build" -->
```bash
cd project-src
composer install --no-dev -o
cd ..
phpbm flash
```
<!-- @endcode-block -->

- **`phpbm flash` copies the whole `vendor/` to the card**, which takes minutes. After that, `phpbm sync` re-copies
  only what changed ([phpbm commands](https://github.com/php-baremetal/phpbm/blob/master/docs/commands/project.md)).
- **A generous `request-timeout`** for web examples: the cold first request is slow. The Laravel and Symfony
  examples set `"request-timeout": 120`; the default is 30 s.
- **The `entry` option** points a `web-server` project at the framework's front controller:
  `"entry": "public/index.php"`.

The extensions are required in the root `composer.json`, not in `project-src`:

| Extension | eloquent | laravel-demo | laravel-demo-optimized | symfony-demo |
| --- | --- | --- | --- | --- |
| `php-ctype` | yes | yes | yes | yes |
| `php-date` | yes | yes | yes | yes |
| `php-filter` | yes | yes | yes | yes |
| `php-mbstring` | yes | yes | yes | yes |
| `php-pdo`, `php-pdo_sqlite` | yes | yes | yes | no |
| `php-openssl` | no | yes | yes | no |
| `php-session` | no | yes | yes | yes |
| `php-tokenizer` | no | yes | yes | yes |
| `php-opcache` | no | no | yes | yes |

Their options and board caveats are in [PHP extensions](./php-extensions.md).

## Eloquent

Eloquent's database layer without the framework: `illuminate/database` 11 with the `Capsule` manager, a model and
the schema builder. The `project-src/composer.json` requires `illuminate/database` `^11.0` and sets
`config.platform.php` to `8.5.11`.

The options of the project:

<!-- @code-block language="json" label="09-frameworks/eloquent/composer.json, extra.baremetal" -->
```json
{
  "sdk": "esp32@^2.0",
  "board": "waveshare-esp32-p4-pico",
  "runtime": "init-loop",
  "storage": "microsd",
  "options": {
    "mbstring-cjk": false,
    "restart-delay": 3600
  }
}
```
<!-- @endcode-block -->

Three things in the example are board specific:

- **`mb_split()` fallback.** `mbstring-regex` is off, and Eloquent's string helpers call `mb_split()` with simple
  patterns. The example defines `mb_split()` over `preg_split()` when the function does not exist. Set
  `mbstring-regex` to `true` instead to get the oniguruma implementation.
- **UTC time zone.** `date_default_timezone_set('UTC')` keeps Carbon timestamps working without named zones.
- **SQLite on FAT.** The example hands Eloquent a PDO opened with a `file:` URI and `?nolock=1`, with
  `journal_mode = MEMORY` and `synchronous = OFF`, as in the SQLite section of
  [PHP extensions](./php-extensions.md). The database is `/sdcard/eloquent.db`, so rows survive resets.

<!-- @code-block language="php" label="09-frameworks/eloquent/project-src/index.php (excerpt)" -->
```php
<?php
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;

$capsule = new Capsule;
$capsule->addConnection(['driver' => 'sqlite', 'database' => '/sdcard/eloquent.db']);
$capsule->setAsGlobal();
$capsule->bootEloquent();

class Post extends Model
{
    protected $fillable = ['title'];
}

$post = Post::create(['title' => 'boot #' . (Post::count() + 1)]);
echo "inserted post #{$post->id} at {$post->created_at}\n";
```
<!-- @endcode-block -->

## Laravel

`laravel-demo` is a stock Laravel application browsed over HTTP on the Waveshare ESP32-P4-ETH (Ethernet) with the
`web-server` runtime. Its project options:

<!-- @code-block language="json" label="09-frameworks/laravel-demo/composer.json, extra.baremetal" -->
```json
{
  "sdk": "esp32@^2.0",
  "board": "waveshare-esp32-p4-eth",
  "runtime": "web-server",
  "storage": "microsd",
  "entry": "public/index.php",
  "options": {
    "mbstring-regex": true,
    "request-timeout": 120
  }
}
```
<!-- @endcode-block -->

`mbstring-regex` is on because Laravel's string helpers call `mb_split()`. Set up the application
before building:

<!-- @code-block language="bash" label="in project-src" -->
```bash
composer install --no-dev -o
cp .env.example .env
php artisan key:generate
```
<!-- @endcode-block -->

The `.env` of the application is a Laravel file inside `project-src`; it is read by Laravel at run time from the
card, not by phpbm. Keep it out of git when it holds secrets, as the project's `.gitignore` does.

### Tuned for the board

`laravel-demo-optimized` is the same application with a few changes, and adds `php-opcache`. After
`composer install`, run `composer optimize-app` (it runs `php artisan event:cache` and `php artisan route:cache`).

| Change | Why |
| --- | --- |
| `php-opcache` required | Keeps compiled scripts in PSRAM, so warm requests skip parsing. |
| `APP_ENV=production`, `APP_DEBUG=false`, `LOG_CHANNEL=null`, `LOG_LEVEL=error` | Every write to the FAT card is slow, and the board has no useful log sink. |
| `SESSION_DRIVER=array`, `CACHE_STORE=array`, `QUEUE_CONNECTION=sync` | In memory: no card read and write per request. Sessions do not persist across requests, so CSRF-protected forms do not round-trip; use `file` for those. |
| `BCRYPT_ROUNDS=10` | Less work per hash. |
| `Route::view('/', 'welcome')` instead of a closure route | A closure cannot be serialized, which turns off route caching for the whole application. |

`laravel-demo` itself keeps Laravel's defaults: `APP_ENV=local`, `SESSION_DRIVER=database`,
`CACHE_STORE=database`.

## Symfony

`symfony-demo` serves a Symfony 7.4 application (`HelloController`, one route at `/`) over HTTP, with the
`web-server` runtime and `public/index.php` as entry. Its options are `"entry": "public/index.php"` and
`"request-timeout": 120`; it does not need `php-openssl` or the SQLite extensions.

Before building, in `project-src`:

<!-- @code-block language="bash" label="in project-src" -->
```bash
composer install --no-dev -o
cp .env.local.example .env.local
```
<!-- @endcode-block -->

Edit `.env.local`: it sets `APP_ENV=prod`, `APP_DEBUG=0` and an `APP_SECRET` to replace with a random value, for
example `php -r 'echo bin2hex(random_bytes(16));'`.

The nested `composer.json` declares `ext-iconv` and `ext-xml` under `config.platform` because Symfony's packages
require them while the firmware has neither: Composer then resolves the packages as if they were present.

## Limits

- **Speed.** The cold first request is slow, which is why the examples set `request-timeout` to 120 s. The
  `web-server` runtime serves one request at a time. No measured request times are recorded for these examples.
- **No full OpenSSL.** Only AES, digests and random bytes exist; a framework feature that needs keys, signatures or
  TLS does not work.
- **No `intl`, `iconv`, `fileinfo`, `gd` or `curl`.** A package that needs them will not run. `mbstring` covers
  the conversions `iconv` would do.
- **Writes are slow.** Logs, file caches and file sessions go through the FAT card; turn them off or into memory.
- **Storage.** The framework is on the card, not in flash. With `embedded` storage it would have to fit the flash
  image, whose budget is described in [Memory, flash and partitions](../reference/memory-and-flash.md).
- **Network.** The web examples serve over the wired network of the Waveshare ESP32-P4-ETH; see [Network](../runtime/network.md).

## Common errors

| Symptom | Cause |
| --- | --- |
| `Class not found`, `vendor/autoload.php` missing | `composer install --no-dev -o` was not run in `project-src` before flashing. |
| The first request returns 503 | `request-timeout` is too short for the cold request: raise it. |
| `Call to undefined function mb_split()` | `mbstring-regex` is off and the code has no fallback. |
| A named time zone is rejected | `date-timezones` is `utc`. |
| Laravel: `No application encryption key has been specified` | Run `php artisan key:generate` before flashing. |
| The build stops on the flash budget | The sources are in the `embedded` image: use a board with a microSD slot and `storage` `microsd`. |
