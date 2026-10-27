<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\Idf;
use PhpBaremetal\Esp32\Tests\Support\FakeIdf;
use PhpBaremetal\Esp32\Tests\Support\Plans;
use PhpBaremetal\Esp32\Tests\Support\RecordingProcess;
use PhpBaremetal\Esp32\Toolchain;
use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\SpecException;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PhpBaremetal\Spec\Testing\FakeProcess;
use PHPUnit\Framework\TestCase;

final class ToolchainTest extends TestCase
{
    private string $dir;
    private string $idf;
    private string $tools;

    protected function setUp(): void
    {
        $this->dir = Plans::tempDir();
        $this->idf = $this->dir . '/toolchain/esp-idf-v6.1';
        $this->tools = $this->dir . '/toolchain/tools';
    }

    private function toolchain(Process $process, array $env = ['PATH' => '/usr/bin'], string $os = 'Linux', ?string $dir = null): Toolchain
    {
        return new Toolchain(new Context('/sdk', $dir ?? $this->dir . '/toolchain', $process), $env, $os);
    }

    public function test_paths_inside_the_toolchain_dir(): void
    {
        $toolchain = $this->toolchain(new FakeProcess());
        self::assertFalse($toolchain->external());
        self::assertSame($this->idf, $toolchain->idfPath());
        self::assertSame($this->tools, $toolchain->toolsPath());
    }

    public function test_external_idf_and_its_tools(): void
    {
        $env = ['PHPBM_ESP32_IDF_PATH' => '/opt/idf/', 'HOME' => '/home/u'];
        self::assertSame('/opt/idf', $this->toolchain(new FakeProcess(), $env)->idfPath());
        self::assertSame('/home/u/.espressif', $this->toolchain(new FakeProcess(), $env)->toolsPath());
        self::assertSame('/t', $this->toolchain(new FakeProcess(), [...$env, 'IDF_TOOLS_PATH' => '/t'])->toolsPath());
        self::assertSame('/x', $this->toolchain(new FakeProcess(), [...$env, 'IDF_TOOLS_PATH' => '/t', 'PHPBM_ESP32_IDF_TOOLS_PATH' => '/x'])->toolsPath());
        self::assertSame('C:\\Users\\u/.espressif', $this->toolchain(new FakeProcess(), ['PHPBM_ESP32_IDF_PATH' => 'C:\\idf', 'USERPROFILE' => 'C:\\Users\\u'], 'Windows')->toolsPath());
    }

    public function test_missing_idf(): void
    {
        $status = $this->toolchain(new FakeProcess())->status();
        self::assertFalse($status->installed);
        self::assertSame("ESP-IDF not found in {$this->idf}", $status->problem);
    }

    public function test_wrong_idf_version(): void
    {
        FakeIdf::install($this->idf, $this->tools, '5.5.5');
        $status = $this->toolchain(new FakeProcess())->status();
        self::assertSame('v5.5.5', $status->version);
        self::assertSame("ESP-IDF v5.5.5 in {$this->idf}, the esp32 sdk needs v6.1", $status->problem);
    }

    public function test_no_suitable_python(): void
    {
        FakeIdf::install($this->idf, $this->tools);
        $process = (new FakeProcess())->on('python3 --version', new ProcessResult(0, "Python 3.9.18\n"));
        $status = $this->toolchain($process)->status();
        self::assertFalse($status->installed);
        self::assertSame(Toolchain::NO_PYTHON, $status->problem);
    }

