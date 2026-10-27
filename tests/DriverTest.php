<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\BuildInputs;
use PhpBaremetal\Esp32\Driver;
use PhpBaremetal\Esp32\SerialPorts;
use PhpBaremetal\Esp32\Tests\Support\FakeIdf;
use PhpBaremetal\Esp32\Tests\Support\Plans;
use PhpBaremetal\Esp32\Tests\Support\RecordingProcess;
use PhpBaremetal\Spec\Sdk\Artifacts;
use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Port;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\SpecException;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PhpBaremetal\Spec\Testing\FakeProcess;
use PHPUnit\Framework\TestCase;

final class DriverTest extends TestCase
{
    private string $toolchainDir;
    private string $idf;
    private string $venv;
    private string $python;

    protected function setUp(): void
    {
        $this->toolchainDir = Plans::tempDir();
        $this->idf = $this->toolchainDir . '/esp-idf-v6.1';
        $this->venv = FakeIdf::install($this->idf, $this->toolchainDir . '/tools');
        $this->python = $this->venv . '/bin/python';
    }

    private function process(): FakeProcess
    {
        return FakeIdf::process($this->idf, $this->venv, $this->toolchainDir . '/tools');
    }

    private function driver(Process $process, string $os = 'Linux'): Driver
    {
        $sdk = SdkDescriptorsTest::sdk();
        return new Driver(new Context($sdk->dir, $this->toolchainDir, $process), ['PATH' => '/usr/bin'], new SerialPorts(Plans::tempDir(), Plans::tempDir()), $os);
    }

    /** The sync tool as the driver names it: its own directory plus /tools/sync.py. */
    private static function syncTool(): string
    {
        return dirname((string) (new \ReflectionClass(Driver::class))->getFileName()) . '/tools/sync.py';
    }

    /** @return list<string> */
    private function last(FakeProcess $process): array
    {
        return $process->calls[count($process->calls) - 1];
    }

    public function test_build_runs_idf_py_and_returns_the_images(): void
    {
        $plan = Plans::plan();
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(0, "[1/2] cc a.o\n[2/2] Linking\n"));
        mkdir($plan->buildDir . '/idf', 0777, true);
        file_put_contents($plan->buildDir . '/idf/flasher_args.json', '{"flash_files":{"0x0":"bootloader/bootloader.bin","0x10000":"blink.bin"}}');
        $out = new BufferedOutput();

        $artifacts = $this->driver($process)->build($plan, $out);

