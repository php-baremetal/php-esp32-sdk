<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32Release;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

// Builds the release assets of the SDK: php-esp32-sdk-<version>.tar.gz, SHA256SUMS and index.json.
// Usage: php tools/release.php <version> <outdir> [--index-url <url|path>] [--base-url <url>]
//   --index-url  previous index.json to merge (default: <outdir>/index.json when it exists); a missing
//                file or an HTTP 404 means "first release"
//   --base-url   where the tarball will be downloaded from (default: next to index.json)

const PACKAGED = ['sdk.json', 'LICENSE', 'README.md', 'driver', 'php', 'chips', 'boards', 'runtimes', 'storages', 'networks', 'host', 'sources', 'schemas'];
const REQUIRED = ['sdk.json', 'driver'];
const UNPACKAGED = ['tests', 'tools', 'vendor', 'composer.json', 'composer.lock', 'phpunit.xml.dist', 'phpunit.xml', 'assets', 'AI_USAGE.md', 'CODE_OF_CONDUCT.md', 'CONTRIBUTING.md', 'SECURITY.md'];
const JUNK = ['__pycache__', '.DS_Store', 'Thumbs.db'];

final class ReleaseError extends RuntimeException
{
}

function main(array $argv): int
{
    $args = [];
    $options = ['index-url' => null, 'base-url' => null];
    for ($i = 1; $i < count($argv); $i++) {
        if (preg_match('/^--(index-url|base-url)(?:=(.*))?$/', $argv[$i], $m) === 1) {
            $options[$m[1]] = $m[2] ?? ($argv[++$i] ?? throw new ReleaseError("--{$m[1]} needs a value"));
            continue;
        }
        if (str_starts_with($argv[$i], '--')) {
            throw new ReleaseError("unknown option {$argv[$i]}");
        }
        $args[] = $argv[$i];
    }
    if (count($args) !== 2) {
        throw new ReleaseError('usage: php tools/release.php <version> <outdir> [--index-url <url|path>] [--base-url <url>]');
    }

    $version = ltrim($args[0], 'v');
    if (preg_match('/^\d+\.\d+\.\d+(?:-(?:alpha|beta|RC)\.?\d*)?$/', $version) !== 1) {
        throw new ReleaseError("'{$args[0]}' is not a release version: use X.Y.Z or X.Y.Z-alpha1/-beta1/-RC1");
    }
    $root = dirname(__DIR__);
    $out = $args[1];
    if (!is_dir($out) && !mkdir($out, 0777, true) && !is_dir($out)) {
        throw new ReleaseError("cannot create {$out}");
    }
    $out = realpath($out);

    $files = collect($root);
    $name = "php-esp32-sdk-{$version}";
    $sdkJson = packagedSdkJson("{$root}/sdk.json", $version);
    validate($root, $files, $sdkJson);

    $tarball = "{$name}.tar.gz";
    $data = gzencode(tar($root, $name, $files, ['sdk.json' => $sdkJson], mtime($root)), 9);
    write("{$out}/{$tarball}", $data);
    $sha256 = hash('sha256', $data);
    write("{$out}/SHA256SUMS", "{$sha256}  {$tarball}\n");

    $previousUrl = $options['index-url'] ?? "{$out}/index.json";
    $versions = previousVersions($previousUrl, $out);
    if (isset($versions[$version]) && strtolower($versions[$version]['sha256']) !== $sha256) {
        throw new ReleaseError("version {$version} is already in {$previousUrl} with another sha256: releases are immutable, use a new version");
    }
    $url = $options['base-url'] === null ? $tarball : rtrim($options['base-url'], '/') . "/{$tarball}";
    $versions[$version] = ['url' => $url, 'sha256' => $sha256];
    uksort($versions, 'version_compare');
    write("{$out}/index.json", json_encode(['name' => 'esp32', 'versions' => $versions], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

    fwrite(STDOUT, sprintf("%s  %s (%d files, %d bytes)\nindex.json: %d version(s)\n", $sha256, $tarball, count($files), strlen($data), count($versions)));
    return 0;
}

/** @return list<string> relative paths of the files to package, sorted */
function collect(string $root): array
{
    foreach (scandir($root) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || in_array($entry, [...PACKAGED, ...UNPACKAGED], true)) {
            continue;
        }
        throw new ReleaseError("unknown top-level entry '{$entry}': add it to PACKAGED or UNPACKAGED in tools/release.php");
    }
    foreach (REQUIRED as $entry) {
        if (!file_exists("{$root}/{$entry}")) {
            throw new ReleaseError("{$entry} is missing");
        }
    }

    $files = [];
    foreach (PACKAGED as $entry) {
        $path = "{$root}/{$entry}";
        if (is_link($path)) {
            throw new ReleaseError("{$entry} is a symlink");
        }
        if (is_file($path)) {
            $files[] = $entry;
            continue;
        }
        if (!is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file) => !in_array($file->getFilename(), JUNK, true)
                && !str_starts_with($file->getFilename(), '.')
                && !str_ends_with($file->getFilename(), '.pyc'),
        ));
        foreach ($iterator as $file) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            if ($file->isLink()) {
                throw new ReleaseError("{$relative} is a symlink");
            }
            $files[] = $relative;
        }
    }
    sort($files, SORT_STRING);
    return $files;
}

