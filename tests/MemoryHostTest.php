<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PHPUnit\Framework\TestCase;

/**
 * host/components/phpbm/memory.c compiled for the PC (tests/fixtures/memory-host: stub ESP-IDF and Zend headers)
 * and run over a fake PSRAM full of random bytes; then under the real Zend MM (tests/fixtures/memory-zend).
 */
final class MemoryHostTest extends TestCase
{
    public function test_chunks_reach_the_zend_mm_with_a_zeroed_header(): void
    {
        $cc = self::compiler();
        $root = dirname(__DIR__);
        $fixture = __DIR__ . '/fixtures/memory-host';
        $bin = sys_get_temp_dir() . '/phpbm-memory-host-' . getmypid();
        $build = [$cc, '-std=c11', '-Wall', '-Wextra', '-Werror', '-I', "{$fixture}/stubs", '-I', "{$root}/host/components/phpbm/include",
            '-o', $bin, "{$fixture}/memory_test.c", "{$root}/host/components/phpbm/memory.c"];
        try {
            [$code, $output] = self::exec($build);
            self::assertSame(0, $code, "build failed:\n{$output}");
            [$code, $output] = self::exec([$bin]);
            self::assertSame("OK\n", str_replace("\r\n", "\n", $output));
            self::assertSame(0, $code);
        } finally {
            @unlink($bin);
        }
    }

    /**
     * The real Zend MM (the SDK's patched zend_alloc.c) over memory.c and a PSRAM of random bytes: no two live blocks
     * of 200 000 emalloc()/efree() overlap (tests/fixtures/memory-zend). Without the zeroed chunk header the MM hands
     * out pages in use within a few thousand steps. Needs a patched PHP source tree (PHPBM_TEST_PHP_SRC, or the
     * newest one phpbm unpacked in its cache) and the system PHP's php_config.h (php-config); skipped otherwise.
     */
    public function test_the_real_zend_mm_never_hands_out_a_page_in_use(): void
    {
        $cc = self::compiler();
        $tree = self::phpTree();
        [$code, $inc] = self::exec(['php-config', '--include-dir']);
        $config = trim($inc) . '/main/php_config.h';
        if ($code !== 0 || !is_file($config)) {
            self::markTestSkipped('no php_config.h (php-config of a PHP with its development headers)');
        }
        $root = dirname(__DIR__);
        $fixture = __DIR__ . '/fixtures/memory-zend';
        $dir = sys_get_temp_dir() . '/phpbm-memory-zend-' . getmypid();
        @mkdir("{$dir}/main", 0777, true);
        copy($config, "{$dir}/main/php_config.h");
        $bin = "{$dir}/mm_zend_test";
        // 512 KB chunks on a 64-bit PC: 128 pages, a page bitmap of two words, as 256 KB chunks on the 32-bit board
        $build = [$cc, '-std=gnu11', '-O1', '-w', '-D_GNU_SOURCE', '-DZEND_MM_CHUNK_SIZE=((size_t)(512*1024))',
            '-I', "{$fixture}/stubs", '-I', $dir, '-I', "{$dir}/main", '-I', "{$root}/host/components/phpbm/include",
            '-I', "{$tree}/Zend", '-I', "{$tree}/main", '-I', "{$tree}/TSRM", '-I', $tree,
            '-o', $bin, "{$fixture}/mm_zend_test.c", "{$fixture}/zend_stubs.c", "{$fixture}/zend_report_stubs.c", "{$tree}/Zend/zend_alloc.c",
            "{$root}/host/components/phpbm/memory.c"];
        try {
            [$code, $output] = self::exec($build);
            self::assertSame(0, $code, "build failed ({$tree}):\n{$output}");
            foreach ([1, 2, 3] as $seed) {
                [$code, $output] = self::exec([$bin, (string) $seed]);
                self::assertSame("OK\n", str_replace("\r\n", "\n", $output), "seed {$seed}");
                self::assertSame(0, $code);
            }
        } finally {
            @unlink($bin);
            @unlink("{$dir}/main/php_config.h");
            @rmdir("{$dir}/main");
            @rmdir($dir);
        }
    }

    /** A PHP source tree with the SDK's zend-mm-platform-storage patch applied, or the test is skipped. */
    private static function phpTree(): string
    {
        $env = getenv('PHPBM_TEST_PHP_SRC');
        if (is_string($env) && $env !== '') {
            return rtrim($env, '/');
        }
        $home = getenv('PHPBM_HOME') ?: (getenv('HOME') ?: '') . '/.phpbm';
        $best = null;
        foreach (glob($home . '/cache/trees/php-*', GLOB_ONLYDIR) ?: [] as $dir) {
            $alloc = @file_get_contents($dir . '/Zend/zend_alloc.c');
            if (!is_file($dir . '.complete') || $alloc === false || !str_contains($alloc, 'zend_mm_platform_handlers')
                || !preg_match('/php-(\d+\.\d+\.\d+)-/', basename($dir) . '-', $m)) {
                continue;
            }
            if ($best === null || version_compare($m[1], $best[0], '>')) {
                $best = [$m[1], $dir];
            }
        }
        if ($best === null) {
            self::markTestSkipped('no patched PHP source tree (set PHPBM_TEST_PHP_SRC, or build a firmware once)');
        }
        return $best[1];
    }

    private static function compiler(): string
    {
        foreach (['cc', 'gcc', 'clang'] as $cc) {
            [$code] = self::exec([$cc, '--version']);
            if ($code === 0) {
                return $cc;
            }
        }
        self::markTestSkipped('no C compiler (cc, gcc or clang)');
    }

    /**
     * @param list<string> $command
     * @return array{int, string}
     */
    private static function exec(array $command): array
    {
        $process = @proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            return [127, ''];
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        return [proc_close($process), $output];
    }
}
