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

    public function test_phpbm_header_has_c_linkage_for_cpp_packages(): void
    {
        $header = (string) file_get_contents(__DIR__ . '/../host/components/phpbm/include/phpbm.h');
        self::assertSame(1, preg_match('/#ifdef __cplusplus\nextern "C" \{\n#endif\n(.*)#ifdef __cplusplus\n\}\n#endif\n$/s', $header, $m), 'phpbm.h is not wrapped in extern "C"');
        self::assertStringContainsString('const char *phpbm_source_dir(void);', $m[1]);
    }

    public function test_the_card_is_mounted_for_microsd_code_or_microsd_assets(): void
    {
        $cmake = (string) file_get_contents(__DIR__ . '/../host/cmake/microsd.cmake');
        self::assertStringContainsString('if(PHPBM_STORAGE STREQUAL "microsd" OR PHPBM_ASSETS_MICROSD)', $cmake);
        $phpbm = (string) file_get_contents(__DIR__ . '/../host/components/phpbm/CMakeLists.txt');
        self::assertStringContainsString('include("${PHPBM_SDK_DIR}/host/cmake/microsd.cmake")', $phpbm);
        self::assertSame(1, preg_match('/if\(PHPBM_MICROSD\)\s+list\(APPEND _srcs "microsd.c" "sync.c"\)/', $phpbm), 'the sync server and the card mount build with the card');
    }

    /**
     * A source-text check of the SDK's own files: it compiles and links nothing. ESP-IDF drift is guarded elsewhere:
     * if ESP-IDF moves or renames its function the link fails (a duplicate or an undefined symbol), and if it runs new
     * code before its wait the configure fails (host/cmake/idf-startup.cmake, tested below).
     */
    public function test_the_p4_app_cpu_waits_for_the_scheduler_with_a_pause_between_reads(): void
    {
        // ESP-IDF's esp_startup_start_app_other_cores() is compiled under another name on the ESP32-P4...
        $post = (string) file_get_contents(__DIR__ . '/../host/post-project.cmake');
        self::assertSame(1, preg_match('/if\(CONFIG_IDF_TARGET_ESP32P4 AND NOT CONFIG_FREERTOS_UNICORE\)(.*?)endif\(\)/s', $post, $m), 'no ESP32-P4 multicore block in post-project.cmake');
        self::assertStringContainsString('idf_component_get_property(_freertos_dir freertos COMPONENT_DIR)', $m[1]);
        self::assertStringContainsString('"${_freertos_dir}/app_startup.c"', $m[1]);
        self::assertStringContainsString('esp_startup_start_app_other_cores=phpbm_idf_start_app_other_cores', $m[1]);
        self::assertSame(1, preg_match('/include\("\$\{PHPBM_SDK_DIR\}\/host\/cmake\/idf-startup\.cmake"\)\s+phpbm_check_idf_app_startup\("\$\{_freertos_dir\}\/app_startup\.c"\)\s+(#[^\n]*\s+)?set_property\(SOURCE/', $m[1]), 'ESP-IDF\'s app_startup.c is not checked before it is renamed');

        // ...and the SDK's startup.c, always linked, provides it: a paced poll, then ESP-IDF's function.
        $phpbm = (string) file_get_contents(__DIR__ . '/../host/components/phpbm/CMakeLists.txt');
        self::assertSame(1, preg_match('/set\(_srcs [^)]*"startup\.c"/', $phpbm), 'startup.c is not in the phpbm sources');
        self::assertStringContainsString('-Wl,-u,phpbm_start_app_other_cores', $phpbm);
        $source = (string) @file_get_contents(__DIR__ . '/../host/components/phpbm/startup.c');
        self::assertSame(1, preg_match('/#if CONFIG_IDF_TARGET_ESP32P4 && !CONFIG_FREERTOS_UNICORE\n(.*)#endif/s', $source, $c), 'no ESP32-P4 multicore block in startup.c');
        self::assertSame(1, preg_match('/while \(port_xSchedulerRunning\[0\] == 0\) \{\s+esp_rom_delay_us\(\d+\);\s+\}\s+phpbm_idf_start_app_other_cores\(\);/', $c[1]), 'the poll is not paced before ESP-IDF\'s function');
        self::assertStringContainsString('void esp_startup_start_app_other_cores(void) __attribute__((alias("phpbm_start_app_other_cores")', $c[1]);
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

    public function test_the_configure_fails_when_esp_idf_runs_new_code_before_its_wait_for_the_scheduler(): void
    {
        $known = <<<'C'
            void esp_startup_start_app(void) { }

            void esp_startup_start_app_other_cores(void)
            {
                // For now, we only support up to two core: 0 and 1.
                if (xPortGetCoreID() >= 2) {
                    abort();
                }

                /* Wait for CPU0 to start FreeRTOS before progressing */
                extern volatile unsigned port_xSchedulerRunning[CONFIG_FREERTOS_NUMBER_OF_CORES];
                while (port_xSchedulerRunning[0] == 0) {
                    ;
                }
                xPortStartScheduler();
            }
            C;
        self::assertSame([0, ''], self::check($known), 'the start of ESP-IDF v6.1');

        $ready = str_replace('    extern volatile', "    s_other_cpu_ready = true;\n    extern volatile", $known);
        [$code, $output] = self::check($ready);
        self::assertNotSame(0, $code, 'new code before the wait');
        self::assertStringContainsString('runs new code before its wait', $output);

        [$code, $output] = self::check(str_replace('while (port_xSchedulerRunning[0] == 0)', 'while (!port_xSchedulerRunning[0])', $known));
        self::assertNotSame(0, $code, 'another wait');
        self::assertStringContainsString('no longer waits with', $output);

        [$code, $output] = self::check(str_replace('esp_startup_start_app_other_cores', 'esp_startup_start_other_cores', $known));
        self::assertNotSame(0, $code, 'renamed');
        self::assertStringContainsString('is not defined', $output);

        // the ESP-IDF trees installed here, when there are some
        foreach (glob((getenv('HOME') ?: '/nonexistent') . '/.phpbm/toolchains/esp32/esp-idf-v*/components/freertos/app_startup.c') ?: [] as $file) {
            self::assertSame([0, ''], self::check((string) file_get_contents($file)), $file);
        }
    }

    /** @return array{int, string} cmake -P of phpbm_check_idf_app_startup() on an app_startup.c of the given text */
    private static function check(string $appStartup): array
    {
        $dir = sys_get_temp_dir() . '/phpbm-idf-startup-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/app_startup.c', $appStartup);
        $module = str_replace('\\', '/', (string) realpath(__DIR__ . '/../host/cmake/idf-startup.cmake'));
        $source = str_replace('\\', '/', $dir) . '/app_startup.c';
        file_put_contents($dir . '/check.cmake', "include(\"{$module}\")\nphpbm_check_idf_app_startup(\"{$source}\")\n");
        $process = @proc_open(['cmake', '-P', $dir . '/check.cmake'], [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes);
        if (!is_resource($process)) {
            self::markTestSkipped('no cmake');
        }
        $output = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $code = proc_close($process);
        unlink($dir . '/app_startup.c');
        unlink($dir . '/check.cmake');
        rmdir($dir);
        return [$code, $output];
    }
}
