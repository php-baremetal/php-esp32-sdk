---
eyebrow: 'SDK development'
lede:    'A PHP extension is a JSON descriptor per PHP minor plus a one-file Composer package; a PHP minor is a directory, an sdk.json entry and the patches that port it. The engine itself is never edited.'
see_also:
  - { href: '../reference/php.md', meta: 'Reference' }
  - { href: './adding-a-board.md', meta: 'SDK development' }
  - { href: 'https://github.com/php-baremetal/php-extensions', meta: 'external', label: 'php-extensions (the bm-php-ext packages)' }
prev: { label: 'Adding a board or chip', href: './adding-a-board.md' }
next: { label: 'Writing native packages', href: './firmware-rules.md' }
---

# Adding a PHP extension or minor

The PHP engine is the pristine release from php.net, downloaded and sha256-checked at build time. Everything
target-specific is a patch under `php/<minor>/patches/` or a file under `php/<minor>/compat/`. Adding to the
PHP side of the SDK has two parts: the build recipe of an extension in this repository, and its package in
the `php-extensions` repository.

## Make a PHP extension available

Create `php/<minor>/extensions/<name>.json` for every minor that supports it. The tests require every
minor to offer the same set of extensions, each with `module` equal to its name. Then add the extension's
package (next section).

### The descriptor

The file name is the extension name (`[a-z][a-z0-9_]*`, not a built-in: `core`, `hash`, `json`, `pcre`,
`random`, `reflection`, `spl`, `standard`, and `uri` from 8.5). Unknown keys are an error.

| Key | Required | Meaning |
|---|---|---|
| `module` | yes | The PHP module name (what `extension_loaded()` reports). Unique within a minor, built-ins included. |
| `entry` | no | The C symbol of the `zend_module_entry`; default `<module>_module_entry` (`zlib` sets `php_zlib_module_entry`). `null` for a Zend extension that an SDK patch starts and that registers itself (OPcache on 8.3 and 8.4); it then stays out of the generated module table. |
| `sources` | no | File patterns to compile, relative to the PHP source tree. `*` and `?` work in the last segment only. When omitted, all the code must be in `compat/extensions/<name>/`. |
| `exclude` | no | Patterns removed from `sources`. |
| `include-dirs` | no | Directories added to the include path; no wildcards. |
| `defines` | no | Compile definitions: name to integer or string (no `;` or control characters). Two extensions defining one name differently is an error. |
| `requires` | no | `extensions`: other extensions this one needs (they must exist for the minor, no cycles). `sources`: external sources from `sdk.json` that the paths use. |
| `idf-requires` | no | ESP-IDF components the `php` component requires when the extension is active (`mbedtls`, `espressif__zlib`). |
| `idf-dependencies` | no | ESP-IDF registry components, `namespace/name` to an exact pin, `==x.y.z` or `==x.y.z~n`. |
| `options` | no | Options a project sets in `extra.baremetal.options`; see below. |
| `cost` | no | A sentence on flash and RAM, shown to users. Measure it. |
| `startup-kb` | by test | KB of persistent memory the extension allocates at engine startup. The firmware subtracts it from the memory it hands PHP, so measure it at script start against a build without the extension, rounded up. A test requires it on every extension. |

#### Sources and external sources

A path is relative to the PHP tree: `ext/mbstring/mbstring.c`. A path with a `source:` prefix is relative to an
external source that `sdk.json` declares under `sources` (`oniguruma`, `sqlite`): `sqlite:sqlite3_phpbm.c`.
The source must be listed in the extension's `requires.sources`, and in `sdk.json` with a `url` and a
`sha256`. Patches of a source live in `sources/<name>/patches/`.

Paths are relative without `..`, must not contain `;`, `=` or `"`, and a pattern that matches no file is a
build error (`'sources' pattern '...' matches no file`), as is an include directory that does not exist.

#### Compat files

Port files go in `php/<minor>/compat/extensions/<name>/`: the headers `configure` would have generated, stubs,
and backends. Every `*.c` file there is compiled when the extension is active, and the directory is on the
include path. OPcache is built from `ext/opcache/*.c` minus `shared_alloc_*.c`, plus
`compat/extensions/opcache/shared_alloc_malloc.c`. An extension that compiles no file at all is an SDK bug
the driver reports.

#### Options and cost

An option has a `description`, a required `default`, and either `values` (an enum) or a `type`: `int`
(`min`, `max`), `bool` or `string` (`pattern`). Names are lowercase words joined with
`-` and unique across the SDK, the runtime, the storage and every extension. The value reaches the C code as
`PHPBM_OPTION_<NAME>`.

An enum or bool option may carry `when`: per value, an object with `defines`, `sources`, `exclude`,
`include-dirs` and `startup-kb` that apply when the option has that value. The layers are applied in order,
and a later layer can add back a file an earlier one excluded. `mbstring-cjk` (excludes the CJK encodings when
false) and `mbstring-regex` (adds the oniguruma sources when true) in `php/<minor>/extensions/mbstring.json` are the
examples to copy.

