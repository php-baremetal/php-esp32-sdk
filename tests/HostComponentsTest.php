<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PHPUnit\Framework\TestCase;

final class HostComponentsTest extends TestCase
{
    public function test_php_component_compiles_without_strict_aliasing(): void
    {
        $cmake = (string) file_get_contents(__DIR__ . '/../host/components/php/CMakeLists.txt');
        self::assertSame(1, preg_match('/target_compile_options\(\$\{COMPONENT_LIB\}\s+PRIVATE\s+([^)]*)\)/', $cmake, $m), 'php component compile options not found');
        self::assertContains('-fno-strict-aliasing', preg_split('/\s+/', trim($m[1])));
    }

    public function test_the_card_is_mounted_for_microsd_code_or_microsd_assets(): void
    {
        $cmake = (string) file_get_contents(__DIR__ . '/../host/cmake/microsd.cmake');
        self::assertStringContainsString('if(PHPBM_STORAGE STREQUAL "microsd" OR PHPBM_ASSETS_MICROSD)', $cmake);
        $phpbm = (string) file_get_contents(__DIR__ . '/../host/components/phpbm/CMakeLists.txt');
        self::assertStringContainsString('include("${PHPBM_SDK_DIR}/host/cmake/microsd.cmake")', $phpbm);
        self::assertSame(1, preg_match('/if\(PHPBM_MICROSD\)\s+list\(APPEND _srcs "microsd.c" "sync.c"\)/', $phpbm), 'the sync server and the card mount build with the card');
    }

    public function test_the_embedded_storage_mounts_the_card_for_microsd_assets(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../storages/embedded/embedded.c');
        self::assertSame(1, preg_match('/#if PHPBM_ASSETS_MICROSD\n(.*?)#endif/s', $source, $m), 'no PHPBM_ASSETS_MICROSD block in embedded.c');
        self::assertStringContainsString('phpbm_microsd_mount()', $source);
        self::assertStringContainsString('phpbm_sync(', $source);
        $microsd = (string) file_get_contents(__DIR__ . '/../storages/microsd/microsd.c');
        self::assertStringContainsString('phpbm_microsd_mount()', $microsd);
        self::assertStringNotContainsString('esp_vfs_fat_sdspi_mount', $microsd, 'the mount lives in the host component, shared by both storages');
    }
}
