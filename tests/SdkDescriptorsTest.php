<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Spec\Sdk\Driver;
use PhpBaremetal\Spec\Sdk\Sdk;
use PhpBaremetal\Spec\Sdk\SdkLoader;
use PHPUnit\Framework\TestCase;

final class SdkDescriptorsTest extends TestCase
{
    public static function sdk(): Sdk
    {
        return (new SdkLoader())->load(dirname(__DIR__));
    }

    public function test_sdk_identity(): void
    {
        $descriptor = self::sdk()->descriptor;
        self::assertSame('esp32', $descriptor->name);
        self::assertSame('0.1.0', $descriptor->version);
        self::assertSame(Driver::CONTRACT, $descriptor->contract);
        self::assertSame('PhpBaremetal\\Esp32\\Driver', $descriptor->driverClass);
    }

    public function test_php_releases_with_their_patches(): void
    {
        $sdk = self::sdk();
        self::assertSame(['8.3', '8.4', '8.5'], array_keys($sdk->descriptor->php));
        self::assertSame('8.3.35', $sdk->descriptor->release('8.3')->version);
        self::assertSame('8.4.26', $sdk->descriptor->release('8.4')->version);
        self::assertSame('8.5.11', $sdk->descriptor->release('8.5')->version);
        self::assertCount(13, $sdk->patches('8.3'));
        self::assertCount(14, $sdk->patches('8.4'));
        self::assertCount(15, $sdk->patches('8.5'));
        foreach (['8.3', '8.4', '8.5'] as $minor) {
            self::assertSame('0001-closure-runtime-cache-arena.patch', basename($sdk->patches($minor)[0]), $minor);
            self::assertFileExists($sdk->compatDir($minor) . '/internal_functions.c');
        }
        // 8.3 keeps sigsetjmp behind HAVE_SIGSETJMP upstream, so it has no 0008-zend-portability patch.
        self::assertNotContains('0008-zend-portability-sigsetjmp-guard.patch', array_map('basename', $sdk->patches('8.3')));
        self::assertSame('0014-uri-drop-lexbor-module-dep.patch', basename($sdk->patches('8.5')[13]));
        foreach (['8.3' => '0013', '8.4' => '0014', '8.5' => '0015'] as $minor => $number) {
            self::assertSame("{$number}-pdo-sqlite-busy-timeout.patch", basename($sdk->patches($minor)[(int) $number - 1]), $minor);
        }
        self::assertFileExists($sdk->compatDir('8.5') . '/uri_whatwg_stub.c');
    }

    public function test_every_minor_has_the_same_extensions(): void
    {
        $sdk = self::sdk();
        $names = array_keys($sdk->phpExtensions['8.4']);
        sort($names);
        foreach (['8.3', '8.5'] as $minor) {
            $other = array_keys($sdk->phpExtensions[$minor]);
            sort($other);
            self::assertSame($names, $other, $minor);
        }
        // 8.5 registers OPcache as a module (its ini lookup needs it); 8.3/8.4 accel_startup() registers a static one.
        foreach (['8.3', '8.4', '8.5'] as $minor) {
            self::assertSame('opcache', $sdk->phpExtension($minor, 'opcache')->module);
            foreach ($sdk->phpExtensions[$minor] as $extension) {
                self::assertSame($extension->name, $extension->module, "{$minor} {$extension->name}");
            }
        }
        self::assertNull($sdk->phpExtension('8.3', 'opcache')->entry);
        self::assertNull($sdk->phpExtension('8.4', 'opcache')->entry);
        self::assertSame('opcache_module_entry', $sdk->phpExtension('8.5', 'opcache')->entry);
        // 8.3 still has mbfilter_utf8_mobile.c, which uses the CJK tables.
        $exclude = static fn (string $minor): array => $sdk->phpExtension($minor, 'mbstring')->resolve(['mbstring-cjk' => false])['layers'][1]['exclude'];
        self::assertContains('ext/mbstring/libmbfl/filters/mbfilter_utf8_mobile.c', $exclude('8.3'));
        self::assertNotContains('ext/mbstring/libmbfl/filters/mbfilter_utf8_mobile.c', $exclude('8.5'));
    }

    public function test_every_release_and_extension_declares_its_startup_memory(): void
    {
        $sdk = self::sdk();
        foreach ($sdk->descriptor->php as $minor => $release) {
            self::assertNotNull($release->startupKb, $minor);
            foreach ($sdk->phpExtensions[$minor] as $name => $extension) {
                $file = $sdk->dir . "/php/{$minor}/extensions/{$name}.json";
                self::assertArrayHasKey('startup-kb', json_decode((string) file_get_contents($file), true), "{$minor} {$name}");
            }
        }
        self::assertSame(21, $sdk->phpExtension('8.4', 'mbstring')->resolve(['mbstring-regex' => true])['startupKb']);
    }