function packagedSdkJson(string $file, string $version): string
{
    $json = (string) file_get_contents($file);
    $original = json_decode($json, true);
    $value = topLevelStringValue($json, 'version');
    if (!is_array($original) || ($original['name'] ?? null) !== 'esp32' || $value === null) {
        throw new ReleaseError('cannot set the version in sdk.json: expected a top-level "version" string of the esp32 sdk');
    }
    $patched = substr_replace($json, json_encode($version, JSON_UNESCAPED_SLASHES), $value[0], $value[1]);
    $expected = $original;
    $expected['version'] = $version;
    if (json_decode($patched, true) !== $expected) {
        throw new ReleaseError('cannot set the version in sdk.json: the patched file does not decode to the expected data');
    }
    return $patched;
}

/**
 * Finds the value of a top-level string key without reformatting the file.
 * @return array{int,int}|null offset and length of the value token, quotes included
 */
function topLevelStringValue(string $json, string $key): ?array
{
    $depth = 0;
    $length = strlen($json);
    for ($i = 0; $i < $length; $i++) {
        $char = $json[$i];
        if ($char === '{' || $char === '[') {
            $depth++;
        } elseif ($char === '}' || $char === ']') {
            $depth--;
        } elseif ($char === '"') {
            $end = stringEnd($json, $i);
            if ($depth === 1 && json_decode(substr($json, $i, $end - $i + 1)) === $key
                && preg_match('/\G\s*:\s*/', $json, $m, 0, $end + 1) === 1 && ($json[$end + 1 + strlen($m[0])] ?? '') === '"') {
                $start = $end + 1 + strlen($m[0]);
                return [$start, stringEnd($json, $start) - $start + 1];
            }
            $i = $end;
        }
    }
    return null;
}

function stringEnd(string $json, int $start): int
{
    for ($i = $start + 1; $i < strlen($json); $i++) {
        if ($json[$i] === '\\') {
            $i++;
        } elseif ($json[$i] === '"') {
            return $i;
        }
    }
    throw new ReleaseError('unterminated string in sdk.json');
}

/** Loads the packaged tree with the spec SdkLoader when the dev dependencies are installed. */
function validate(string $root, array $files, string $sdkJson): void
{
    if (!is_file("{$root}/vendor/autoload.php")) {
        fwrite(STDERR, "warning: vendor/ is missing, the packaged sdk is not validated (run composer install)\n");
        return;
    }
    require_once "{$root}/vendor/autoload.php";
    $stage = sys_get_temp_dir() . '/php-esp32-sdk-release-' . bin2hex(random_bytes(4));
    try {
        foreach ($files as $relative) {
            $target = "{$stage}/{$relative}";
            if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true) && !is_dir(dirname($target))) {
                throw new ReleaseError("cannot create {$target}");
            }
            copy("{$root}/{$relative}", $target);
        }
        file_put_contents("{$stage}/sdk.json", $sdkJson);
        $sdk = (new \PhpBaremetal\Spec\Sdk\SdkLoader())->load($stage);
        if (!is_file("{$stage}/driver/Driver.php") || !is_file("{$stage}/driver/tools/sync.py")) {
            throw new ReleaseError('the packaged driver is incomplete');
        }
        fwrite(STDOUT, sprintf("validated: %d boards, %d chips, PHP %s\n", count($sdk->boards), count($sdk->chips), implode('/', array_keys($sdk->descriptor->php))));
    } catch (\PhpBaremetal\Spec\SpecException $e) {
        throw new ReleaseError('the packaged sdk does not load: ' . $e->getMessage());
    } finally {
        removeTree($stage);
    }
}

/** SOURCE_DATE_EPOCH, else the time of the last git commit of the sdk, else 0. */
function mtime(string $root): int
{
    $epoch = getenv('SOURCE_DATE_EPOCH');
    if (is_string($epoch) && ctype_digit($epoch)) {
        return (int) $epoch;
    }
    $process = @proc_open(['git', '-C', $root, 'log', '-1', '--format=%ct'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return 0;
    }
    $output = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    return proc_close($process) === 0 && ctype_digit($output) ? (int) $output : 0;
}

/** @param array<string,string> $override relative path => contents */
function tar(string $root, string $prefix, array $files, array $override, int $mtime): string
{
    $tar = tarHeader("{$prefix}/", 0, 0755, $mtime, '5');
    $dirs = [];
    foreach ($files as $relative) {
        $parts = explode('/', $relative);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            $dir = implode('/', array_slice($parts, 0, $i + 1));
            if (!isset($dirs[$dir])) {
                $dirs[$dir] = true;
                $tar .= tarHeader("{$prefix}/{$dir}/", 0, 0755, $mtime, '5');
            }
        }
        $contents = $override[$relative] ?? (string) file_get_contents("{$root}/{$relative}");
        $mode = is_executable("{$root}/{$relative}") && PHP_OS_FAMILY !== 'Windows' ? 0755 : 0644;
        $tar .= tarHeader("{$prefix}/{$relative}", strlen($contents), $mode, $mtime, '0');
        $tar .= $contents . str_repeat("\0", (512 - strlen($contents) % 512) % 512);
    }
    return $tar . str_repeat("\0", 1024);
}

