---
eyebrow: 'SDK development'
lede:    'A release is a tarball, its checksum and a version index, built by tools/release.php. Tags build releases; pushes to the sdk branch build previews until 2.0.0 is out.'
see_also:
  - { href: './working-on-the-sdk.md', meta: 'SDK development' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/sdks.md', meta: 'phpbm', label: 'SDKs and phpbm.lock' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/reference/files-and-environment.md', meta: 'phpbm', label: 'phpbm files and environment' }
prev: { label: 'Writing native packages', href: './firmware-rules.md' }
next: { label: 'No next page', href: '#' }
---

# Releases and previews

Releases are immutable: a published version is never rebuilt with different contents, a fix is a new version.
`tools/release.php` enforces it, and the workflows call it.

## tools/release.php

<!-- @code-block language="Bash" label="usage" -->
```bash
php tools/release.php <version> <outdir> [--index-url <url|path>] [--base-url <url>]
```
<!-- @endcode-block -->

| Argument | Meaning |
|---|---|
| `version` | `X.Y.Z`, or `X.Y.Z-alpha1`, `-beta1`, `-RC1` (a dot before the number is accepted: `2.0.0-alpha.7`). A leading `v` is dropped. Anything else is refused. |
| `outdir` | Where the three assets are written; created if missing. |
| `--index-url` | The previous `index.json` to merge: an http(s) URL, a `file://` URL or a path. Default: `<outdir>/index.json` when it exists. A missing file or an HTTP 404 means a first release; any other failure stops the script. |
| `--base-url` | Where the tarball will be downloaded from; the index entry is `<base-url>/<tarball>`. Default: the bare file name, relative to the index. |

It writes:

| File | Content |
|---|---|
| `php-esp32-sdk-<version>.tar.gz` | The tarball, with everything under one directory, `php-esp32-sdk-<version>/`. |
| `SHA256SUMS` | The sha256 of the tarball, in `sha256sum` format. |
| `index.json` | `{"name": "esp32", "versions": {"<version>": {"url", "sha256"}}}`, sorted by version. |

### What is packaged

The tarball holds a fixed list of top-level entries; the rest of the repository stays out.

| Packaged | Not packaged |
|---|---|
| `sdk.json`, `LICENSE`, `README.md`, `driver`, `php`, `chips`, `boards`, `runtimes`, `storages`, `networks`, `host`, `sources` | `tests`, `tools`, `vendor`, `composer.json`, `composer.lock`, `phpunit.xml.dist`, `assets`, `docs`, `AI_USAGE.md`, `CODE_OF_CONDUCT.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CHANGELOG.md` |

<!-- @callout variant="warning" title="A new top-level entry" -->
Any top-level file or directory that is in neither list (dot entries aside) stops the script with `unknown
top-level entry`. Add it to `PACKAGED` or `UNPACKAGED` in `tools/release.php`. Symbolic links, `__pycache__`,
`.pyc` files and dot files inside packaged directories are never included, and a symlink is an error.
<!-- @endcallout -->

### Version, contents and reproducibility

- `sdk.json` in the repository carries a placeholder version. The script writes the release version into the
  packaged copy only, without reformatting the file, and checks that nothing else changed.
- With `vendor/` installed, it loads the packaged tree with the spec `SdkLoader` and prints
  `validated: <n> boards, <m> chips, PHP <minors>`; a tree that does not load is an error. Without `vendor/`
  it warns and skips the check, so run `composer install` first.
- The tarball is reproducible: files sorted, fixed owner, the modification time from `SOURCE_DATE_EPOCH`, else the
  last commit time, else 0. The CI sets `SOURCE_DATE_EPOCH` to the commit time.

### The index and immutability

The previous index is read and the new version is added to it. If the version is already there with a
different sha256, the script stops with `version <v> is already in <index> with another sha256: releases are
immutable, use a new version`; with the same sha256 the run is idempotent. Relative URLs of a previous index
read from somewhere else (another directory, an URL) are made absolute, so they stay valid in the new file.

## release.yml

