<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Sdk\Toolchain as ToolchainContract;
use PhpBaremetal\Spec\Sdk\ToolchainStatus;
use PhpBaremetal\Spec\SpecException;

final class Toolchain implements ToolchainContract
{
    public const IDF_VERSION = 'v6.1';
    public const IDF_REPOSITORY = 'https://github.com/espressif/esp-idf.git';
    public const TARGETS = 'esp32s3,esp32p4';
    public const MIN_PYTHON = '3.10';
    public const NO_PYTHON = 'ESP-IDF needs Python 3.10 or newer: install it (python.org; on macOS `brew install python`; on Windows tick "Add python.exe to PATH") or set PHPBM_PYTHON';

    /** @var list<string>|false|null */
    private array|false|null $hostPython = null;
    /** @var array<string,string>|null */
    private ?array $activated = null;
    private ?string $pythonVersion = null;

    /** @param array<string,string> $environment */
    public function __construct(
        private readonly Context $context,
        private readonly array $environment,
        private readonly string $osFamily = PHP_OS_FAMILY,
    ) {
    }

    public function external(): bool
    {
        return $this->variable('PHPBM_ESP32_IDF_PATH') !== '';
    }

    public function windows(): bool
    {
        return $this->osFamily === 'Windows';
    }

    public function idfPath(): string
    {
        return $this->external()
            ? rtrim($this->variable('PHPBM_ESP32_IDF_PATH'), '/\\')
            : $this->context->toolchainDir . '/esp-idf-' . self::IDF_VERSION;
    }

    public function toolsPath(): string
    {
        if (!$this->external()) {
            return $this->context->toolchainDir . '/tools';
        }
        foreach (['PHPBM_ESP32_IDF_TOOLS_PATH', 'IDF_TOOLS_PATH'] as $name) {
            if ($this->variable($name) !== '') {
                return rtrim($this->variable($name), '/\\');
            }
        }
        $home = $this->variable('HOME') !== '' ? $this->variable('HOME') : $this->variable('USERPROFILE');
        return rtrim($home, '/\\') . '/.espressif';
    }

    public function version(): ?string
    {
        $header = @file_get_contents($this->idfPath() . '/components/esp_common/include/esp_idf_version.h');
        if ($header === false) {
            return null;
        }
        $parts = [];
        foreach (['MAJOR', 'MINOR', 'PATCH'] as $part) {
            if (!preg_match('/#define\s+ESP_IDF_VERSION_' . $part . '\s+(\d+)/', $header, $m)) {
                return null;
            }
            $parts[] = $m[1];
        }
        return 'v' . implode('.', $parts);
    }

    /** @return list<string>|null */
    public function hostPython(): ?array
    {
        if ($this->hostPython === null) {
            $this->hostPython = false;
            $candidates = $this->variable('PHPBM_PYTHON') !== '' ? [[$this->variable('PHPBM_PYTHON')]] : [];
            $candidates = [...$candidates, ...($this->windows() ? [['py', '-3'], ['python']] : [['python3'], ['python3.13'], ['python3.12'], ['python3.11'], ['python3.10'], ['python']])];
            foreach ($candidates as $candidate) {
                $result = $this->context->process->run([...$candidate, '--version']);
                if ($result->successful()
                    && preg_match('/Python (\d+)\.(\d+)/', $result->output . $result->errorOutput, $m)
                    && version_compare("{$m[1]}.{$m[2]}", self::MIN_PYTHON, '>=')) {
                    $this->hostPython = $candidate;
                    $this->pythonVersion = "{$m[1]}.{$m[2]}";
                    break;
                }
            }
        }
        return $this->hostPython === false ? null : $this->hostPython;
    }

    public function env(): array
    {
        if ($this->activated !== null) {
            return $this->activated;
        }
        $python = $this->hostPython() ?? throw new SpecException(self::NO_PYTHON);
        $base = $this->baseEnv();
        $result = $this->context->process->run([...$python, $this->idfPath() . '/tools/idf_tools.py', '--non-interactive', 'export', '--format', 'key-value'], null, $base);
        if (!$result->successful()) {
            throw new SpecException(sprintf('cannot activate ESP-IDF in %s: %s; %s', $this->idfPath(), self::lastLine($result), $this->installHint()));
        }
        $pathKey = 'PATH';
        if ($this->windows()) {
            foreach (array_keys($this->environment) as $key) {
                if (strcasecmp((string) $key, 'path') === 0) {
                    $pathKey = (string) $key;
                    break;
                }
            }
        }
        $exported = IdfExport::parse($result->output, $this->variable($pathKey), $this->windows() ? ';' : ':');
        if ($pathKey !== 'PATH' && isset($exported['PATH'])) {
            $exported[$pathKey] = $exported['PATH'];
            unset($exported['PATH']);
        }
        $env = [...$base, ...$exported];
        if (!is_file(self::venvPython($env['IDF_PYTHON_ENV_PATH'], $this->windows()))) {
            throw new SpecException('ESP-IDF tools are not installed in ' . $this->toolsPath() . ': ' . $this->installHint());
        }
        return $this->activated = $env;
    }