function tarHeader(string $path, int $size, int $mode, int $mtime, string $type): string
{
    $name = $path;
    $prefix = '';
    if (strlen($path) > 100) {
        $trimmed = rtrim($path, '/');
        $cut = false;
        for ($i = min(155, strlen($trimmed) - 1); $i > 0; $i--) {
            if ($trimmed[$i] === '/' && strlen($path) - $i - 1 <= 100) {
                $cut = $i;
                break;
            }
        }
        if ($cut === false) {
            throw new ReleaseError("path too long for the tarball: {$path}");
        }
        $prefix = substr($path, 0, $cut);
        $name = substr($path, $cut + 1);
    }
    $header = pack(
        'a100a8a8a8a12a12a8a1a100a6a2a32a32a8a8a155a12',
        $name,
        sprintf('%07o', $mode),
        sprintf('%07o', 0),
        sprintf('%07o', 0),
        sprintf('%011o', $size),
        sprintf('%011o', $mtime),
        str_repeat(' ', 8),
        $type,
        '',
        'ustar',
        '00',
        'root',
        'root',
        '',
        '',
        $prefix,
        '',
    );
    $checksum = array_sum(array_map('ord', str_split($header)));
    return substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
}

/** @return array<string, array{url:string, sha256:string}> */
function previousVersions(string $location, string $out): array
{
    $isHttp = preg_match('#^https?://#i', $location) === 1;
    $base = null;
    if (!$isHttp) {
        $path = localPath($location);
        if (!is_file($path)) {
            fwrite(STDOUT, "no previous index at {$location}: starting a new one\n");
            return [];
        }
        $raw = file_get_contents($path);
        $dir = (string) realpath(dirname($path));
        // relative urls stay valid when the new index is written next to the previous one
        $base = $dir === $out ? null : fileUrl($dir) . '/';
    } else {
        $raw = @file_get_contents($location, false, stream_context_create(['http' => ['ignore_errors' => true, 'timeout' => 30]]));
        $status = 0;
        foreach (array_reverse($http_response_header ?? []) as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
                break;
            }
        }
        if ($status === 404) {
            fwrite(STDOUT, "no previous index at {$location} (404): starting a new one\n");
            return [];
        }
        if ($raw === false || $status !== 200) {
            throw new ReleaseError("cannot read the previous index {$location} (HTTP {$status})");
        }
        $base = substr($location, 0, (int) strrpos($location, '/') + 1);
    }

    $data = json_decode((string) $raw, true);
    if (!is_array($data) || !is_array($data['versions'] ?? null)) {
        throw new ReleaseError("invalid previous index {$location}: expected {\"versions\": {...}}");
    }
    $versions = [];
    foreach ($data['versions'] as $version => $entry) {
        if (!is_array($entry) || !is_string($entry['url'] ?? null) || !is_string($entry['sha256'] ?? null)) {
            throw new ReleaseError("invalid previous index {$location}: entry {$version}");
        }
        $url = $entry['url'];
        if ($base !== null && preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\\\\)#i', $url) !== 1) {
            $url = $base . $url;
        }
        $versions[(string) $version] = ['url' => $url, 'sha256' => $entry['sha256']];
    }
    fwrite(STDOUT, sprintf("merging %d version(s) from %s\n", count($versions), $location));
    return $versions;
}

/** Same rules as phpbm's Paths::local(): file:///a%20b, file://localhost/x and file:///C:/x become local paths. */
function localPath(string $url, ?string $osFamily = null): string
{
    if (preg_match('#^file://(?:localhost)?(/.*)$#i', $url, $m) !== 1) {
        return $url;
    }
    $path = rawurldecode($m[1]);
    return ($osFamily ?? PHP_OS_FAMILY) === 'Windows' && preg_match('#^/[A-Za-z]:[/\\\\]#', $path) === 1 ? substr($path, 1) : $path;
}

function fileUrl(string $path): string
{
    $path = str_replace('\\', '/', $path);
    $drive = '';
    if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
        $drive = '/' . substr($path, 0, 2);
        $path = substr($path, 2);
    }
    return 'file://' . $drive . implode('/', array_map('rawurlencode', explode('/', $path)));
}

function write(string $path, string $contents): void
{
    $tmp = $path . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $contents) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new ReleaseError("cannot write {$path}");
    }
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['argv'][0] ?? '')) === __FILE__) {
    try {
        exit(main($_SERVER['argv']));
    } catch (ReleaseError $e) {
        fwrite(STDERR, "release: {$e->getMessage()}\n");
        exit(1);
    }
}