`.github/workflows/release.yml` runs when a tag matching `v[0-9]+.[0-9]+.[0-9]+*` is pushed.

1. **tag**: the tag must be semver, `vX.Y.Z` or `vX.Y.Z-alphaN`, `-betaN`, `-RCN`, which Composer and Packagist
   accept; anything else fails the run.
2. **tests**: the `tests.yml` matrix, against the spec tag in `SPEC_REF`.
3. **release**: checks out the SDK and `php-baremetal/spec`, installs PHP 8.4 and the dependencies, then:
   - downloads `index.json` of the latest release (`releases/latest`). Without a release, or with a latest
     release that has no `index.json` (the releases before the SDK, v1.5.0 and older, carry none), it starts a
     new index; any other API error fails the run;
   - runs `tools/release.php` with the tag without its `v`, `--index-url previous/index.json` and
     `--base-url https://github.com/<repository>/releases/download/<tag>`;
   - publishes `php-esp32-sdk-*.tar.gz`, `SHA256SUMS` and `index.json` to the GitHub release of the tag, with
     generated release notes. A tag with a `-` is a prerelease and is not made the latest release; any other
     tag is, so `releases/latest/download/index.json` always lists every stable version.

<!-- @callout variant="note" title="Set SPEC_REF before tagging" -->
`SPEC_REF` in `release.yml` is the `php-baremetal/spec` tag the release is built and tested against. It
appears twice, in the `env` block and as the `spec-ref` of the tests job: set both to the released spec tag
before you tag the SDK.
<!-- @endcallout -->

To release: make sure the tests pass on `master`, set `SPEC_REF`, then tag and push.

<!-- @code-block language="Bash" label="a release" -->
```bash
git tag v2.0.0 && git push origin v2.0.0
```
<!-- @endcode-block -->

## preview.yml

Until 2.0.0 is released, every push to the `sdk` branch (and a manual run) publishes a preview. phpbm reads
these previews by default, so `esp32@^2.0` resolves to them.

- The tests run first, against `SPEC_REF`.
- The version is `2.0.0-alpha.<run number>` of the workflow.
- The previous `index.json` is downloaded from the `sdk-preview` release when it exists, and
  `tools/release.php` merges the new version into it with `--base-url` pointing at
  `releases/download/sdk-preview`: the index lists every preview so far, and each tarball stays downloadable.
- The assets go to the `sdk-preview` pre-release, never the latest one, named `SDK preview (latest <version>)`.
- Runs are serialised (`concurrency: preview`, no cancelling), so two pushes cannot overwrite each other's index.

Once v2.0.0 is out, delete `.github/workflows/preview.yml` and the `sdk-preview` release; the default index of
phpbm moves back to `releases/latest/download/index.json`.

## How phpbm reads the index

phpbm reads `index.json` from the pattern `https://github.com/php-baremetal/php-{name}-sdk/releases/download/sdk-preview/index.json`
(the previews; after 2.0.0, `releases/latest/download/index.json`), with `{name}` the SDK name. It picks the
highest version that satisfies the project's constraint, downloads the `url`, checks the `sha256`, and
unpacks it into `~/.phpbm/sdks/esp32/<version>`. `PHPBM_SDK_INDEX` replaces the pattern, and accepts a local path or a
`file://` URL; a relative `url` in the index is resolved against the index's own location.

### Testing a release locally

Build the assets into a directory, point phpbm at the index there, and install from it.

<!-- @code-block language="Bash" label="a local release" -->
```bash
php tools/release.php 2.0.0-alpha.99 /tmp/sdk-dist
export PHPBM_SDK_INDEX=/tmp/sdk-dist/index.json
phpbm sdk:install esp32@^2.0
cd ../some-project && phpbm build
```
<!-- @endcode-block -->

Pick a version that is not in the public index, or phpbm may prefer the published one: the highest version
wins. Remove the installed copy with `phpbm sdk:remove esp32@2.0.0-alpha.99` when you are done. To try the
working tree without a tarball, use `phpbm sdk:link` instead, see
[Working on the SDK](./working-on-the-sdk.md).
