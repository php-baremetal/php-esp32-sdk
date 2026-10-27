<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PHPUnit\Framework\TestCase;

use function PhpBaremetal\Esp32Release\localPath;
use function PhpBaremetal\Esp32Release\mtime;
use function PhpBaremetal\Esp32Release\packagedSdkJson;
use function PhpBaremetal\Esp32Release\tarHeader;

final class ReleaseTest extends TestCase
{
    private string $out;

    protected function setUp(): void
    {
        $this->out = sys_get_temp_dir() . '/esp32-release-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->out)) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->out, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    rmdir($file->getPathname());
                } else {
                    @chmod($file->getPathname(), 0666);
                    unlink($file->getPathname());
                }
            }
            rmdir($this->out);
        }
    }

    /** @return array{int,string} */
    private function release(string ...$args): array
    {
        $command = array_map('escapeshellarg', [PHP_BINARY, dirname(__DIR__) . '/tools/release.php', ...$args]);
        exec(implode(' ', $command) . ' 2>&1', $output, $code);
        return [$code, implode("\n", $output)];
    }

    /** @return array<string,string> path => contents */
    private function entries(string $tarball): array
    {
        $entries = [];
        $phar = new \PharData($tarball);
        foreach (new \RecursiveIteratorIterator($phar) as $file) {
            $pathname = str_replace('\\', '/', $file->getPathname());
            $path = substr($pathname, strpos($pathname, '.tar.gz/') + 8);
            $entries[$path] = $file->getContent();
        }
        ksort($entries);
        return $entries;
    }

    public function test_packages_only_what_an_installed_sdk_needs(): void
    {
        [$code, $output] = $this->release('v0.99.0-alpha1', $this->out);
        self::assertSame(0, $code, $output);

        $tarball = "{$this->out}/php-esp32-sdk-0.99.0-alpha1.tar.gz";
        $entries = $this->entries($tarball);
        $roots = array_unique(array_map(static fn (string $p) => explode('/', $p)[0], array_keys($entries)));
        self::assertSame(['php-esp32-sdk-0.99.0-alpha1'], array_values($roots));

        $tops = array_values(array_unique(array_map(static fn (string $p) => explode('/', $p)[1], array_keys($entries))));
        sort($tops);
        self::assertSame(['LICENSE', 'README.md', 'boards', 'chips', 'driver', 'host', 'networks', 'php', 'runtimes', 'sdk.json', 'sources', 'storages'], $tops);
        self::assertArrayHasKey('php-esp32-sdk-0.99.0-alpha1/driver/tools/sync.py', $entries);
        self::assertSame([], array_filter(array_keys($entries), static fn (string $p) => str_contains($p, '__pycache__') || str_ends_with($p, '.pyc')));

        $sdk = json_decode($entries['php-esp32-sdk-0.99.0-alpha1/sdk.json'], true);
        self::assertSame('0.99.0-alpha1', $sdk['version']);
        self::assertNotSame('0.99.0-alpha1', json_decode((string) file_get_contents(dirname(__DIR__) . '/sdk.json'), true)['version']);
        self::assertSame(file_get_contents(dirname(__DIR__) . '/driver/Driver.php'), $entries['php-esp32-sdk-0.99.0-alpha1/driver/Driver.php']);

        $sha = hash_file('sha256', $tarball);
        self::assertSame("{$sha}  php-esp32-sdk-0.99.0-alpha1.tar.gz\n", file_get_contents("{$this->out}/SHA256SUMS"));
        self::assertSame(
            ['name' => 'esp32', 'versions' => ['0.99.0-alpha1' => ['url' => 'php-esp32-sdk-0.99.0-alpha1.tar.gz', 'sha256' => $sha]]],
            json_decode((string) file_get_contents("{$this->out}/index.json"), true),
        );
    }

    public function test_merges_the_previous_index(): void
    {
        mkdir($this->out);
        $previous = "{$this->out}/previous.json";
        file_put_contents($previous, json_encode(['name' => 'esp32', 'versions' => [
            '0.98.0' => ['url' => 'https://example.test/v0.98.0/php-esp32-sdk-0.98.0.tar.gz', 'sha256' => str_repeat('a', 64)],
        ]]));

        [$code, $output] = $this->release('0.99.0', "{$this->out}/dist", '--index-url', 'file://' . $previous, '--base-url', 'https://example.test/v0.99.0/');
        self::assertSame(0, $code, $output);

        $index = json_decode((string) file_get_contents("{$this->out}/dist/index.json"), true);
        self::assertSame(['0.98.0', '0.99.0'], array_keys($index['versions']));
        self::assertSame('https://example.test/v0.99.0/php-esp32-sdk-0.99.0.tar.gz', $index['versions']['0.99.0']['url']);
        self::assertSame(str_repeat('a', 64), $index['versions']['0.98.0']['sha256']);
    }

    public function test_starts_a_new_index_without_a_previous_one(): void
    {
        [$code, $output] = $this->release('0.99.0', $this->out, '--index-url', "{$this->out}/missing.json");
        self::assertSame(0, $code, $output);
        self::assertStringContainsString('starting a new one', $output);
        self::assertCount(1, json_decode((string) file_get_contents("{$this->out}/index.json"), true)['versions']);
    }

    public function test_merges_a_previous_index_served_over_http(): void
    {
        mkdir("{$this->out}/served", 0777, true);
        file_put_contents("{$this->out}/served/index.json", json_encode(['versions' => [
            '0.98.0' => ['url' => 'php-esp32-sdk-0.98.0.tar.gz', 'sha256' => str_repeat('c', 64)],
        ]]));
        $port = random_int(20000, 40000);
        $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', "{$this->out}/served"], [1 => ['file', "{$this->out}/server.log", 'w'], 2 => ['file', "{$this->out}/server.log", 'a']], $pipes);
        try {
            for ($i = 0; $i < 50 && @fsockopen('127.0.0.1', $port) === false; $i++) {
                usleep(100_000);
            }
            [$code, $output] = $this->release('0.99.0', "{$this->out}/a", '--index-url', "http://127.0.0.1:{$port}/index.json");
            self::assertSame(0, $code, $output);
            $index = json_decode((string) file_get_contents("{$this->out}/a/index.json"), true);
            self::assertSame("http://127.0.0.1:{$port}/php-esp32-sdk-0.98.0.tar.gz", $index['versions']['0.98.0']['url']);

            [$code, $output] = $this->release('0.99.0', "{$this->out}/b", '--index-url', "http://127.0.0.1:{$port}/missing.json");
            self::assertSame(0, $code, $output);
            self::assertStringContainsString('(404): starting a new one', $output);
        } finally {
            proc_terminate($server);
            proc_close($server);
        }
    }

    public function test_refuses_to_republish_a_version_with_other_contents(): void
    {
        mkdir($this->out);
        file_put_contents("{$this->out}/index.json", json_encode(['versions' => [
            '0.99.0' => ['url' => 'php-esp32-sdk-0.99.0.tar.gz', 'sha256' => str_repeat('b', 64)],
        ]]));

        [$code, $output] = $this->release('0.99.0', $this->out);
        self::assertSame(1, $code);
        self::assertStringContainsString('version 0.99.0 is already in', $output);
    }

    public function test_rejects_a_version_composer_cannot_parse(): void
    {
        [$code, $output] = $this->release('0.99.0-test', $this->out);
        self::assertSame(1, $code);
        self::assertStringContainsString("'0.99.0-test' is not a release version", $output);
    }

    public function test_makes_relative_urls_of_a_local_previous_index_absolute(): void
    {
        require_once dirname(__DIR__) . '/tools/release.php';
        mkdir("{$this->out}/old mirror", 0777, true);
        $previous = "{$this->out}/old mirror/index.json";
        file_put_contents($previous, json_encode(['versions' => [
            '0.98.0' => ['url' => 'php-esp32-sdk-0.98.0.tar.gz', 'sha256' => str_repeat('a', 64)],
            '0.97.0' => ['url' => 'https://example.test/php-esp32-sdk-0.97.0.tar.gz', 'sha256' => str_repeat('b', 64)],
        ]]));

        [$code, $output] = $this->release('0.99.0', "{$this->out}/dist", '--index-url', 'file://' . str_replace(' ', '%20', $previous));
        self::assertSame(0, $code, $output);
        $versions = json_decode((string) file_get_contents("{$this->out}/dist/index.json"), true)['versions'];
        $url = $versions['0.98.0']['url'];
        self::assertStringStartsWith('file://', $url);
        self::assertSame(realpath("{$this->out}/old mirror") . '/php-esp32-sdk-0.98.0.tar.gz', str_replace('\\', '/', localPath($url)));
        self::assertSame('https://example.test/php-esp32-sdk-0.97.0.tar.gz', $versions['0.97.0']['url']);
        self::assertSame('php-esp32-sdk-0.99.0.tar.gz', $versions['0.99.0']['url']);

        // a previous index in the output directory keeps its relative urls
        [$code, $output] = $this->release('0.99.1', "{$this->out}/dist");
        self::assertSame(0, $code, $output);
        $versions = json_decode((string) file_get_contents("{$this->out}/dist/index.json"), true)['versions'];
        self::assertSame('php-esp32-sdk-0.99.0.tar.gz', $versions['0.99.0']['url']);
    }

    public function test_converts_file_urls_like_phpbm(): void
    {
        require_once dirname(__DIR__) . '/tools/release.php';
        self::assertSame('/a b/index.json', localPath('file:///a%20b/index.json', 'Linux'));
        self::assertSame('/srv/x.json', localPath('file://localhost/srv/x.json', 'Linux'));
        self::assertSame('C:/x/index.json', localPath('file:///C:/x/index.json', 'Windows'));
        self::assertSame('/C:/x/index.json', localPath('file:///C:/x/index.json', 'Linux'));
        self::assertSame('relative/index.json', localPath('relative/index.json'));
    }

    public function test_sets_the_version_whatever_the_indentation(): void
    {
        require_once dirname(__DIR__) . '/tools/release.php';
        mkdir($this->out);
        $file = "{$this->out}/sdk.json";
        foreach ([
            "{\n\t\"name\": \"esp32\",\n\t\"options\": {\"version\": \"keep\"},\n\t\"version\" :  \"0.1.0\"\n}\n",
            '{"name":"esp32","nested":{"version":"keep"},"version":"0.1.0","x":["version","y"]}',
            "{\n  \"version\": \"0.1.0\",\n  \"name\": \"esp32\", \"note\": \"a \\\" quoted } brace\"\n}",
        ] as $json) {
            file_put_contents($file, $json);
            $patched = packagedSdkJson($file, '1.2.3-RC1');
            $expected = json_decode($json, true);
            $expected['version'] = '1.2.3-RC1';
            self::assertSame($expected, json_decode($patched, true));
            self::assertSame(str_replace('"0.1.0"', '"1.2.3-RC1"', $json), $patched);
        }

        file_put_contents($file, '{"name":"esp32","options":{"version":"0.1.0"}}');
        $this->expectExceptionMessage('top-level "version"');
        packagedSdkJson($file, '1.0.0');
    }

    public function test_defaults_the_mtime_to_the_last_commit(): void
    {
        require_once dirname(__DIR__) . '/tools/release.php';
        mkdir($this->out);
        $previous = getenv('SOURCE_DATE_EPOCH');
        putenv('SOURCE_DATE_EPOCH');
        try {
            self::assertSame(0, mtime($this->out));
            exec('git --version 2>&1', $ignored, $code);
            if ($code !== 0) {
                self::markTestSkipped('git is not available');
            }
            $git = static function (string ...$args): void {
                $command = implode(' ', array_map('escapeshellarg', ['git', ...$args]));
                exec($command . ' 2>&1', $output, $code);
                self::assertSame(0, $code, implode("\n", $output));
            };
            $git('-C', $this->out, 'init', '-q');
            file_put_contents("{$this->out}/f", 'x');
            $git('-C', $this->out, 'add', 'f');
            putenv('GIT_COMMITTER_DATE=@1700000000 +0000');
            $git('-C', $this->out, '-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', 'commit', '-q', '-m', 't');
            self::assertSame(1700000000, mtime($this->out));
            putenv('SOURCE_DATE_EPOCH=42');
            self::assertSame(42, mtime($this->out));
        } finally {
            putenv('GIT_COMMITTER_DATE');
            putenv($previous === false ? 'SOURCE_DATE_EPOCH' : "SOURCE_DATE_EPOCH={$previous}");
        }
    }

    public function test_splits_long_paths_into_the_ustar_prefix(): void
    {
        require_once dirname(__DIR__) . '/tools/release.php';
        $path = 'php-esp32-sdk-1.0.0/' . str_repeat('d', 90) . '/' . str_repeat('f', 60) . '.c';
        $header = tarHeader($path, 3, 0644, 0, '0');

        self::assertSame(512, strlen($header));
        self::assertSame(str_repeat('f', 60) . '.c', rtrim(substr($header, 0, 100), "\0"));
        self::assertSame('php-esp32-sdk-1.0.0/' . str_repeat('d', 90), rtrim(substr($header, 345, 155), "\0"));
    }
}