<!-- @code-block language="JSON" label="php/8.4/extensions/zlib.json" -->
```json
{
    "module": "zlib",
    "entry": "php_zlib_module_entry",
    "sources": ["ext/zlib/*.c"],
    "include-dirs": ["ext/zlib"],
    "defines": { "HAVE_ZLIB": 1 },
    "idf-requires": ["espressif__zlib"],
    "idf-dependencies": { "espressif/zlib": "==1.3.2~1" },
    "cost": "about 56 KB with the zlib library, about 25 KB of RAM; deflate needs about 260 KB of memory_limit while it runs",
    "startup-kb": 26
}
```
<!-- @endcode-block -->

#### Patches

If the extension does not build or behave on the target unmodified, add a patch to
`php/<minor>/patches/` for every minor, numbered after the last one (`0014-pdo-sqlite-busy-timeout.patch`
for 8.4). Patches apply in file name order and are tested by number: a new patch changes the counts in
`test_php_releases_with_their_patches()`. Prefer a compile-time switch the patch adds and the descriptor
turns on with `defines` (the `MBSTRING_NO_CJK` and `PHP_EXT_OPCACHE_ENABLED` pattern) over editing behaviour
unconditionally.

<!-- @callout variant="note" title="Test every minor, on a board" -->
`SdkDescriptorsTest` and `PhpExtensionsTest` prove that the descriptors load and resolve. They do not prove
that the extension compiles, links and runs. Build a project that requires the package for 8.3, 8.4 and 8.5
and run it on a board.
<!-- @endcallout -->

### The bm-php-ext package

Each extension has a Composer package, `php-baremetal/php-<name>`, in the
[php-extensions](https://github.com/php-baremetal/php-extensions) monorepo, one folder `php-<name>` per
package. It carries no code: the build recipe is the descriptor above, and the package only names the
extension. Users `composer require` it, and the conductor tells phpbm to build the extension into the firmware.

<!-- @code-block language="JSON" label="php-pdo_sqlite/composer.json" -->
```json
{
    "name": "php-baremetal/php-pdo_sqlite",
    "description": "PHP's pdo_sqlite extension (PDO driver for SQLite databases) for php-baremetal firmware.",
    "type": "bm-php-ext",
    "license": "MIT",
    "require": {
        "php-baremetal/php-pdo": "^1.0"
    },
    "extra": {
        "baremetal": {
            "php-ext": "pdo_sqlite"
        }
    }
}
```
<!-- @endcode-block -->

- `type` is `bm-php-ext`, and `extra.baremetal` may hold only `php-ext`: the descriptor's file name.
- Mirror `requires.extensions` of the descriptor with `require` on the other `php-<name>` packages.
- Add the folder (with `LICENSE` and a `README.md`) and a row in the README table of the monorepo.
- A package is released by pushing the tag `php-<name>/v<semver>` (for example `php-mbstring/v1.0.0`). The package
  version never goes in `composer.json`.

A package works only with an SDK that provides the extension. Document the user-facing side (the options, the cost)
in the package README and in [PHP versions and extensions](../reference/php.md).

## Add a PHP minor or a patch release

A **patch release** (say 8.4.26 to 8.4.27) is an edit of the minor's entry in `sdk.json`:

<!-- @code-block language="JSON" label="sdk.json" -->
```json
"8.4": {
    "version": "8.4.26",
    "url": "https://www.php.net/distributions/php-8.4.26.tar.gz",
    "sha256": "c248abaebc4bb777b80968f0760da0da8e989836e896c9ff637b6775d7d37662",
    "startup-kb": 816
}
```
<!-- @endcode-block -->

`version` must start with the minor and a dot, `sha256` is the 64 hex digits of the tarball, and `startup-kb`
is required by the driver. Then check that every patch of the minor still applies (phpbm applies them to the
fresh tree), that the file lists in `php/<minor>/sources.cmake` still match the tree, update the version
assertions in `SdkDescriptorsTest`, and run a project on a board.

A **new minor** is a new directory, `php/<minor>/`, with the same shape as the others, and an entry under
`php` in `sdk.json`; the loader fails with `PHP <minor> is declared but php/<minor>/ does not exist` when the
directory is missing. Open an issue or a discussion first.

| Part | Content |
|---|---|
| `patches/` | The numbered patches that port the engine to the target. Start from the previous minor's and adapt. |
| `sources.cmake` | The list of PHP source files compiled for the engine (`PHP_SRCS`) and the private include directories (`PHP_PRIV_INCLUDE_DIRS`), included by `host/components/php/CMakeLists.txt`. |
| `compat/` | The generated headers and stubs: `php_config.h`, `zend_config.h`, `build-defs.h`, `timelib_config.h`, `internal_functions.c`, `date_stub.c`, `main/`, and `compat/extensions/<name>/`. A test requires `internal_functions.c`. |
| `extensions/` | The descriptors; the same set as the other minors. |

Add the minor to `SdkDescriptorsTest` (releases, patches, extensions), and test it on a board with the extensions that matter: OPcache, `pdo_sqlite` and `mbstring`.