    public function test_tools_missing(): void
    {
        FakeIdf::install($this->idf, $this->tools);
        \PhpBaremetal\Esp32\Files::deleteTree($this->tools . '/python_env');
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(0, "PATH=/x:\$PATH\n"));
        $status = $this->toolchain($process)->status();
        self::assertSame("ESP-IDF tools are not installed in {$this->tools}: run `phpbm toolchain:install`", $status->problem);
    }

    public function test_installed_and_activated(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools);
        $toolchain = $this->toolchain(FakeIdf::process($this->idf, $venv, $this->tools));

        $status = $toolchain->status();
        self::assertTrue($status->installed);
        self::assertSame('v6.1.0', $status->version);
        self::assertSame([
            'IDF_PATH' => $this->idf,
            'IDF_TOOLS_PATH' => $this->tools,
            'IDF_PYTHON_ENV_PATH' => $venv,
            'ESP_IDF_VERSION' => '6.1',
            'PATH' => "{$this->tools}/tools/xtensa/bin:{$this->idf}/tools:/usr/bin",
        ], $toolchain->env());
        self::assertSame("{$venv}/bin/python", $toolchain->python());
    }

    public function test_prefers_phpbm_python_and_skips_old_ones(): void
    {
        $process = (new FakeProcess())
            ->on('/opt/py --version', new ProcessResult(0, "Python 3.8.0\n"))
            ->on('python3 --version', new ProcessResult(0, "Python 3.11.2\n"));
        self::assertSame(['python3'], $this->toolchain($process, ['PHPBM_PYTHON' => '/opt/py'])->hostPython());

        $windows = (new FakeProcess())->on('py -3 --version', new ProcessResult(0, "Python 3.12.0\n"));
        self::assertSame(['py', '-3'], $this->toolchain($windows, [], 'Windows')->hostPython());
    }

    public function test_windows_rejects_paths_with_spaces(): void
    {
        $dir = $this->dir . '/my tools';
        FakeIdf::install("{$dir}/esp-idf-v6.1", "{$dir}/tools");
        $status = $this->toolchain(new FakeProcess(), [], 'Windows', $dir)->status();
        self::assertFalse($status->installed);
        self::assertSame("ESP-IDF cannot work from a path with spaces or non-ASCII characters ({$dir}/esp-idf-v6.1): set PHPBM_HOME to a path without them", $status->problem);
    }

    public function test_install_clones_then_installs_tools_and_python_env(): void
    {
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(0))
            ->on("python3 {$this->idf}/tools/idf_tools.py", new ProcessResult(0));
        $out = new BufferedOutput();
        $this->toolchain($process)->install($out);

        self::assertSame([
            ['python3', '--version'],
            ['git', 'clone', '--depth', '1', '--branch', 'v6.1', '--recurse-submodules', '--shallow-submodules', Toolchain::IDF_REPOSITORY, $this->idf],
            ['python3', "{$this->idf}/tools/idf_tools.py", '--non-interactive', 'install', 'required', 'cmake', 'ninja', '--targets=esp32s3,esp32p4'],
            ['python3', "{$this->idf}/tools/idf_tools.py", '--non-interactive', 'install-python-env'],
        ], $process->calls);
        self::assertContains('step: cloning ESP-IDF v6.1', $out->lines);
        self::assertContains('step: installing ESP-IDF tools for esp32s3,esp32p4', $out->lines);
        self::assertContains('step: installing the ESP-IDF Python environment', $out->lines);
    }

    public function test_install_keeps_an_existing_checkout_and_clears_a_broken_one(): void
    {
        mkdir($this->idf . '/half', 0777, true);
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(0))
            ->on("python3 {$this->idf}/tools/idf_tools.py", new ProcessResult(0));
        $this->toolchain($process)->install(new BufferedOutput());
        self::assertDirectoryDoesNotExist($this->idf . '/half');

        FakeIdf::install($this->idf, $this->tools);
        $again = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on("python3 {$this->idf}/tools/idf_tools.py", new ProcessResult(0));
        $this->toolchain($again)->install(new BufferedOutput());
        self::assertCount(0, array_filter($again->calls, static fn ($c) => $c[0] === 'git'));
    }

    public function test_install_failures_explain_what_to_do(): void
    {
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(128, '', "fatal: unable to access\n"));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('cannot clone ESP-IDF v6.1: fatal: unable to access; check the network and run `phpbm toolchain:install` again');
        $this->toolchain($process)->install(new BufferedOutput());
    }

    public function test_install_needs_python(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage(Toolchain::NO_PYTHON);
        $this->toolchain(new FakeProcess())->install(new BufferedOutput());
    }

    public function test_install_refuses_an_external_idf(): void
    {
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('PHPBM_ESP32_IDF_PATH points to an ESP-IDF managed outside phpbm');
        $this->toolchain(new FakeProcess(), ['PHPBM_ESP32_IDF_PATH' => '/opt/idf'])->install(new BufferedOutput());
    }

    public function test_idf_runs_the_venv_python_with_the_activated_environment(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools);
        $process = FakeIdf::process($this->idf, $venv, $this->tools);
        $idf = new Idf($process, $this->toolchain($process));

        $idf->idfPy(['--version'], '/w');
        $idf->python(['-m', 'esptool', 'version']);

        self::assertSame(["{$venv}/bin/python", "{$this->idf}/tools/idf.py", '--version'], $process->calls[2]);
        self::assertSame(["{$venv}/bin/python", '-m', 'esptool', 'version'], $process->calls[3]);
    }

    public function test_windows_keeps_the_path_key_case(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools, '6.1.0', true);
        $process = (new FakeProcess())
            ->on('py -3 --version', new ProcessResult(0, "Python 3.12.0\n"))
            ->on("py -3 {$this->idf}/tools/idf_tools.py --non-interactive export", new ProcessResult(0, "IDF_PYTHON_ENV_PATH={$venv}\nPATH=C:\\x\\bin;%PATH%\n"));
        $toolchain = $this->toolchain($process, ['Path' => 'C:\\Windows', 'USERPROFILE' => 'C:\\Users\\u'], 'Windows');

        $env = $toolchain->env();
        self::assertArrayNotHasKey('PATH', $env);
        self::assertSame('C:\\x\\bin;C:\\Windows', $env['Path']);
        self::assertStringEndsWith('/Scripts/python.exe', $toolchain->python());
    }

    public function test_export_failure_is_reported(): void
    {
        FakeIdf::install($this->idf, $this->tools);
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(1, '', "ERROR: tool xtensa-esp-elf has no installed versions\n"));
        try {
            $this->toolchain($process)->env();
            self::fail('expected exception');
        } catch (SpecException $e) {
            self::assertStringContainsString("cannot activate ESP-IDF in {$this->idf}: ERROR: tool xtensa-esp-elf has no installed versions; ", $e->getMessage());
            self::assertStringContainsString('run `phpbm toolchain:install`', $e->getMessage());
        }
    }

    public function test_export_failure_on_an_external_idf_points_to_idf_tools(): void
    {
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(1, '', "ERROR: broken\n"));
        try {
            $this->toolchain($process, ['PHPBM_ESP32_IDF_PATH' => '/opt/idf', 'HOME' => '/h'])->env();
            self::fail('expected exception');
        } catch (SpecException $e) {
            self::assertStringContainsString('cannot activate ESP-IDF in /opt/idf: ERROR: broken; ', $e->getMessage());
            self::assertStringContainsString('idf_tools.py install and install-python-env', $e->getMessage());
        }
    }

    public function test_missing_venv_on_an_external_idf_uses_the_external_hint(): void
    {
        $venv = $this->dir . '/nope';
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(0, "IDF_PYTHON_ENV_PATH={$venv}\n"));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('idf_tools.py install and install-python-env');
        $this->toolchain($process, ['PHPBM_ESP32_IDF_PATH' => '/opt/idf', 'HOME' => '/h'])->env();
    }

    public function test_delete_tree_removes_read_only_files(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX permissions');
        }
        $tree = $this->dir . '/ro';
        mkdir("{$tree}/sub", 0777, true);
        file_put_contents("{$tree}/sub/f", 'x');
        chmod("{$tree}/sub/f", 0444);
        \PhpBaremetal\Esp32\Files::deleteTree($tree);
        self::assertFileDoesNotExist("{$tree}/sub/f");
        self::assertDirectoryDoesNotExist($tree);
    }

    public function test_install_checks_the_windows_path_before_cloning(): void
    {
        $dir = $this->dir . '/my tools';
        $process = (new FakeProcess())->on('py -3 --version', new ProcessResult(0, "Python 3.12.0\n"));
        try {
            $this->toolchain($process, [], 'Windows', $dir)->install(new BufferedOutput());
            self::fail('expected exception');
        } catch (SpecException $e) {
            self::assertStringContainsString('ESP-IDF cannot work from a path with spaces or non-ASCII characters', $e->getMessage());
        }
        self::assertCount(0, array_filter($process->calls, static fn ($c) => $c[0] === 'git'));
    }

    public function test_install_reports_a_missing_git(): void
    {
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(127));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('git is required to install ESP-IDF: install git and run `phpbm toolchain:install` again');
        $this->toolchain($process)->install(new BufferedOutput());
    }

    public function test_install_python_env_failure(): void
    {
        FakeIdf::install($this->idf, $this->tools);
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on("python3 {$this->idf}/tools/idf_tools.py --non-interactive install-python-env", new ProcessResult(2, '', "pip failed\n"))
            ->on("python3 {$this->idf}/tools/idf_tools.py", new ProcessResult(0));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage('installing the ESP-IDF Python environment failed (exit 2): pip failed');
        $this->toolchain($process)->install(new BufferedOutput());
    }

    public function test_install_invalidates_the_activation_memo(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools);
        $process = FakeIdf::process($this->idf, $venv, $this->tools)
            ->on("python3 {$this->idf}/tools/idf_tools.py --non-interactive install", new ProcessResult(0));
        $toolchain = $this->toolchain($process);
        $toolchain->env();
        $toolchain->install(new BufferedOutput());
        $toolchain->env();
        $exports = array_filter($process->calls, static fn ($c) => in_array('export', $c, true));
        self::assertCount(2, $exports);
    }

    public function test_env_pins_the_idf_variables_even_if_the_export_omits_them(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools);
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(0, "PATH=/x:\$PATH\n"));
        $env = $this->toolchain($process)->env();

        self::assertSame($venv, $env['IDF_PYTHON_ENV_PATH']);
        self::assertSame('6.1', $env['ESP_IDF_VERSION']);
        self::assertSame($this->idf, $env['IDF_PATH']);
    }

    public function test_inherited_idf_variables_do_not_leak(): void
    {
        $venv = FakeIdf::install($this->idf, $this->tools);
        $process = new RecordingProcess((new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('python3', new ProcessResult(0, "PATH=/x:\$PATH\n")));
        $inherited = ['PATH' => '/usr/bin', 'IDF_PATH' => '/other', 'IDF_TOOLS_PATH' => '/other-tools', 'IDF_PYTHON_ENV_PATH' => '/other/venv', 'ESP_IDF_VERSION' => '5.0'];
        $env = $this->toolchain($process, $inherited)->env();

        self::assertSame($venv, $env['IDF_PYTHON_ENV_PATH']);
        self::assertSame($this->idf, $env['IDF_PATH']);
        self::assertSame($this->tools, $env['IDF_TOOLS_PATH']);
        self::assertSame('6.1', $env['ESP_IDF_VERSION']);
        $export = $process->runs[1]['env'];
        self::assertSame($venv, $export['IDF_PYTHON_ENV_PATH']);
        self::assertSame('6.1', $export['ESP_IDF_VERSION']);
    }

    public function test_install_runs_with_the_pinned_environment(): void
    {
        $venv = $this->tools . '/python_env/idf6.1_py3.12_env';
        $process = new RecordingProcess((new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(0))
            ->on('python3', new ProcessResult(0)));
        $this->toolchain($process)->install(new BufferedOutput());

        self::assertSame(['IDF_PATH' => $this->idf, 'IDF_TOOLS_PATH' => $this->tools, 'IDF_PYTHON_ENV_PATH' => $venv, 'ESP_IDF_VERSION' => '6.1'], $process->last()['env']);
    }

    public function test_install_reclones_an_incompatible_managed_checkout(): void
    {
        FakeIdf::install($this->idf, $this->tools, '5.5.5');
        file_put_contents($this->idf . '/stale', 'x');
        $process = (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on('git clone', new ProcessResult(0))
            ->on("python3 {$this->idf}/tools/idf_tools.py", new ProcessResult(0));
        $this->toolchain($process)->install(new BufferedOutput());

        self::assertFileDoesNotExist($this->idf . '/stale');
        self::assertCount(1, array_filter($process->calls, static fn ($c) => $c[0] === 'git'));
    }

    public function test_windows_clone_enables_long_paths(): void
    {
        $process = (new FakeProcess())
            ->on('py -3 --version', new ProcessResult(0, "Python 3.12.0\n"))
            ->on('git -c core.longpaths=true clone', new ProcessResult(0))
            ->on('py -3', new ProcessResult(0));
        $this->toolchain($process, [], 'Windows')->install(new BufferedOutput());

        self::assertSame(['git', '-c', 'core.longpaths=true', 'clone'], array_slice($process->calls[1], 0, 4));
    }

    public function test_the_managed_idf_directory_is_versioned(): void
    {
        self::assertStringEndsWith('/esp-idf-v6.1', $this->toolchain(new FakeProcess())->idfPath());
    }
}