    public function test_chips(): void
    {
        $sdk = self::sdk();
        self::assertSame(['esp32p4', 'esp32s3'], array_keys($sdk->chips));
        self::assertSame('esp32s3', $sdk->chip('esp32s3')->target);
        self::assertSame(['quad', 'octal'], $sdk->chip('esp32s3')->psram);
        self::assertSame(['hex'], $sdk->chip('esp32p4')->psram);
        self::assertTrue($sdk->chip('esp32p4')->supports('network', 'emac'));
    }

    public function test_every_board_loads(): void
    {
        $boards = self::sdk()->boards;
        self::assertCount(13, $boards);
        self::assertSame(['microsd' => 'spi', 'network' => 'w5500'], $boards['waveshare-esp32-s3-eth']->capabilities());
        self::assertSame(14, $boards['waveshare-esp32-s3-eth']->peripherals['network']['cs']);
        self::assertSame('octal', $boards['waveshare-esp32-s3-eth']->psram);
        self::assertSame('16MB', $boards['waveshare-esp32-s3-eth']->flash);
        self::assertSame(['microsd' => 'sdio', 'network' => 'emac'], $boards['waveshare-esp32-p4-eth']->capabilities());
        self::assertSame(45, $boards['waveshare-esp32-p4-eth']->peripherals['microsd']['pwr_en']);
        self::assertSame([], $boards['generic-esp32p4']->capabilities());
        foreach (['generic-esp32p4', 'waveshare-esp32-p4-eth', 'waveshare-esp32-p4-pico', 'waveshare-esp32-p4-wifi6'] as $id) {
            self::assertSame('hex', $boards[$id]->psram, $id);
        }
        self::assertSame(['network' => 'wifi'], $boards['generic-esp32s3-quad']->capabilities());
        self::assertSame('4MB', $boards['generic-esp32s3-quad']->flash);
    }

    public function test_the_sdk_declares_the_firmware_options(): void
    {
        $options = self::sdk()->descriptor->options;
        $names = array_keys($options);
        sort($names);
        self::assertSame(['firmware-reserve', 'network', 'on-error', 'optimization', 'power-save', 'request-timeout', 'restart-delay'], $names);
        self::assertSame(['int', 0, 3600, 30], [$options['request-timeout']->type, $options['request-timeout']->min, $options['request-timeout']->max, $options['request-timeout']->default]);
        self::assertSame(['size', 'performance', 'debug'], $options['optimization']->values);
        self::assertSame('size', $options['optimization']->default);
        self::assertSame(['off', 'on'], $options['power-save']->values);
        self::assertSame('off', $options['power-save']->default);
        self::assertSame('enum', $options['on-error']->type);
        self::assertSame('stop', $options['on-error']->default);
        self::assertSame(['stop', 'continue'], $options['on-error']->values);
        self::assertSame('int', $options['restart-delay']->type);
        self::assertSame(5, $options['restart-delay']->default);
        self::assertSame(3600, $options['restart-delay']->max);
        self::assertSame(['off', 'on'], $options['network']->values);
        self::assertSame('off', $options['network']->default);
        self::assertSame([256, 4096, 1024], [$options['firmware-reserve']->min, $options['firmware-reserve']->max, $options['firmware-reserve']->default]);
    }

    public function test_runtime_specific_options(): void
    {
        $sdk = self::sdk();
        self::assertSame(['loop-interval'], array_keys($sdk->runtime('init-loop')->options));
        self::assertSame([], $sdk->runtime('event-driven')->options);
        $web = $sdk->runtime('web-server')->options;
        self::assertSame(['init', 'port'], array_keys($web));
        self::assertSame('', $web['init']->default);
        self::assertSame(80, $web['port']->default);
        self::assertSame('script.php', $web['init']->accept('script.php', 'w'));
        self::assertSame('lib/boot-1.php', $web['init']->accept('lib/boot-1.php', 'w'));
        foreach (['../x.php', '/abs.php', 'x.txt', 'a//b.php'] as $bad) {
            try {
                $web['init']->accept($bad, 'w');
                self::fail("accepted {$bad}");
            } catch (\PhpBaremetal\Spec\SpecException) {
            }
        }
    }

    public function test_no_sdk_runtime_or_storage_option_collides(): void
    {
        $sdk = self::sdk();
        $modules = [...$sdk->runtimes, ...$sdk->storages];
        $seen = array_keys($sdk->descriptor->options);
        foreach ($modules as $module) {
            self::assertSame([], array_intersect($seen, array_keys($module->options)), "{$module->id} collides");
        }
        foreach ($sdk->runtimes as $runtime) {
            foreach ($sdk->storages as $storage) {
                self::assertSame([], array_keys(array_intersect_key($runtime->options, $storage->options)), "{$runtime->id} x {$storage->id}");
            }
        }
    }