    /** @return array<string,string> */
    private function baseEnv(): array
    {
        $this->hostPython();
        $majorMinor = preg_match('/^v(\d+\.\d+)/', $this->version() ?? self::IDF_VERSION, $m) ? $m[1] : substr(self::IDF_VERSION, 1);

        return [
            'IDF_PATH' => $this->idfPath(),
            'IDF_TOOLS_PATH' => $this->toolsPath(),
            'IDF_PYTHON_ENV_PATH' => sprintf('%s/python_env/idf%s_py%s_env', $this->toolsPath(), $majorMinor, $this->pythonVersion ?? ''),
            'ESP_IDF_VERSION' => $majorMinor,
        ];
    }

    private function compatible(?string $version): bool
    {
        return $version !== null && str_starts_with($version . '.', self::IDF_VERSION . '.');
    }

    public function python(): string
    {
        return self::venvPython($this->env()['IDF_PYTHON_ENV_PATH'], $this->windows());
    }

    public function status(): ToolchainStatus
    {
        $idf = $this->idfPath();
        if (!is_file("{$idf}/tools/idf.py") || !is_file("{$idf}/tools/idf_tools.py")) {
            return new ToolchainStatus(false, null, "ESP-IDF not found in {$idf}");
        }
        $version = $this->version();
        if (!$this->compatible($version)) {
            return new ToolchainStatus(false, $version, sprintf('ESP-IDF %s in %s, the esp32 sdk needs %s', $version ?? 'of unknown version', $idf, self::IDF_VERSION));
        }
        if (($problem = $this->pathProblem()) !== null) {
            return new ToolchainStatus(false, $version, $problem);
        }
        try {
            $this->env();
        } catch (SpecException $e) {
            return new ToolchainStatus(false, $version, $e->getMessage());
        }
        return new ToolchainStatus(true, $version);
    }

    public function install(Output $out): void
    {
        if ($this->external()) {
            throw new SpecException('PHPBM_ESP32_IDF_PATH points to an ESP-IDF managed outside phpbm: install its tools there with idf_tools.py, or unset the variable');
        }
        $python = $this->hostPython() ?? throw new SpecException(self::NO_PYTHON);
        if (($problem = $this->pathProblem()) !== null) {
            throw new SpecException($problem);
        }
        $idf = $this->idfPath();
        $process = $this->context->process;

        if (!is_file("{$idf}/tools/idf_tools.py") || !$this->compatible($this->version())) {
            Files::deleteTree($idf);
            if (file_exists($idf)) {
                throw new SpecException("cannot remove the incomplete ESP-IDF checkout in {$idf}: delete it by hand and run `phpbm toolchain:install` again");
            }
            $out->step('cloning ESP-IDF ' . self::IDF_VERSION);
            $result = $process->run(['git', ...($this->windows() ? ['-c', 'core.longpaths=true'] : []), 'clone', '--depth', '1', '--branch', self::IDF_VERSION, '--recurse-submodules', '--shallow-submodules', self::IDF_REPOSITORY, $idf], null, [], $out);
            if ($result->exitCode === 127) {
                throw new SpecException('git is required to install ESP-IDF: install git and run `phpbm toolchain:install` again');
            }
            if (!$result->successful()) {
                throw new SpecException(sprintf('cannot clone ESP-IDF %s: %s; check the network and run `phpbm toolchain:install` again', self::IDF_VERSION, self::lastLine($result)));
            }
        }

        $env = $this->baseEnv();
        $steps = [
            'installing ESP-IDF tools for ' . self::TARGETS => ['install', 'required', 'cmake', 'ninja', '--targets=' . self::TARGETS],
            'installing the ESP-IDF Python environment' => ['install-python-env'],
        ];
        foreach ($steps as $label => $args) {
            $out->step($label);
            $result = $process->run([...$python, "{$idf}/tools/idf_tools.py", '--non-interactive', ...$args], $idf, $env, $out);
            if (!$result->successful()) {
                throw new SpecException(sprintf('%s failed (exit %d): %s; fix the cause and run `phpbm toolchain:install` again', $label, $result->exitCode, self::lastLine($result)));
            }
        }
        $this->activated = null;
    }

    public static function lastLine(ProcessResult $result): string
    {
        $lines = preg_split('/\R/', trim($result->errorOutput !== '' ? $result->errorOutput : $result->output)) ?: [];
        return trim((string) end($lines)) ?: 'no output';
    }

    private function pathProblem(): ?string
    {
        if ($this->windows()) {
            foreach ([$this->idfPath(), $this->toolsPath()] as $path) {
                if (preg_match('/[^\x21-\x7E]/', $path)) {
                    return "ESP-IDF cannot work from a path with spaces or non-ASCII characters ({$path}): set PHPBM_HOME to a path without them";
                }
            }
        }
        return null;
    }

    private function installHint(): string
    {
        return $this->external() ? 'run its tools/idf_tools.py install and install-python-env' : 'run `phpbm toolchain:install`';
    }

    private static function venvPython(string $venv, bool $windows): string
    {
        return $venv === '' ? '' : rtrim($venv, '/\\') . ($windows ? '/Scripts/python.exe' : '/bin/python');
    }

    private function variable(string $name): string
    {
        $value = $this->environment[$name] ?? '';
        return is_string($value) ? $value : '';
    }
}
