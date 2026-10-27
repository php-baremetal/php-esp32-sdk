<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\BuildInputs;
use PhpBaremetal\Esp32\Packages;
use PhpBaremetal\Esp32\PackageSpec;
use PhpBaremetal\Esp32\Tests\Support\Plans;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension;
use PhpBaremetal\Spec\SpecException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PackagesTest extends TestCase
{
    private static function fixtures(): string
    {
        return BuildInputs::cmakePath(__DIR__ . '/fixtures/packages');
    }

    private static function spec(string $fixture, array $meta = [], string $type = 'bm-hal', string $name = 'php-baremetal/esp32-x'): PackageSpec
    {
        if ($type === 'bm-hal' && !array_key_exists('platform', $meta)) {
            $meta['platform'] = 'esp32';
        }
        return PackageSpec::fromPackage(new Package($name, $type, '1.0.0', self::fixtures() . "/{$fixture}", $meta), '/project', sdkName: 'esp32');
    }

    public function test_builtin_modules_follow_the_php_minor(): void
    {
        $package = new Package('php-baremetal/x', 'bm-ext', '1.0.0', self::fixtures() . '/hello', ['module' => 'uri']);
        $this->assertSame('uri', PackageSpec::fromPackage($package, '/project', '8.4')->module);
        $date = new Package('php-baremetal/x', 'bm-ext', '1.0.0', self::fixtures() . '/hello', ['module' => 'date']);
        $this->assertSame('date', PackageSpec::fromPackage($date, '/project', '8.4')->module);
        $this->expectExceptionMessage("module 'uri' is a built-in PHP extension");
        PackageSpec::fromPackage($package, '/project', '8.5');
    }

    public function test_defaults(): void
    {
        $f = self::fixtures();
        $hal = self::spec('hal');
        self::assertSame('php-baremetal/esp32-x', $hal->name);
        self::assertSame("{$f}/hal", $hal->dir);
        self::assertNull($hal->module);
        self::assertSame(100, $hal->priority);
        self::assertSame(["{$f}/hal/src/a.c", "{$f}/hal/src/b.c"], $hal->sources);
        self::assertSame(["{$f}/hal/src"], $hal->includeDirs);
        self::assertSame([], $hal->defines);
        self::assertSame([], $hal->idfRequires);
        self::assertSame([], $hal->idfDependencies);
        self::assertNull($hal->sdkconfig);
        self::assertSame([], $hal->partitions);

        $lib = self::spec('lib', [], 'bm-lib');
        self::assertSame(["{$f}/lib/src/lib.c"], $lib->sources);
        self::assertSame(["{$f}/lib/include", "{$f}/lib/src"], $lib->includeDirs);

        $cfg = self::spec('cfg');
        self::assertSame([], $cfg->sources);
        self::assertSame([], $cfg->includeDirs);
    }

    public function test_full_contract(): void
    {
        $f = self::fixtures();
        $spec = self::spec('hal', [
            'sources' => ['src/b.c', 'src/?.c'],
            'include-dirs' => ['./src/'],
            'defines' => ['FOO' => 1, 'BAR' => 'x'],
            'idf-requires' => ['esp_timer', 'esp_timer'],
            'idf-dependencies' => ['espressif/led_strip' => '^3.0.0'],
            'priority' => 5,
            'partitions' => [['name' => 'phpstore', 'subtype' => 'nvs', 'size' => '24K']],
        ]);
        self::assertSame(["{$f}/hal/src/b.c", "{$f}/hal/src/a.c"], $spec->sources);
        self::assertSame(["{$f}/hal/src"], $spec->includeDirs);
        self::assertSame(['FOO' => 1, 'BAR' => 'x'], $spec->defines);
        self::assertSame(['esp_timer'], $spec->idfRequires);
        self::assertSame(['espressif/led_strip' => '^3.0.0'], $spec->idfDependencies);
        self::assertSame(5, $spec->priority);
        self::assertSame([['name' => 'phpstore', 'subtype' => 'nvs', 'kb' => 24]], $spec->partitions);

        self::assertSame("{$f}/cfg/sdkconfig.esp32", self::spec('cfg', ['sdkconfig' => 'sdkconfig.esp32'])->sdkconfig);
        self::assertSame('hello', self::spec('hello', ['module' => 'hello'], 'bm-ext')->module);
    }

    public function test_relative_package_paths_resolve_against_the_project(): void
    {
        $f = self::fixtures();
        $spec = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1', 'packages\\hello', []), dirname($f));
        self::assertSame("{$f}/hello", $spec->dir);
        self::assertSame(["{$f}/hello/src/hello.c"], $spec->sources);
    }

    public function test_windows_paths_use_forward_slashes(): void
    {
        $f = self::fixtures();
        $spec = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1', str_replace('/', '\\', "{$f}/hal"), ['sources' => ['src\\a.c']]), '/project');
        self::assertSame("{$f}/hal", $spec->dir);
        self::assertSame(["{$f}/hal/src/a.c"], $spec->sources);
    }

    /** @return array<string,array{0:string,1:array<string,mixed>,2:string,3:string}> */
    public static function invalid(): array
    {
        return [
            'ext without module' => ['hello', [], 'bm-ext', "package php-baremetal/esp32-x: type bm-ext needs 'module' in extra.baremetal"],
            'module on a hal' => ['hal', ['module' => 'x'], 'bm-hal', "package php-baremetal/esp32-x: 'module' is only allowed for type bm-ext, not bm-hal"],
            'bad module name' => ['hello', ['module' => 'Hello'], 'bm-ext', "package php-baremetal/esp32-x: 'module' must match [a-z_][a-z0-9_]*"],
            'null entry' => ['hello', ['module' => 'hello', 'entry' => null], 'bm-ext', "package php-baremetal/esp32-x: 'entry' must be the C symbol of the zend_module_entry (omit it for hello_module_entry)"],
            'bad entry' => ['hello', ['module' => 'hello', 'entry' => 'a-b'], 'bm-ext', "package php-baremetal/esp32-x: 'entry' must be the C symbol of the zend_module_entry (omit it for hello_module_entry)"],
            'entry on a hal' => ['hal', ['entry' => 'x_module_entry'], 'bm-hal', "package php-baremetal/esp32-x: 'entry' is only allowed for type bm-ext, not bm-hal"],
            'unknown type' => ['hal', [], 'bm-extension', "package php-baremetal/esp32-x: unknown type 'bm-extension' (allowed: bm-ext, bm-hal, bm-lib, bm-php-ext)"],
            'unknown key' => ['hal', ['src-dir' => 'src'], 'bm-hal', "package php-baremetal/esp32-x: unknown key 'src-dir' in extra.baremetal (allowed: defines, entry, idf-dependencies, idf-requires, include-dirs, module, partitions, platform, priority, register, registries, sdkconfig, sources)"],
            'priority not int' => ['hal', ['priority' => '1'], 'bm-hal', "package php-baremetal/esp32-x: 'priority' must be an integer"],
            'glob without matches' => ['hal', ['sources' => ['src/*.cpp']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' pattern 'src/*.cpp' matches no file"],
            'missing file' => ['hal', ['sources' => ['src/c.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' pattern 'src/c.c' matches no file"],
            'glob with ..' => ['hal', ['sources' => ['../hello/src/*.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' must not contain '..': '../hello/src/*.c'"],
            'absolute glob' => ['hal', ['sources' => ['/etc/*.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' must be relative to the package: '/etc/*.c'"],
            'wildcard in a directory' => ['hal', ['sources' => ['*/a.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' pattern '*/a.c' may use wildcards only in its last segment"],
            'brace glob' => ['hal', ['sources' => ['src/{a,b}.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' pattern 'src/{a,b}.c' may only use '*' and '?' wildcards"],
            'sources not a list' => ['hal', ['sources' => 'src/*.c'], 'bm-hal', "package php-baremetal/esp32-x: 'sources' must be a list of strings"],
            'missing include dir' => ['hal', ['include-dirs' => ['include']], 'bm-hal', "package php-baremetal/esp32-x: include dir 'include' not found"],
            'bad define name' => ['hal', ['defines' => ['1X' => 1]], 'bm-hal', "package php-baremetal/esp32-x: invalid define name '1X'"],
            'bool define' => ['hal', ['defines' => ['X' => true]], 'bm-hal', "package php-baremetal/esp32-x: define 'X' must be an integer or a string without ';' or control characters"],
            'defines as list' => ['hal', ['defines' => ['X']], 'bm-hal', "package php-baremetal/esp32-x: 'defines' must be an object"],
            'bad idf require' => ['hal', ['idf-requires' => ['a b']], 'bm-hal', "package php-baremetal/esp32-x: invalid ESP-IDF component name 'a b' in 'idf-requires'"],
            'bad constraint' => ['hal', ['idf-dependencies' => ['espressif/x' => 3]], 'bm-hal', "package php-baremetal/esp32-x: invalid version constraint for 'espressif/x' in 'idf-dependencies'"],
            'missing sdkconfig' => ['cfg', ['sdkconfig' => 'sdkconfig.esp32s3'], 'bm-hal', "package php-baremetal/esp32-x: sdkconfig fragment 'sdkconfig.esp32s3' not found"],
            'partition size' => ['hal', ['partitions' => [['name' => 'p', 'subtype' => 'nvs', 'size' => '6K']]], 'bm-hal', "package php-baremetal/esp32-x: partition 'p': size must be <n>K with n a multiple of 4"],
            'partition size unit' => ['hal', ['partitions' => [['name' => 'p', 'subtype' => 'nvs', 'size' => '1M']]], 'bm-hal', "package php-baremetal/esp32-x: partition 'p': size must be <n>K with n a multiple of 4"],
            'reserved partition' => ['hal', ['partitions' => [['name' => 'storage', 'subtype' => 'fat', 'size' => '64K']]], 'bm-hal', "package php-baremetal/esp32-x: partition name 'storage' is reserved (factory, nvs, phy_init, storage)"],
            'builtin module' => ['hello', ['module' => 'json'], 'bm-ext', "package php-baremetal/esp32-x: module 'json' is a built-in PHP extension"],
            'disallowed subtype' => ['hal', ['partitions' => [['name' => 'p', 'subtype' => 'factory', 'size' => '4K']]], 'bm-hal', "package php-baremetal/esp32-x: partition 'p': subtype 'factory' is not allowed (allowed: nvs, nvs_keys, fat, spiffs, littlefs, coredump, efuse, undefined, phy)"],
            'bad subtype' => ['hal', ['partitions' => [['name' => 'p', 'subtype' => 'nvs2', 'size' => '4K']]], 'bm-hal', "package php-baremetal/esp32-x: partition 'p': subtype 'nvs2' is not allowed (allowed: nvs,"],
            'subtype not a string' => ['hal', ['partitions' => [['name' => 'p', 'subtype' => 1, 'size' => '4K']]], 'bm-hal', "package php-baremetal/esp32-x: partition 'p': 'subtype' must be a string"],
            'registries on a hal' => ['hal', ['registries' => ['r' => ['type' => 't', 'header' => 'x.h']]], 'bm-hal', "package php-baremetal/esp32-x: 'registries' is only allowed for type bm-ext, not bm-hal"],
            'bad registry name' => ['lib', ['module' => 'm', 'registries' => ['R-1' => ['type' => 't', 'header' => 'lib.h']]], 'bm-ext', "package php-baremetal/esp32-x: invalid registry name 'R-1' (use [a-z_][a-z0-9_]*)"],
            'registry keys' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 't']]], 'bm-ext', "package php-baremetal/esp32-x: registry 'r' must have exactly the keys type and header"],
            'registry type' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 'a;b', 'header' => 'lib.h']]], 'bm-ext', "package php-baremetal/esp32-x: registry 'r': invalid C type"],
            'registry const type' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 'const t', 'header' => 'lib.h']]], 'bm-ext', "package php-baremetal/esp32-x: registry 'r': invalid C type 'const t'"],
            'registry struct without tag' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 'struct', 'header' => 'lib.h']]], 'bm-ext', "package php-baremetal/esp32-x: registry 'r': invalid C type 'struct'"],
            'registry header quote' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 't', 'header' => 'li"b.h']]], 'bm-ext', "package php-baremetal/esp32-x: 'registries' must not contain ';', '=' or '\"': 'li\"b.h'"],
            'source semicolon' => ['hal', ['sources' => ['src/a;b.c']], 'bm-hal', "package php-baremetal/esp32-x: 'sources' must not contain ';', '=' or '\"': 'src/a;b.c'"],
            'include dir equals' => ['hal', ['include-dirs' => ['src=x']], 'bm-hal', "package php-baremetal/esp32-x: 'include-dirs' must not contain ';', '=' or '\"': 'src=x'"],
            'idf dependency twice' => ['hal', ['idf-dependencies' => ['led_strip' => '^3', 'Espressif/led_strip' => '^3']], 'bm-hal', "package php-baremetal/esp32-x: 'idf-dependencies' names espressif/led_strip twice"],
            'registry header missing' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 't', 'header' => 'nope.h']]], 'bm-ext', "package php-baremetal/esp32-x: registry 'r': header 'nope.h' not found in the include dirs"],
            'registry header outside' => ['lib', ['module' => 'm', 'registries' => ['r' => ['type' => 't', 'header' => '../x.h']]], 'bm-ext', "package php-baremetal/esp32-x: 'registries' must not contain '..': '../x.h'"],
            'bad register name' => ['hal', ['register' => ['Bad' => ['a']]], 'bm-hal', "package php-baremetal/esp32-x: invalid registry name 'Bad' in 'register' (use [a-z_][a-z0-9_]*)"],
            'bad symbol' => ['hal', ['register' => ['r' => ['a-b']]], 'bm-hal', "package php-baremetal/esp32-x: invalid C symbol 'a-b' in 'register' for 'r'"],
            'register not a list' => ['hal', ['register' => ['r' => 'a']], 'bm-hal', "package php-baremetal/esp32-x: 'register.r' must be a list of strings"],
            'hal without platform' => ['hal', ['platform' => null], 'bm-hal', "package php-baremetal/esp32-x: type bm-hal needs 'platform' in extra.baremetal"],
            'hal platform not a name' => ['hal', ['platform' => 'ESP-32'], 'bm-hal', "package php-baremetal/esp32-x: 'platform' must be the platform name ([a-z][a-z0-9]*)"],
            'hal platform not the sdk' => ['hal', ['platform' => 'stm32'], 'bm-hal', "package php-baremetal/esp32-x: 'platform' is 'stm32' but the project's sdk is 'esp32'"],
            'platform on a lib' => ['lib', ['platform' => 'esp32'], 'bm-lib', "package php-baremetal/esp32-x: 'platform' is only allowed for type bm-hal, not bm-lib"],
            'partition keys' => ['hal', ['partitions' => [['name' => 'p', 'size' => '4K']]], 'bm-hal', 'package php-baremetal/esp32-x: partitions[0] must have exactly the keys name, subtype and size'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_rejects_invalid_contracts(string $fixture, array $meta, string $type, string $message): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage($message);
        self::spec($fixture, $meta, $type);
    }

    public function test_hal_name_must_carry_the_platform_prefix(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package php-baremetal/gpio-hal: the name must start with '<vendor>/esp32-' (the 'platform' prefix)");
        self::spec('hal', ['platform' => 'esp32'], 'bm-hal', 'php-baremetal/gpio-hal');
    }

    public function test_php_extension_packages_carry_only_php_ext(): void
    {
        $spec = self::spec('hal', ['php-ext' => 'mbstring'], 'bm-php-ext', 'php-baremetal/php-mbstring');
        self::assertSame('mbstring', $spec->phpExtension);
        self::assertSame([], $spec->sources);
        self::assertSame([], $spec->includeDirs);
        self::assertNull($spec->module);

        foreach ([
            [['php-ext' => 'mbstring', 'sources' => ['a.c']], "type bm-php-ext allows only 'php-ext' in extra.baremetal, got 'sources'"],
            [['module' => 'mbstring'], "type bm-php-ext allows only 'php-ext' in extra.baremetal, got 'module'"],
            [[], "type bm-php-ext needs 'php-ext' naming a PHP extension"],
            [['php-ext' => 'Mb-String'], "type bm-php-ext needs 'php-ext' naming a PHP extension"],
        ] as [$meta, $message]) {
            try {
                self::spec('hal', $meta, 'bm-php-ext');
                self::fail("accepted: {$message}");
            } catch (SpecException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->expectExceptionMessage("unknown key 'php-ext' in extra.baremetal");
        self::spec('hal', ['php-ext' => 'x'], 'bm-lib');
    }

    public function test_php_extension_packages_stay_out_of_the_packages_component(): void
    {
        $plan = Plans::plan(['packages' => [
            new Package('php-baremetal/php-mbstring', 'bm-php-ext', '1.0.0', self::fixtures() . '/hal', ['php-ext' => 'mbstring']),
            new Package('php-baremetal/hello', 'bm-ext', '1.0.0', self::fixtures() . '/hello', ['module' => 'hello']),
        ]]);
        self::assertSame(['php-baremetal/hello'], array_map(static fn (PackageSpec $s): string => $s->name, Packages::fromPlan($plan)->specs));
    }

    public function test_rejects_a_missing_package_directory(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('package a/b: directory /nowhere/x not found');
        PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1', '/nowhere/x', []), '/project');
    }

    private static function set(): Packages
    {
        return new Packages([
            self::spec('hal', ['defines' => ['FOO' => 1, 'BAR' => 'x'], 'idf-requires' => ['esp_driver_gpio'], 'partitions' => [['name' => 'phpstore', 'subtype' => 'nvs', 'size' => '24K']]], 'bm-hal', 'php-baremetal/esp32-hal'),
            self::spec('hello', ['module' => 'hello', 'idf-dependencies' => ['espressif/led_strip' => '^3.0.0']], 'bm-ext', 'php-baremetal/hello'),
            self::spec('lib', ['module' => 'events', 'priority' => 10, 'idf-requires' => ['esp_timer', 'esp_driver_gpio']], 'bm-ext', 'php-baremetal/lib'),
            self::spec('cfg', ['sdkconfig' => 'sdkconfig.esp32'], 'bm-lib', 'php-baremetal/zcfg'),
        ]);
    }

    public function test_writes_the_component(): void
    {
        $f = self::fixtures();
        $dir = Plans::tempDir() . '/phpbm_packages';
        self::set()->writeComponent($dir);

        self::assertSame(<<<CMAKE
            idf_component_register(
                SRCS
                    "{$f}/hal/src/a.c"
                    "{$f}/hal/src/b.c"
                    "{$f}/hello/src/hello.c"
                    "{$f}/lib/src/lib.c"
                    "modules.c"
                    "registries.c"
                INCLUDE_DIRS
                    "{$f}/hal/src"
                    "{$f}/hello/src"
                    "{$f}/lib/include"
                    "{$f}/lib/src"
                REQUIRES esp_driver_gpio esp_timer php
                WHOLE_ARCHIVE)
            set_source_files_properties(
                "{$f}/hal/src/a.c"
                "{$f}/hal/src/b.c"
                PROPERTIES COMPILE_DEFINITIONS "FOO=1;BAR=x"
                    OBJECT_DEPENDS "\${CMAKE_CURRENT_LIST_DIR}/stamps/php-baremetal__esp32-hal.stamp;\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp")
            set_source_files_properties(
                "{$f}/hello/src/hello.c"
                PROPERTIES OBJECT_DEPENDS "\${CMAKE_CURRENT_LIST_DIR}/stamps/php-baremetal__hello.stamp;\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp")
            set_source_files_properties(
                "{$f}/lib/src/lib.c"
                PROPERTIES OBJECT_DEPENDS "\${CMAKE_CURRENT_LIST_DIR}/stamps/php-baremetal__lib.stamp;\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp")
            set_source_files_properties("modules.c" "registries.c" PROPERTIES OBJECT_DEPENDS "\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp")

            CMAKE, file_get_contents("{$dir}/CMakeLists.txt"));
        self::assertSame(['headers.stamp', 'php-baremetal__esp32-hal.stamp', 'php-baremetal__hello.stamp', 'php-baremetal__lib.stamp'], array_map('basename', glob("{$dir}/stamps/*.stamp")));

        self::assertSame(<<<'C'
            /* Generated by phpbm from the project's packages. */
            #include <stddef.h>
            #include "zend_modules.h"

            extern zend_module_entry events_module_entry;
            extern zend_module_entry hello_module_entry;

            zend_module_entry *const phpbm_package_modules[] = {
                &events_module_entry,
                &hello_module_entry,
            };
            const int phpbm_package_module_count = 2;

            C, file_get_contents("{$dir}/modules.c"));

        self::assertSame("dependencies:\n  espressif/led_strip: \"^3.0.0\"\n", file_get_contents("{$dir}/idf_component.yml"));
    }

    public function test_defines_keep_backslashes_and_are_escaped_for_cmake(): void
    {
        $spec = new PackageSpec('a/b', '/p/b', null, 100, ['/p/b/src/a.c'], [], ['PATHSEP' => 'a\\b"c$d', 'N' => 3], [], [], null, []);
        $cmake = (new Packages([$spec]))->cmakeLists();
        self::assertStringContainsString('PROPERTIES COMPILE_DEFINITIONS "PATHSEP=a\\\\b\\"c\\$d;N=3"' . "\n", $cmake);
    }

    public function test_package_paths_with_dollar_are_escaped(): void
    {
        $spec = new PackageSpec('a/b', '/p/$x', null, 100, ['/p/$x/src/a.c'], ['/p/$x/include'], [], [], [], null, []);
        $cmake = (new Packages([$spec]))->cmakeLists();
        self::assertStringContainsString('"/p/\\$x/src/a.c"', $cmake);
        self::assertStringContainsString("INCLUDE_DIRS\n        \"/p/\\\$x/include\"", $cmake);
    }

    public function test_windows_drive_paths_use_forward_slashes_in_cmake_and_prefix_maps(): void
    {
        $dir = 'C:\\proj\\.phpbm\\packages\\x';
        $spec = new PackageSpec('a/b', $dir, null, 100, ["{$dir}\\src\\a.c"], ["{$dir}\\include"], ['D' => 1], [], [], null, []);
        $set = new Packages([$spec]);
        self::assertStringContainsString('"C:/proj/.phpbm/packages/x/src/a.c"', $set->cmakeLists());
        self::assertStringContainsString('"C:/proj/.phpbm/packages/x/include"', $set->cmakeLists());
        self::assertSame(['C:/proj/.phpbm/packages/x' => 'package/a/b'], $set->prefixMaps());
    }

    public function test_plan_level_views(): void
    {
        $f = self::fixtures();
        $set = self::set();
        self::assertSame(['events', 'hello'], $set->modules());
        self::assertSame([['name' => 'phpstore', 'subtype' => 'nvs', 'kb' => 24]], $set->partitions());
        self::assertSame(["{$f}/cfg/sdkconfig.esp32"], $set->sdkconfigFragments());
        self::assertSame([
            "{$f}/hal" => 'package/php-baremetal/esp32-hal',
            "{$f}/hello" => 'package/php-baremetal/hello',
            "{$f}/lib" => 'package/php-baremetal/lib',
            "{$f}/cfg" => 'package/php-baremetal/zcfg',
        ], $set->prefixMaps());
    }

    public function test_modules_sort_by_priority_then_name(): void
    {
        $set = new Packages([
            self::spec('hello', ['module' => 'zeta', 'priority' => 1], 'bm-ext', 'a/a'),
            self::spec('hello', ['module' => 'beta'], 'bm-ext', 'a/b'),
            self::spec('hello', ['module' => 'alpha'], 'bm-ext', 'a/c'),
            self::spec('hello', ['module' => 'omega', 'priority' => -5], 'bm-ext', 'a/d'),
        ]);
        self::assertSame(['omega', 'zeta', 'alpha', 'beta'], $set->modules());
    }

    public function test_component_without_packages(): void
    {
        $dir = Plans::tempDir() . '/phpbm_packages';
        mkdir($dir);
        file_put_contents("{$dir}/idf_component.yml", "stale\n");
        (new Packages([]))->writeComponent($dir);

        self::assertSame("idf_component_register(\n    SRCS\n        \"modules.c\"\n        \"registries.c\"\n    REQUIRES php\n    WHOLE_ARCHIVE)\n"
            . "set_source_files_properties(\"modules.c\" \"registries.c\" PROPERTIES OBJECT_DEPENDS \"\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp\")\n", file_get_contents("{$dir}/CMakeLists.txt"));
        self::assertSame(<<<'C'
            /* Generated by phpbm from the project's packages. */
            #include <stddef.h>
            #include "zend_modules.h"

            zend_module_entry *const phpbm_package_modules[] = {
                NULL,
            };
            const int phpbm_package_module_count = 0;

            C, file_get_contents("{$dir}/modules.c"));
        self::assertFileDoesNotExist("{$dir}/idf_component.yml");
    }

    public function test_unchanged_component_files_are_not_rewritten(): void
    {
        $dir = Plans::tempDir() . '/phpbm_packages';
        self::set()->writeComponent($dir);
        touch("{$dir}/modules.c", 1_000_000_000);
        clearstatcache();
        self::set()->writeComponent($dir);
        clearstatcache();
        self::assertSame(1_000_000_000, filemtime("{$dir}/modules.c"));
    }

    private static function copyPackage(): string
    {
        $dir = Plans::tempDir() . '/pkg';
        mkdir("{$dir}/src", 0777, true);
        mkdir("{$dir}/include", 0777, true);
        file_put_contents("{$dir}/src/a.c", "int a(void) { return 1; }\n");
        file_put_contents("{$dir}/src/priv.h", "#define P 1\n");
        file_put_contents("{$dir}/include/a.h", "int a(void);\n");
        return $dir;
    }

    private static function stampOf(string $pkg, string $component, string $stamp = 'a__b'): array
    {
        $spec = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1.0.0', $pkg, []), '/project');
        (new Packages([$spec]))->writeComponent($component);
        clearstatcache();
        return [file_get_contents("{$component}/stamps/{$stamp}.stamp"), filemtime("{$component}/stamps/{$stamp}.stamp")];
    }

    public function test_a_package_change_with_an_older_mtime_changes_its_stamp(): void
    {
        $pkg = self::copyPackage();
        $component = Plans::tempDir() . '/phpbm_packages';
        [$hash, ] = self::stampOf($pkg, $component);
        $cmake = file_get_contents("{$component}/CMakeLists.txt");
        self::assertStringContainsString("PROPERTIES OBJECT_DEPENDS \"\${CMAKE_CURRENT_LIST_DIR}/stamps/a__b.stamp;\${CMAKE_CURRENT_LIST_DIR}/stamps/headers.stamp\")", $cmake);
        touch("{$component}/stamps/a__b.stamp", 1_000_000_000);

        [$same, $mtime] = self::stampOf($pkg, $component);
        self::assertSame($hash, $same);
        self::assertSame(1_000_000_000, $mtime, 'an unchanged stamp is not rewritten');

        file_put_contents("{$pkg}/src/a.c", "int a(void) { return 2; }\n");
        touch("{$pkg}/src/a.c", strtotime('2020-01-01'));
        [$changed, $mtime] = self::stampOf($pkg, $component);
        self::assertNotSame($hash, $changed);
        self::assertGreaterThan(1_000_000_000, $mtime);
    }

    public function test_a_private_header_next_to_the_sources_is_part_of_the_stamp(): void
    {
        $pkg = self::copyPackage();
        $component = Plans::tempDir() . '/phpbm_packages';
        $spec = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1.0.0', $pkg, ['include-dirs' => ['include']]), '/project');
        $before = Packages::packageHash($spec);
        file_put_contents("{$pkg}/src/priv.h", "#define P 2\n");
        touch("{$pkg}/src/priv.h", strtotime('2020-01-01'));
        self::assertNotSame($before, Packages::packageHash($spec));
    }

    public function test_a_header_change_moves_the_shared_headers_stamp_and_the_version_moves_the_package_stamp(): void
    {
        $pkg = self::copyPackage();
        $component = Plans::tempDir() . '/phpbm_packages';
        [$headers, ] = self::stampOf($pkg, $component, 'headers');
        file_put_contents("{$pkg}/include/a.h", "int a(int);\n");
        touch("{$pkg}/include/a.h", strtotime('2020-01-01'));
        [$changed, ] = self::stampOf($pkg, $component, 'headers');
        self::assertNotSame($headers, $changed);

        $v1 = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1.0.0', $pkg, []), '/project');
        $v2 = PackageSpec::fromPackage(new Package('a/b', 'bm-lib', '1.0.1', $pkg, []), '/project');
        self::assertNotSame(Packages::packageHash($v1), Packages::packageHash($v2));
    }

    public function test_stamps_of_removed_packages_are_deleted(): void
    {
        $pkg = self::copyPackage();
        $component = Plans::tempDir() . '/phpbm_packages';
        self::stampOf($pkg, $component);
        (new Packages([]))->writeComponent($component);
        self::assertFileDoesNotExist("{$component}/stamps/a__b.stamp");
        self::assertFileExists("{$component}/stamps/headers.stamp");
    }

    public function test_rejects_duplicate_modules(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("packages a/a and a/b both define the PHP module 'hello'");
        new Packages([self::spec('hello', ['module' => 'hello'], 'bm-ext', 'a/a'), self::spec('hello', ['module' => 'hello'], 'bm-ext', 'a/b')]);
    }

    public function test_rejects_a_module_named_like_an_optional_php_extension(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/a: module 'date' is the name of PHP's optional extension date (php-baremetal/php-date): rename it");
        new Packages([self::spec('hello', ['module' => 'date'], 'bm-ext', 'a/a')], [], ['date' => 'date', 'zlib' => 'zlib']);
    }

    public function test_rejects_an_entry_equal_to_the_symbol_of_a_builtin_module(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/a: module 'mine' uses the symbol basic_functions_module, which the built-in extension standard already defines: rename it or set 'entry'");
        new Packages([self::spec('hello', ['module' => 'mine', 'entry' => 'basic_functions_module'], 'bm-ext', 'a/a')]);
    }

    public function test_compares_module_names_not_symbols_with_optional_php_extensions(): void
    {
        // zlib's entry is php_zlib_module_entry: a package module 'php_zlib' gets a clashing symbol but not a clashing name.
        self::assertSame(['zlib_x'], (new Packages([self::spec('hello', ['module' => 'zlib_x'], 'bm-ext', 'a/a')], [], ['zlib' => 'zlib'], ['php_zlib_module_entry']))->modules());
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/a: module 'php_zlib' uses the symbol php_zlib_module_entry, which an active PHP extension already defines: rename it or set 'entry'");
        new Packages([self::spec('hello', ['module' => 'php_zlib'], 'bm-ext', 'a/a')], [], ['zlib' => 'zlib'], ['php_zlib_module_entry']);
    }

    public function test_rejects_a_module_symbol_defined_by_an_active_php_extension(): void
    {
        self::assertSame(['hello'], (new Packages([self::spec('hello', ['module' => 'hello'], 'bm-ext', 'a/a')], [], ['date' => 'date'], ['date_module_entry']))->modules());
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/a: module 'zend_x' uses the symbol zend_x_module_entry, which an active PHP extension already defines: rename it or set 'entry'");
        new Packages([self::spec('hello', ['module' => 'zend_x'], 'bm-ext', 'a/a')], [], [], ['zend_x_module_entry']);
    }

    public function test_an_entry_overrides_the_generated_symbol(): void
    {
        $set = new Packages([
            self::spec('hello', ['module' => 'hello', 'entry' => 'php_hello_module_entry'], 'bm-ext', 'a/a'),
            self::spec('hello', ['module' => 'zz'], 'bm-ext', 'a/b'),
        ], [], [], ['php_zz_module_entry']);
        self::assertSame(['hello', 'zz'], $set->modules());
        self::assertSame(['php_hello_module_entry', 'zz_module_entry'], $set->entries());
        self::assertStringContainsString("extern zend_module_entry php_hello_module_entry;\n", $set->modulesSource());
        self::assertStringContainsString("    &php_hello_module_entry,\n", $set->modulesSource());
    }

    public function test_rejects_two_packages_with_the_same_entry(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("packages a/a and a/b both use the symbol hello_module_entry for their PHP module: set 'entry'");
        new Packages([
            self::spec('hello', ['module' => 'hello'], 'bm-ext', 'a/a'),
            self::spec('hello', ['module' => 'other', 'entry' => 'hello_module_entry'], 'bm-ext', 'a/b'),
        ]);
    }

    public function test_from_plan_rejects_a_module_named_like_an_optional_php_extension_of_the_minor(): void
    {
        $plan = Plans::plan(['packages' => [new Package('a/a', 'bm-ext', '1', self::fixtures() . '/hello', ['module' => 'zlib'])]]);
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("module 'zlib' is the name of PHP's optional extension zlib (php-baremetal/php-zlib)");
        Packages::fromPlan($plan);
    }

    public function test_rejects_duplicate_partitions(): void
    {
        $p = ['partitions' => [['name' => 'data', 'subtype' => 'nvs', 'size' => '4K']]];
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("packages a/esp32-a and a/esp32-b both declare the partition 'data'");
        new Packages([self::spec('hal', $p, 'bm-hal', 'a/esp32-a'), self::spec('hal', $p, 'bm-hal', 'a/esp32-b')]);
    }

    private static function declarer(string $name = 'a/bus'): PackageSpec
    {
        return self::spec('lib', ['module' => str_replace('/', '_', $name), 'registries' => ['bus_drivers' => ['type' => 'bus_driver_t', 'header' => 'lib.h']]], 'bm-ext', $name);
    }

    public function test_generates_registries(): void
    {
        $set = new Packages([
            self::declarer(),
            self::spec('hal', ['register' => ['bus_drivers' => ['z_drv', 'a_drv']]], 'bm-hal', 'a/esp32-zeta'),
            self::spec('hal', ['register' => ['bus_drivers' => ['m_drv']]], 'bm-hal', 'a/esp32-alpha'),
        ]);
        self::assertSame(<<<'C'
            /* Generated by phpbm from the project's packages. */
            #include <stddef.h>

            #include "lib.h"
            extern const bus_driver_t m_drv;
            extern const bus_driver_t a_drv;
            extern const bus_driver_t z_drv;
            const bus_driver_t *const bus_drivers[] = {
                &m_drv,
                &a_drv,
                &z_drv,
                NULL,
            };
            const size_t bus_drivers_count = 3;

            C, $set->registriesSource());
    }

    public function test_registries_are_sorted_and_accept_tagged_types(): void
    {
        $set = new Packages([self::spec('lib', ['module' => 'm', 'registries' => [
            'zz' => ['type' => 'struct zz_entry', 'header' => 'lib.h'],
            'aa' => ['type' => 'unsigned int', 'header' => 'lib.h'],
        ]], 'bm-ext', 'a/bus')]);
        self::assertSame(<<<'C'
            /* Generated by phpbm from the project's packages. */
            #include <stddef.h>

            #include "lib.h"
            const unsigned int *const aa[] = {
                NULL,
            };
            const size_t aa_count = 0;

            #include "lib.h"
            const struct zz_entry *const zz[] = {
                NULL,
            };
            const size_t zz_count = 0;

            C, $set->registriesSource());
    }

    public function test_rejects_a_registry_named_like_another_registrys_count(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/bus: registry 'x_count' collides with 'x_count' generated for the registry 'x'");
        new Packages([self::spec('lib', ['module' => 'm', 'registries' => [
            'x' => ['type' => 't', 'header' => 'lib.h'],
            'x_count' => ['type' => 't', 'header' => 'lib.h'],
        ]], 'bm-ext', 'a/bus')]);
    }

    public function test_rejects_a_symbol_named_like_a_generated_table(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/esp32-one: symbol 'bus_drivers_count' collides with the table generated for the registry 'bus_drivers'");
        new Packages([self::declarer(), self::spec('hal', ['register' => ['bus_drivers' => ['bus_drivers_count']]], 'bm-hal', 'a/esp32-one')]);
    }

    public function test_an_unchanged_registries_file_is_not_rewritten(): void
    {
        $dir = Plans::tempDir() . '/phpbm_packages';
        $set = new Packages([self::declarer()]);
        $set->writeComponent($dir);
        foreach (['CMakeLists.txt', 'modules.c', 'registries.c'] as $file) {
            touch("{$dir}/{$file}", 1000000000);
        }
        clearstatcache();
        $set->writeComponent($dir);
        clearstatcache();
        foreach (['CMakeLists.txt', 'modules.c', 'registries.c'] as $file) {
            self::assertSame(1000000000, filemtime("{$dir}/{$file}"), $file);
        }
        (new Packages([self::declarer('a/other')]))->writeComponent($dir);
        clearstatcache();
        self::assertNotSame(1000000000, filemtime("{$dir}/modules.c"));
        self::assertSame(1000000000, filemtime("{$dir}/registries.c"));
    }

    public function test_idf_dependency_names_are_normalized(): void
    {
        $set = new Packages([
            self::spec('hal', ['idf-dependencies' => ['led_strip' => '^3']], 'bm-hal', 'a/esp32-a'),
            self::spec('hal', ['idf-dependencies' => ['espressif/led_strip' => '^3']], 'bm-hal', 'a/esp32-b'),
        ]);
        self::assertSame(['espressif/led_strip' => '^3'], $set->idfDependencies());
        $this->expectExceptionMessage("packages a/esp32-a and a/esp32-b require the ESP-IDF component espressif/led_strip with different constraints ('^3' and '^4')");
        new Packages([
            self::spec('hal', ['idf-dependencies' => ['led_strip' => '^3']], 'bm-hal', 'a/esp32-a'),
            self::spec('hal', ['idf-dependencies' => ['espressif/led_strip' => '^4']], 'bm-hal', 'a/esp32-b'),
        ]);
    }

    public function test_a_registry_without_entries_is_empty(): void
    {
        self::assertSame(<<<'C'
            /* Generated by phpbm from the project's packages. */
            #include <stddef.h>

            #include "lib.h"
            const bus_driver_t *const bus_drivers[] = {
                NULL,
            };
            const size_t bus_drivers_count = 0;

            C, (new Packages([self::declarer()]))->registriesSource());
        self::assertSame("/* Generated by phpbm from the project's packages. */\n#include <stddef.h>\n", (new Packages([]))->registriesSource());
    }

    public function test_rejects_registering_into_an_undeclared_registry(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/esp32-b: registers into 'nope', which no installed package declares");
        new Packages([self::spec('hal', ['register' => ['nope' => ['x']]], 'bm-hal', 'a/esp32-b')]);
    }

    public function test_rejects_a_registry_declared_twice(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("packages a/a and a/b both declare the registry 'bus_drivers'");
        new Packages([self::declarer('a/a'), self::declarer('a/b')]);
    }

    public function test_rejects_a_duplicate_symbol(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("registry 'bus_drivers': symbol 'x' is registered twice");
        new Packages([
            self::declarer(),
            self::spec('hal', ['register' => ['bus_drivers' => ['x']]], 'bm-hal', 'a/esp32-one'),
            self::spec('hal', ['register' => ['bus_drivers' => ['x']]], 'bm-hal', 'a/esp32-two'),
        ]);
    }

    public function test_rejects_a_partition_declared_twice_in_one_package(): void
    {
        $part = ['name' => 'data', 'subtype' => 'nvs', 'size' => '4K'];
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("package a/esp32-a: partition 'data' is declared twice");
        new Packages([self::spec('hal', ['partitions' => [$part, $part]], 'bm-hal', 'a/esp32-a')]);
    }

    public function test_conflicting_idf_dependencies(): void
    {
        $same = new Packages([
            self::spec('hal', ['idf-dependencies' => ['espressif/x' => '^1']], 'bm-hal', 'a/esp32-a'),
            self::spec('hal', ['idf-dependencies' => ['espressif/x' => '^1']], 'bm-hal', 'a/esp32-b'),
        ]);
        self::assertSame(['espressif/x' => '^1'], $same->idfDependencies());

        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("packages a/esp32-a and a/esp32-b require the ESP-IDF component espressif/x with different constraints ('^1' and '^2')");
        new Packages([
            self::spec('hal', ['idf-dependencies' => ['espressif/x' => '^1']], 'bm-hal', 'a/esp32-a'),
            self::spec('hal', ['idf-dependencies' => ['espressif/x' => '^2']], 'bm-hal', 'a/esp32-b'),
        ]);
    }

    public function test_php_extension_dependencies_go_into_the_manifest(): void
    {
        $set = new Packages([self::spec('hal', ['idf-dependencies' => ['espressif/led_strip' => '==3.1.0~1']], 'bm-hal', 'a/esp32-a')], ['zlib' => ['espressif/zlib' => '==1.3.2~1']]);
        self::assertSame(['espressif/led_strip' => '==3.1.0~1', 'espressif/zlib' => '==1.3.2~1'], $set->idfDependencies());
        self::assertSame("dependencies:\n  espressif/led_strip: \"==3.1.0~1\"\n  espressif/zlib: \"==1.3.2~1\"\n", $set->componentManifest());
        self::assertSame(['php'], $set->idfRequires());
        self::assertSame(['espressif/zlib' => '==1.3.2~1'], (new Packages([self::spec('hal', ['idf-dependencies' => ['espressif/zlib' => '==1.3.2~1']], 'bm-hal', 'a/esp32-a')], ['zlib' => ['espressif/zlib' => '==1.3.2~1']]))->idfDependencies());

        $zlib = PhpExtension::fromArray('8.4', 'zlib', ['module' => 'z', 'sources' => ['a.c'], 'idf-dependencies' => ['espressif/zlib' => '==1.3.2~1']], 'zlib.json');
        $plan = Plans::plan(['phpExtensions' => [$zlib]]);
        self::assertSame(['zlib' => ['espressif/zlib' => '==1.3.2~1']], Packages::fromPlan($plan)->phpExtensionDependencies);
    }

    public function test_a_package_and_a_php_extension_requiring_different_versions_conflict(): void
    {
        $this->expectExceptionMessage("package a/esp32-a and the php extension zlib require the ESP-IDF component espressif/zlib with different constraints ('^1.3' and '==1.3.2~1')");
        new Packages([self::spec('hal', ['idf-dependencies' => ['espressif/zlib' => '^1.3']], 'bm-hal', 'a/esp32-a')], ['zlib' => ['espressif/zlib' => '==1.3.2~1']]);
    }

    public function test_two_php_extensions_requiring_different_versions_conflict(): void
    {
        $this->expectExceptionMessage("the php extension a and the php extension b require the ESP-IDF component espressif/zlib with different constraints ('==1.3.1' and '==1.3.2~1')");
        new Packages([], ['a' => ['espressif/zlib' => '==1.3.1'], 'b' => ['espressif/zlib' => '==1.3.2~1']]);
    }

    public function test_from_plan_sorts_packages_by_name(): void
    {
        $f = self::fixtures();
        $plan = Plans::plan(['packages' => [
            new Package('z/hello', 'bm-ext', '1', "{$f}/hello", ['module' => 'hello']),
            new Package('a/esp32-hal', 'bm-hal', '1', "{$f}/hal", ['platform' => 'esp32']),
        ]]);
        self::assertSame(['a/esp32-hal', 'z/hello'], array_map(static fn (PackageSpec $s): string => $s->name, Packages::fromPlan($plan)->specs));
    }
}