        $sdk = BuildInputs::cmakePath($plan->sdk->dir);
        $build = $plan->buildDir;
        $cmake = BuildInputs::cmakePath($build);
        self::assertSame(
            [$this->python, "{$this->idf}/tools/idf.py", '-C', "{$cmake}/project", '-B', "{$cmake}/idf", "-DSDKCONFIG={$cmake}/sdkconfig", "-DPHPBM_INPUTS={$cmake}/inputs.cmake", '-DIDF_TARGET=esp32s3', 'build'],
            $this->last($process),
        );
        self::assertFileExists("{$build}/inputs.cmake");
        self::assertSame("cmake_minimum_required(VERSION 3.22)\ninclude(\"{$sdk}/host/project.cmake\")\nproject(firmware)\ninclude(\"{$sdk}/host/post-project.cmake\")\n", file_get_contents("{$build}/project/CMakeLists.txt"));
        self::assertSame([0 => "{$build}/idf/bootloader/bootloader.bin", 0x10000 => "{$build}/idf/blink.bin"], $artifacts->images);
        self::assertContains('step: building for Waveshare ESP32-S3-ETH', $out->lines);
    }

    public function test_build_runs_in_the_generated_project_with_the_target_in_the_environment(): void
    {
        $plan = Plans::plan();
        $process = new RecordingProcess($this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(1)));
        try {
            $this->driver($process)->build($plan, new BufferedOutput());
        } catch (SpecException) {
        }
        $run = $process->last();
        self::assertSame(BuildInputs::cmakePath($plan->buildDir) . '/project', $run['cwd']);
        self::assertSame('esp32s3', $run['env']['IDF_TARGET']);
        self::assertSame($this->venv, $run['env']['IDF_PYTHON_ENV_PATH']);
        self::assertSame($this->idf, $run['env']['IDF_PATH']);
    }

    private function buildWith(\PhpBaremetal\Spec\Sdk\BuildPlan $plan): void
    {
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(0));
        try {
            $this->driver($process)->build($plan, new BufferedOutput());
        } catch (SpecException) {
        }
    }

    private function movedTo(\PhpBaremetal\Spec\Sdk\BuildPlan $first, \PhpBaremetal\Spec\Sdk\BuildPlan $other): \PhpBaremetal\Spec\Sdk\BuildPlan
    {
        return new \PhpBaremetal\Spec\Sdk\BuildPlan($first->projectDir, $other->manifest, $other->sdk, $other->board, $other->chip, $other->runtime, $other->storage, $other->php, $other->phpSourceDir, [], [], $first->buildDir, false, $other->options);
    }

    private function seed(\PhpBaremetal\Spec\Sdk\BuildPlan $plan): void
    {
        @mkdir($plan->buildDir . '/idf', 0777, true);
        file_put_contents($plan->buildDir . '/idf/CMakeCache.txt', 'x');
        file_put_contents($plan->buildDir . '/sdkconfig', 'x');
    }

    public function test_same_plan_keeps_the_cache_and_sdkconfig(): void
    {
        $plan = Plans::plan();
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/fingerprint.json');
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');
        self::assertFileExists($plan->buildDir . '/sdkconfig');
    }

    public function test_changing_the_board_on_the_same_chip_drops_only_sdkconfig(): void
    {
        $first = Plans::plan(['board' => 'waveshare-esp32-s3-eth']);
        $this->buildWith($first);
        $this->seed($first);
        $other = Plans::plan(['board' => 'generic-esp32s3-quad']);
        $moved = new \PhpBaremetal\Spec\Sdk\BuildPlan($first->projectDir, $other->manifest, $other->sdk, $other->board, $other->chip, $other->runtime, $other->storage, $other->php, $other->phpSourceDir, [], [], $first->buildDir);
        $this->buildWith($moved);
        self::assertFileDoesNotExist($first->buildDir . '/sdkconfig');
        self::assertFileExists($first->buildDir . '/idf/CMakeCache.txt');
    }

    public function test_editing_a_linked_sdk_fragment_drops_sdkconfig_but_keeps_the_cache(): void
    {
        $copy = Plans::tempDir();
        $source = dirname(__DIR__);
        foreach (['sdk.json', 'composer.json', 'boards', 'chips', 'runtimes', 'storages', 'host', 'php', 'driver'] as $entry) {
            $this->copyTree("{$source}/{$entry}", "{$copy}/{$entry}");
        }
        $plan = Plans::plan(['sdk' => (new \PhpBaremetal\Spec\Sdk\SdkLoader())->load($copy)]);
        $this->buildWith($plan);
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/sdkconfig');
        file_put_contents("{$copy}/host/sdkconfig.defaults", "CONFIG_EDITED=y\n", FILE_APPEND);
        $this->buildWith($plan);
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');

        $this->seed($plan);
        $defaults = (string) file_get_contents("{$copy}/host/sdkconfig.defaults");
        file_put_contents("{$copy}/host/sdkconfig.defaults", substr($defaults, 0, -strlen("CONFIG_EDITED=y\n")));
        $octal = "{$copy}/chips/esp32s3/sdkconfig.octal";
        file_put_contents($octal, "CONFIG_EDITED=y\n" . file_get_contents($octal));
        $this->buildWith($plan);
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig', 'moving a line between fragments changes the fingerprint');
    }

    public function test_editing_the_network_sdkconfig_fragment_drops_sdkconfig(): void
    {
        $copy = Plans::tempDir();
        $source = dirname(__DIR__);
        foreach (['sdk.json', 'composer.json', 'boards', 'chips', 'runtimes', 'storages', 'networks', 'host', 'php', 'driver'] as $entry) {
            $this->copyTree("{$source}/{$entry}", "{$copy}/{$entry}");
        }
        $wifi = new \PhpBaremetal\Spec\Manifest\Package('php-baremetal/ext-wifi', 'bm-ext', '1.0.0', __DIR__ . '/fixtures/packages/hello', ['module' => 'wifi']);
        $plan = Plans::plan(['sdk' => (new \PhpBaremetal\Spec\Sdk\SdkLoader())->load($copy), 'board' => 'waveshare-esp32-p4-wifi6', 'packages' => [$wifi]]);
        $this->buildWith($plan);
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/sdkconfig');

        file_put_contents("{$copy}/networks/wifi-c6/sdkconfig", "CONFIG_EDITED=y\n", FILE_APPEND);
        $this->buildWith($plan);
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');
    }

    public function test_changing_the_optimization_or_the_p4_revision_drops_sdkconfig(): void
    {
        $plan = Plans::plan();
        $this->buildWith($plan);
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/sdkconfig');
        $this->buildWith($this->movedTo($plan, Plans::plan(['options' => ['optimization' => 'performance']])));
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');

        $p4 = Plans::plan(['board' => 'waveshare-esp32-p4-eth']);
        $this->buildWith($p4);
        $this->seed($p4);
        $this->buildWith($p4);
        self::assertFileExists($p4->buildDir . '/sdkconfig');
        $b = $p4->board;
        $v3 = new \PhpBaremetal\Spec\Sdk\Descriptor\Board($b->id, $b->name, $b->chip, $b->psram, $b->flash, $b->console, $b->peripherals, null, 'v3');
        $this->buildWith($this->movedTo($p4, Plans::plan(['board' => $v3])));
        self::assertFileDoesNotExist($p4->buildDir . '/sdkconfig');
    }

    public function test_changing_power_save_drops_sdkconfig(): void
    {
        $plan = Plans::plan();
        $this->buildWith($plan);
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/sdkconfig');
        $this->buildWith($this->movedTo($plan, Plans::plan(['options' => ['power-save' => 'on']])));
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');
    }

    public function test_editing_a_package_sdkconfig_fragment_drops_sdkconfig(): void
    {
        $dir = Plans::tempDir() . '/cfg';
        mkdir($dir);
        file_put_contents("{$dir}/sdkconfig.esp32", "CONFIG_A=y\n");
        $plan = Plans::plan(['packages' => [new \PhpBaremetal\Spec\Manifest\Package('acme/cfg', 'bm-lib', '1.0.0', $dir, ['sdkconfig' => 'sdkconfig.esp32'])]]);
        $this->buildWith($plan);
        $this->seed($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/sdkconfig');
        $before = (string) file_get_contents($plan->buildDir . '/fingerprint.json');

        file_put_contents("{$dir}/sdkconfig.esp32", "CONFIG_A=n\n");
        $this->buildWith($plan);
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt');
        self::assertNotSame($before, file_get_contents($plan->buildDir . '/fingerprint.json'));
    }

    private function copyTree(string $from, string $to): void
    {
        if (is_file($from)) {
            @mkdir(dirname($to), 0777, true);
            copy($from, $to);
            return;
        }
        @mkdir($to, 0777, true);
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $item) {
            $target = $to . substr($item->getPathname(), strlen($from));
            $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
        }
    }

    public function test_changing_the_chip_drops_the_cache_too(): void
    {
        $first = Plans::plan(['board' => 'waveshare-esp32-s3-eth']);
        $this->buildWith($first);
        $this->seed($first);
        $other = Plans::plan(['board' => 'generic-esp32p4']);
        $moved = new \PhpBaremetal\Spec\Sdk\BuildPlan($first->projectDir, $other->manifest, $other->sdk, $other->board, $other->chip, $other->runtime, $other->storage, $other->php, $other->phpSourceDir, [], [], $first->buildDir);
        $this->buildWith($moved);
        self::assertFileDoesNotExist($first->buildDir . '/sdkconfig');
        self::assertDirectoryDoesNotExist($first->buildDir . '/idf');
        self::assertStringContainsString('esp32p4', (string) file_get_contents($first->buildDir . '/fingerprint.json'));
    }

    public function test_changing_the_idf_version_drops_the_cache_too(): void
    {
        $plan = Plans::plan();
        $this->buildWith($plan);
        $this->seed($plan);
        FakeIdf::install($this->idf, $this->toolchainDir . '/tools', '6.1.1');
        $this->buildWith($plan);
        self::assertDirectoryDoesNotExist($plan->buildDir . '/idf');
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
    }

    public function test_clean_build_starts_from_scratch(): void
    {
        $plan = Plans::plan(['clean' => true]);
        mkdir($plan->buildDir . '/idf/stale', 0777, true);
        file_put_contents($plan->buildDir . '/sdkconfig', 'old');
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(1));

        try {
            $this->driver($process)->build($plan, new BufferedOutput());
        } catch (SpecException) {
        }
        self::assertDirectoryDoesNotExist($plan->buildDir . '/idf/stale');
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
    }

    public function test_a_cmake_cache_from_another_directory_starts_from_scratch(): void
    {
        $plan = Plans::plan();
        $this->buildWith($plan);
        mkdir($plan->buildDir . '/idf/stale', 0777, true);
        file_put_contents($plan->buildDir . '/idf/CMakeCache.txt', 'CMAKE_CACHEFILE_DIR:INTERNAL=' . BuildInputs::cmakePath($plan->buildDir) . "/idf\n");
        file_put_contents($plan->buildDir . '/sdkconfig', 'x');
        $this->buildWith($plan);
        self::assertDirectoryExists($plan->buildDir . '/idf/stale', 'a cache of this directory is kept');
        self::assertFileExists($plan->buildDir . '/sdkconfig');

        file_put_contents($plan->buildDir . '/idf/CMakeCache.txt', "CMAKE_CACHEFILE_DIR:INTERNAL=/elsewhere/.phpbm/build/idf\n");
        $this->buildWith($plan);
        self::assertDirectoryDoesNotExist($plan->buildDir . '/idf/stale');
        self::assertFileDoesNotExist($plan->buildDir . '/sdkconfig');
    }

    public function test_changing_an_sdk_source_with_an_older_mtime_starts_idf_from_scratch(): void
    {
        $copy = Plans::tempDir();
        $source = dirname(__DIR__);
        foreach (['sdk.json', 'composer.json', 'boards', 'chips', 'runtimes', 'storages', 'host', 'php', 'driver'] as $entry) {
            $this->copyTree("{$source}/{$entry}", "{$copy}/{$entry}");
        }
        $plan = Plans::plan(['sdk' => (new \PhpBaremetal\Spec\Sdk\SdkLoader())->load($copy)]);
        $this->buildWith($plan);
        $this->seed($plan);
        $before = \PhpBaremetal\Esp32\Driver::sdkContentHash($plan);
        $this->buildWith($plan);
        self::assertFileExists($plan->buildDir . '/idf/CMakeCache.txt', 'an unchanged sdk keeps the cache');

        foreach (['host/components/phpbm/run.c', "php/{$plan->php->minor}/compat/php_config.h", 'host/cmake/sdkconfig.cmake'] as $file) {
            $path = "{$copy}/{$file}";
            file_put_contents($path, file_get_contents($path) . "/* edited */\n");
            touch($path, strtotime('2020-01-01'));
            self::assertNotSame($before, \PhpBaremetal\Esp32\Driver::sdkContentHash($plan), $file);
            $this->buildWith($plan);
            self::assertFileDoesNotExist($plan->buildDir . '/idf/CMakeCache.txt', $file);
            $this->seed($plan);
            $before = \PhpBaremetal\Esp32\Driver::sdkContentHash($plan);
        }
    }

    public function test_only_sdkconfig_fragments_are_left_out_of_the_sdk_content(): void
    {
        foreach (['/host/sdkconfig.defaults', '/runtimes/init-loop/sdkconfig', '/host/sdkconfig/console-uart', '\\host\\sdkconfig\\optimization-size', '/chips/esp32s3/sdkconfig.rev-v3'] as $fragment) {
            self::assertTrue(\PhpBaremetal\Esp32\Driver::isSdkconfigFragment($fragment), $fragment);
        }
        foreach (['/host/cmake/sdkconfig.cmake', '/host/components/phpbm/sdkconfig_helpers.c', '/host/sdkconfig.h', '/host/my-sdkconfig'] as $source) {
            self::assertFalse(\PhpBaremetal\Esp32\Driver::isSdkconfigFragment($source), $source);
        }
    }

    public function test_a_cmake_cache_path_differing_only_in_case_is_the_same_directory_on_windows(): void
    {
        $dir = Plans::tempDir() . '/idf';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/CMakeCache.txt', 'CMAKE_CACHEFILE_DIR:INTERNAL=' . strtoupper(\PhpBaremetal\Esp32\BuildInputs::cmakePath($dir)) . "\r\n");
        self::assertFalse(\PhpBaremetal\Esp32\Driver::movedCache($dir, true));
        self::assertTrue(\PhpBaremetal\Esp32\Driver::movedCache($dir, false));
    }

    public function test_failed_build_shows_the_tail_and_explains(): void
    {
        $plan = Plans::plan();
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(2, "[1/9] cc a.o\nninja: build stopped\n"));
        $out = new BufferedOutput();

        try {
            $this->driver($process)->build($plan, $out);
            self::fail('expected an exception');
        } catch (SpecException $e) {
            self::assertSame('the firmware build failed (exit 2): see the output above', $e->getMessage());
        }
        self::assertCount(1, array_filter($out->lines, fn (string $line) => $line === 'line: ninja: build stopped'));
    }

    public function test_windows_build_refuses_paths_with_spaces(): void
    {
        $plan = Plans::plan();
        $spaced = new \PhpBaremetal\Spec\Sdk\BuildPlan(
            '/my projects/blink', $plan->manifest, $plan->sdk, $plan->board, $plan->chip, $plan->runtime, $plan->storage,
            $plan->php, $plan->phpSourceDir, [], [], '/my projects/blink/.phpbm/build',
        );
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('ESP-IDF cannot build from a path with spaces or non-ASCII characters (/my projects/blink): move the project');
        $this->driver($this->process(), 'Windows')->build($spaced, new BufferedOutput());
    }

    public function test_windows_build_refuses_an_sdk_path_with_spaces(): void
    {
        $plan = Plans::plan();
        $sdkDir = Plans::tempDir() . '/my sdk';
        mkdir($sdkDir, 0777, true);
        $driver = new Driver(new Context($sdkDir, $this->toolchainDir, $this->process()), ['PATH' => '/usr/bin'], new SerialPorts(Plans::tempDir(), Plans::tempDir()), 'Windows');
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('ESP-IDF cannot build from a path with spaces or non-ASCII characters (' . $sdkDir . ')');
        $driver->build($plan, new BufferedOutput());
    }

    public function test_linux_build_allows_a_project_path_with_spaces(): void
    {
        $base = Plans::tempDir();
        $proj = $base . '/my projects/blink';
        $plan = Plans::plan();
        $spaced = new \PhpBaremetal\Spec\Sdk\BuildPlan(
            $proj, $plan->manifest, $plan->sdk, $plan->board, $plan->chip, $plan->runtime, $plan->storage,
            $plan->php, $plan->phpSourceDir, [], [], $proj . '/.phpbm/build',
        );
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(1));

        try {
            $this->driver($process)->build($spaced, new BufferedOutput());
            self::fail('expected an exception');
        } catch (SpecException $e) {
            self::assertSame('the firmware build failed (exit 1): see the output above', $e->getMessage());
        }
    }

    public function test_build_validates_the_toolchain_before_cleaning(): void
    {
        $plan = Plans::plan(['clean' => true]);
        mkdir($plan->buildDir . '/idf/stale', 0777, true);

        try {
            $this->driver(new FakeProcess())->build($plan, new BufferedOutput());
            self::fail('expected an exception');
        } catch (SpecException) {
        }
        self::assertDirectoryExists($plan->buildDir . '/idf/stale');
        self::assertFileDoesNotExist($plan->buildDir . '/inputs.cmake');
    }

    public function test_build_rejects_network_on_for_a_board_without_network_before_cleaning(): void
    {
        $plan = Plans::plan(['board' => 'generic-esp32p4', 'options' => ['network' => 'on'], 'clean' => true]);
        mkdir($plan->buildDir . '/idf/stale', 0777, true);
        $process = $this->process();

        try {
            $this->driver($process)->build($plan, new BufferedOutput());
            self::fail('expected an exception');
        } catch (SpecException $e) {
            self::assertSame("option network=on: board 'generic-esp32p4' has no network", $e->getMessage());
        }
        self::assertDirectoryExists($plan->buildDir . '/idf/stale');
        self::assertFileDoesNotExist($plan->buildDir . '/inputs.cmake');
    }

    public function test_build_passes_the_network_to_the_host(): void
    {
        $plan = Plans::plan(['options' => ['network' => 'on']]);
        $process = $this->process()->on($this->python . ' ' . $this->idf . '/tools/idf.py', new ProcessResult(0, ''));
        mkdir($plan->buildDir . '/idf', 0777, true);
        file_put_contents($plan->buildDir . '/idf/flasher_args.json', '{"flash_files":{"0x0":"bootloader/bootloader.bin"}}');
        $this->driver($process)->build($plan, new BufferedOutput());
        self::assertStringContainsString("set(PHPBM_NETWORK \"w5500\")\n", (string) file_get_contents($plan->buildDir . '/inputs.cmake'));
        self::assertStringContainsString('/networks/w5500/phpbm_w5500")', (string) file_get_contents($plan->buildDir . '/inputs.cmake'));
    }

    public function test_probes_never_throw_when_the_process_fails(): void
    {
        $process = new class () implements Process {
            public function run(array $command, ?string $cwd = null, array $env = [], ?\PhpBaremetal\Spec\Sdk\Output $out = null): ProcessResult
            {
                throw new \RuntimeException('cannot spawn');
            }

            public function tty(array $command, ?string $cwd = null, array $env = []): int
            {
                return 1;
            }
        };
        $driver = $this->driver($process);

        self::assertNull($driver->identify(new Port('/dev/x')));
        self::assertSame([], $driver->ports());
    }

    public function test_flash_writes_flash_args_with_esptool(): void
    {
        $plan = Plans::plan();
        $process = $this->process()->on($this->python . ' -m esptool', new ProcessResult(0));

        $this->driver($process)->flash(new Artifacts($plan->buildDir, [], ['idf_build_dir' => '/b/idf']), $plan, new Port('/dev/ttyACM0'), new BufferedOutput());

        self::assertSame(
            [$this->python, '-m', 'esptool', '--chip', 'esp32s3', '-p', '/dev/ttyACM0', '-b', '460800', '--before', 'default-reset', '--after', 'hard-reset', 'write-flash', '@flash_args'],
            $this->last($process),
        );
    }

    public function test_flash_to_microsd_only_writes_the_images(): void
    {
        $plan = Plans::plan(['storage' => 'microsd']);
        $process = $this->process()->on($this->python . ' -m esptool', new ProcessResult(0));

        $this->driver($process)->flash(new Artifacts($plan->buildDir, [], ['idf_build_dir' => '/b/idf']), $plan, new Port('/dev/ttyACM0'), new BufferedOutput());

        self::assertSame(['-m', 'esptool'], array_slice($this->last($process), 1, 2));
        self::assertSame([], array_filter($process->calls, static fn (array $c) => str_contains(implode(' ', $c), 'parttool')));
    }

    public function test_sync_runs_the_sync_tool_on_the_sources(): void
    {
        $plan = Plans::plan(['storage' => 'microsd']);
        $tool = self::syncTool();
        $process = new RecordingProcess($this->process()->on("{$this->python} -u {$tool}", new ProcessResult(0, "put index.php (12 B)\nsynced 1 file(s)\n")));
        $out = new BufferedOutput();

        self::assertTrue($this->driver($process)->sync($plan, new Port('/dev/ttyACM0'), $out));

        self::assertSame([$this->python, '-u', $tool, '--port', '/dev/ttyACM0', $plan->srcDir()], $process->last()['command']);
        self::assertSame($plan->projectDir, $process->last()['cwd']);
        self::assertContains('step: syncing project-src to the microSD card on /dev/ttyACM0', $out->lines);
        self::assertContains('line: put index.php (12 B)', $out->lines);
    }

    public function test_sync_excludes_the_manifest_env_file_and_forces_utf8(): void
    {
        $plan = Plans::plan(['storage' => 'microsd', 'envFile' => 'config/prod.env']);
        $tool = self::syncTool();
        $process = new RecordingProcess($this->process()->on("{$this->python} -u {$tool}", new ProcessResult(0)));

        $this->driver($process)->sync($plan, new Port('/dev/ttyACM0'), new BufferedOutput());

        self::assertSame([$this->python, '-u', $tool, '--port', '/dev/ttyACM0', '--exclude', $plan->projectDir . '/config/prod.env', $plan->srcDir()], $process->last()['command']);
    }

    public function test_failed_sync_explains(): void
    {
        $plan = Plans::plan(['storage' => 'microsd']);
        $process = $this->process()->on("{$this->python} -u " . self::syncTool(), new ProcessResult(1, '', "error: the board did not answer\n"));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('syncing to the microSD card on /dev/ttyACM0 failed: the board did not answer');
        $this->driver($process)->sync($plan, new Port('/dev/ttyACM0'), new BufferedOutput());
    }

    public function test_sync_does_nothing_for_embedded_sources(): void
    {
        $plan = Plans::plan();
        $process = $this->process();
        $calls = count($process->calls);
        $out = new BufferedOutput();

        self::assertFalse($this->driver($process)->sync($plan, new Port('/dev/ttyACM0'), $out));

        self::assertCount($calls, $process->calls);
        self::assertSame([], $out->lines);
    }

    public function test_failed_flash_explains(): void
    {
        $plan = Plans::plan();
        $process = $this->process()->on($this->python . ' -m esptool', new ProcessResult(2, '', "A fatal error occurred: Failed to connect\n"));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('flashing /dev/ttyACM0 failed: A fatal error occurred: Failed to connect; check the cable, or hold BOOT while pressing RESET and try again');
        $this->driver($process)->flash(new Artifacts($plan->buildDir, [], ['idf_build_dir' => '/b/idf']), $plan, new Port('/dev/ttyACM0'), new BufferedOutput());
    }

    public function test_identify(): void
    {
        $process = $this->process()->on(
            $this->python . ' -m esptool --chip auto -p /dev/ttyACM0',
            new ProcessResult(0, (string) file_get_contents(__DIR__ . '/fixtures/esptool-v5.txt')),
        );
        $chip = $this->driver($process)->identify(new Port('/dev/ttyACM0'));
        self::assertSame('esp32p4', $chip?->target);
        self::assertSame([$this->python, '-m', 'esptool', '--chip', 'auto', '-p', '/dev/ttyACM0', '--connect-attempts', '2', 'flash-id'], $this->last($process));
    }

    public function test_identify_is_null_without_toolchain_or_answer(): void
    {
        self::assertNull($this->driver(new FakeProcess())->identify(new Port('/dev/x')));
        self::assertNull($this->driver($this->process())->identify(new Port('/dev/x')));
    }

    public function test_ports_from_pyserial_with_fallback(): void
    {
        $process = $this->process()->on($this->python . ' -m serial.tools.list_ports', new ProcessResult(0, (string) file_get_contents(__DIR__ . '/fixtures/list-ports-windows.txt')));
        self::assertSame('COM3', $this->driver($process)->ports()[0]->path);

        self::assertSame([], $this->driver(new FakeProcess())->ports());
    }

    public function test_monitor(): void
    {
        $process = $this->process()->onTty($this->python . ' -m esp_idf_monitor', 0);
        self::assertSame(0, $this->driver($process)->monitor(new Port('/dev/ttyACM0')));
        self::assertSame([$this->python, '-m', 'esp_idf_monitor', '-p', '/dev/ttyACM0', '-b', '115200'], $this->last($process));
    }
}