    public function test_php_84_extensions(): void
    {
        $sdk = self::sdk();
        $extensions = $sdk->phpExtensions['8.4'];
        ksort($extensions);
        $modules = array_map(static fn ($e): ?string => $e->entry, $extensions);
        self::assertSame([
            'bcmath' => 'bcmath_module_entry',
            'ctype' => 'ctype_module_entry',
            'date' => 'date_module_entry',
            'filter' => 'filter_module_entry',
            'mbstring' => 'mbstring_module_entry',
            'opcache' => null,
            'openssl' => 'openssl_module_entry',
            'pdo' => 'pdo_module_entry',
            'pdo_sqlite' => 'pdo_sqlite_module_entry',
            'session' => 'session_module_entry',
            'sqlite3' => 'sqlite3_module_entry',
            'tokenizer' => 'tokenizer_module_entry',
            'zlib' => 'php_zlib_module_entry',
        ], $modules);
        $date = $sdk->phpExtension('8.4', 'date');
        self::assertSame(['date-timezones'], array_keys($date->options));
        self::assertSame(['full', 'utc'], $date->options['date-timezones']->values);
        self::assertSame('full', $date->options['date-timezones']->default);
        self::assertSame([], $date->resolve(['date-timezones' => 'full'])['defines']);
        self::assertSame(['PHP_EXT_DATE_MINIMAL_TZ' => 1], $date->resolve(['date-timezones' => 'utc'])['defines']);
        self::assertSame(['ext/session/mod_mm.c'], $sdk->phpExtension('8.4', 'session')->exclude);
        $mbstring = $sdk->phpExtension('8.4', 'mbstring');
        self::assertSame(['mbstring-cjk', 'mbstring-regex'], array_keys($mbstring->options));
        self::assertSame(['HAVE_MBSTRING' => 1], $mbstring->resolve([])['defines']);
        self::assertSame([], $mbstring->usedSources([]));
        self::assertSame(['HAVE_MBSTRING' => 1, 'MBSTRING_NO_CJK' => 1], $mbstring->resolve(['mbstring-cjk' => false])['defines']);
        self::assertSame(['oniguruma'], $mbstring->usedSources(['mbstring-regex' => true]));
        self::assertContains('oniguruma:src', $mbstring->resolve(['mbstring-regex' => true])['includeDirs']);
        self::assertArrayHasKey('oniguruma', $sdk->descriptor->sources);
        self::assertSame(['0001-esp32-config-h.patch', '0002-const-unicode-fold-tables.patch'], array_map('basename', $sdk->sourcePatches('oniguruma')));
        self::assertSame(['pdo'], $sdk->phpExtension('8.4', 'pdo_sqlite')->requiresExtensions);
        foreach (['pdo_sqlite', 'sqlite3'] as $name) {
            $sqlite = $sdk->phpExtension('8.4', $name);
            self::assertSame(['sqlite'], $sqlite->usedSources([]));
            self::assertContains('sqlite:sqlite3_phpbm.c', $sqlite->sources);
            self::assertContains('sqlite:.', $sqlite->includeDirs);
        }
        self::assertArrayHasKey('sqlite', $sdk->descriptor->sources);
        self::assertSame(['0001-fatfs-lseek-past-eof.patch', '0002-fatfs-inode-from-path.patch', '0003-phpbm-build-unit.patch', '0004-excl-readonly-reserved-lock.patch'], array_map('basename', $sdk->sourcePatches('sqlite')));
        $opcache = $sdk->phpExtension('8.4', 'opcache');
        self::assertSame(['opcache-memory', 'opcache-validate'], array_keys($opcache->options));
        self::assertSame(1024, $opcache->options['opcache-memory']->default);
        self::assertFalse($opcache->options['opcache-validate']->default);
        self::assertSame(['PHP_EXT_OPCACHE_ENABLED' => 1, 'USE_MALLOC_SHM' => 1], $opcache->resolve([])['defines']);
        $openssl = $sdk->phpExtension('8.4', 'openssl');
        self::assertSame([], $openssl->sources);
        self::assertSame(['mbedtls'], $openssl->idfRequires);
        self::assertFileExists($sdk->compatDir('8.4') . '/extensions/openssl/openssl_compat.c');
        $zlib = $sdk->phpExtension('8.4', 'zlib');
        self::assertSame(['espressif__zlib'], $zlib->idfRequires);
        self::assertSame(['espressif/zlib' => '==1.3.2~1'], $zlib->idfDependencies);
        self::assertSame('0013-zlib-gzopen-without-dup.patch', basename($sdk->patches('8.4')[12]));
        $declared = array_keys($sdk->descriptor->options);
        foreach ([...$sdk->runtimes, ...$sdk->storages] as $module) {
            $declared = [...$declared, ...array_keys($module->options)];
        }
        foreach ($extensions as $extension) {
            self::assertSame([], array_intersect($declared, array_keys($extension->options)), $extension->name);
        }
    }

    public function test_modules(): void
    {
        $sdk = self::sdk();
        self::assertSame(['event-driven', 'init-loop', 'web-server'], array_keys($sdk->runtimes));
        self::assertSame(['network'], $sdk->runtime('web-server')->requires->capabilities);
        self::assertSame(['php-baremetal/ext-events'], $sdk->runtime('event-driven')->requires->packages);
        self::assertSame(['embedded', 'microsd'], array_keys($sdk->storages));
        self::assertSame(['microsd'], $sdk->storage('microsd')->requires->capabilities);
    }
}
