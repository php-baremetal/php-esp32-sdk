<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\SpecException;

final class BuildInputs
{
    /** Network types the script connects with ext-wifi; the host only starts lwIP and the event loop. */
    private const WIFI_NETWORKS = ['wifi', 'wifi-c6'];
    /** GPIO0-54: the P4, the only chip with an SDMMC microSD slot. */
    private const SDMMC_MAX_GPIO = 54;

    public static function cmakePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new SpecException("cannot create the build directory {$dir}");
        }
    }

    public static function write(BuildPlan $plan, ?Packages $packages = null, ?string $projectVersion = null): string
    {
        self::checkPath('the project directory', $plan->projectDir);
        self::checkPath('the sdk directory', $plan->sdk->dir);
        self::checkPath('the PHP source directory', $plan->phpSourceDir);
        self::checkPath('the build directory', $plan->buildDir);
        foreach ($plan->sourceDirs as $name => $dir) {
            self::checkPath("the source {$name}", $dir);
        }
        $build = $plan->buildDir;
        $board = $plan->board;
        $project = $plan->manifest->project;
        $flashKb = self::megabytesToKilobytes($board->id, $board->flash);
        $envSource = self::envSource($plan->env);
        $network = self::network($plan);
        $driver = self::networkDriver($plan, $network);

        $vars = [
            'SDK_DIR' => self::cmakePath($plan->sdk->dir),
            'PROJECT_DIR' => self::cmakePath($plan->projectDir),
            'PROJECT_NAME' => $project->name,
            'PROJECT_VER' => $projectVersion ?? ProjectVersion::of($plan->projectDir),
            'MANIFEST' => self::cmakePath($plan->projectDir . '/' . Manifest::PATH),
            'BOARD' => $board->id,
            'BOARD_NAME' => $board->name,
            'CHIP' => $board->chip,
            'TARGET' => $plan->chip->target,
            'PSRAM' => $board->psram ?? '',
            'FLASH_KB' => (string) $flashKb,
            'FLASH_MODE' => self::flashMode($plan),
            'REVISION' => self::revision($plan),
            'OPTIMIZATION' => self::optimization($plan),
            'POWER_SAVE_SDKCONFIG' => self::powerSaveSdkconfig($plan),
            'CONSOLE' => $board->console ?? 'uart',
            'RUNTIME' => $plan->runtime->id,
            'STORAGE' => $plan->storage->id,
            'NETWORK' => $network,
            'NETWORK_COMPONENT' => self::networkComponent($plan, $driver),
            'NETWORK_SDKCONFIG' => self::networkSdkconfig($plan, $driver),
            'PHP_VERSION' => $plan->php->version,
            'PHP_MINOR' => $plan->php->minor,
            'PHP_SRC' => self::cmakePath($plan->phpSourceDir),
            'APP_SRC' => self::cmakePath($plan->srcDir()),
            'APP_ENTRY' => $plan->entry(),
            'INCLUDE_DIR' => self::cmakePath("{$build}/include"),
            'ENV_C' => self::cmakePath("{$build}/env.c"),
            'BOARD_SDKCONFIG' => self::cmakePath("{$build}/board.sdkconfig"),
            'SOURCES' => implode(';', array_keys($plan->sourceDirs)),
        ];
        foreach ($plan->sourceDirs as $name => $dir) {
            $vars['SOURCE_' . self::name($name)] = self::cmakePath($dir);
        }
        $extensions = PhpExtensions::fromPlan($plan);
        $vars['PHP_EXTENSIONS'] = implode(';', $extensions->names());
        $vars['PHP_EXT_TABLE'] = $extensions->extensions === [] ? '' : self::cmakePath("{$build}/" . PhpExtensions::TABLE);
        $vars['PHP_EXT_SOURCES'] = implode(';', $extensions->sources);
        $vars['PHP_EXT_INCLUDE_DIRS'] = implode(';', $extensions->includeDirs);
        $vars['PHP_EXT_DEFINES'] = implode(';', $extensions->definitions());
        $vars['PHP_EXT_IDF_REQUIRES'] = implode(';', $extensions->idfRequires);
        self::checkSdmmcPins($plan);
        $peripherals = $board->peripherals;
        ksort($peripherals);
        $vars['PERIPHERALS'] = implode(';', array_keys($peripherals));
        foreach ($peripherals as $peripheral => $block) {
            ksort($block);
            foreach ($block as $key => $value) {
                $vars[self::name($peripheral) . '_' . self::name((string) $key)] = self::scalar($board->id, "{$peripheral}.{$key}", $value);
            }
        }

        $packages ??= Packages::fromPlan($plan);
        foreach ($packages->specs as $spec) {
            self::checkPath("package {$spec->name}", $spec->dir);
        }
        $vars['PACKAGES_COMPONENT'] = self::cmakePath("{$build}/" . Packages::COMPONENT);
        $vars['PARTITIONS'] = implode(';', array_map(static fn (array $p): string => "{$p['name']},{$p['subtype']},{$p['kb']}", $packages->partitions()));
        $vars['PACKAGE_SDKCONFIG'] = implode(';', $packages->sdkconfigFragments());
        $maps = [];
        foreach (self::prefixMaps($plan, $packages) as $dir => $name) {
            $maps[] = "{$dir}={$name}";
        }
        $vars['PREFIX_MAPS'] = implode(';', $maps);

        $cmake = '';
        foreach ($vars as $name => $value) {
            $cmake .= sprintf("set(PHPBM_%s \"%s\")\n", $name, self::cmakeValue($value));
        }

        self::ensureDir($build);
        self::put("{$build}/board.sdkconfig", "CONFIG_ESPTOOLPY_FLASHSIZE_{$board->flash}=y\nCONFIG_ESPTOOLPY_FLASHSIZE=\"{$board->flash}\"\n");
        self::put("{$build}/env.c", $envSource);
        self::ensureDir("{$build}/include");
        self::put("{$build}/include/phpbm_options.h", self::optionsHeader($plan, $network, $driver));
        $packages->writeComponent("{$build}/" . Packages::COMPONENT);
        $table = "{$build}/" . PhpExtensions::TABLE;
        if ($extensions->extensions !== []) {
            self::put($table, $extensions->tableSource());
        } elseif (is_file($table) && !@unlink($table)) {
            throw new SpecException("cannot delete {$table}");
        }
        self::put("{$build}/inputs.cmake", $cmake);

        return self::cmakePath("{$build}/inputs.cmake");
    }

    public static function project(string $build, string $sdkDir): string
    {
        $dir = "{$build}/project";
        self::ensureDir($dir);
        $sdk = self::cmakePath($sdkDir);
        $include = static fn (string $file): string => 'include("' . self::cmakeValue("{$sdk}/host/{$file}") . "\")\n";
        self::put("{$dir}/CMakeLists.txt", "cmake_minimum_required(VERSION 3.22)\n" . $include('project.cmake') . "project(firmware)\n" . $include('post-project.cmake'));
        return self::cmakePath($dir);
    }

    /** Paths end up in CMake lists (split at ';') and in -ffile-prefix-map=<dir>=<name> (split at '='). */
    public static function checkPath(string $what, string $path): void
    {
        if (strpbrk($path, ';=') !== false) {
            throw new SpecException("{$what} ({$path}) contains ';' or '=', which the build cannot handle: move it to a path without them");
        }
    }

    public static function cmakeValue(string $value): string
    {
        return strtr($value, ['\\' => '\\\\', '"' => '\"', '$' => '\$', "\n" => '\n', "\r" => '\r', "\t" => '\t']);
    }

    public static function packageDir(string $projectDir, string $path): string
    {
        $path = self::cmakePath($path);
        if (!str_starts_with($path, '/') && !preg_match('/^[A-Za-z]:/', $path)) {
            $path = self::cmakePath($projectDir) . '/' . (str_starts_with($path, './') ? substr($path, 2) : $path);
        }
        return $path;
    }

    /** @return array<string,string> */
    private static function prefixMaps(BuildPlan $plan, Packages $packages): array
    {
        $maps = [
            self::cmakePath($plan->projectDir) => 'project',
            self::cmakePath($plan->sdk->dir) => 'php-esp32-sdk',
            self::cmakePath($plan->phpSourceDir) => 'php-src',
        ];
        foreach ($plan->sourceDirs as $name => $dir) {
            $maps[self::cmakePath($dir)] = "source/{$name}";
        }
        $maps = array_merge($maps, $packages->prefixMaps());
        uksort($maps, static fn (string $a, string $b): int => strlen($a) <=> strlen($b) ?: strcmp($a, $b));
        return $maps;
    }

    /** Whether the power-save option is on. */
    public static function powerSave(BuildPlan $plan): bool
    {
        return ($plan->options['power-save'] ?? ($plan->sdk->descriptor->options['power-save']->default ?? 'off')) === 'on';
    }

    /** host/sdkconfig/power-save with the power-save option on, '' without. */
    private static function powerSaveSdkconfig(BuildPlan $plan): string
    {
        return self::powerSave($plan) ? self::cmakePath("{$plan->sdk->dir}/host/sdkconfig/power-save") : '';
    }

    /** The optimization option: host/sdkconfig/optimization-<value>. */
    public static function optimization(BuildPlan $plan): string
    {
        return (string) ($plan->options['optimization'] ?? ($plan->sdk->descriptor->options['optimization']->default ?? 'size'));
    }

    /** The board's flash bus: quad (the default), or a mode the chip has a chips/<chip>/sdkconfig.flash-<mode> for. */
    public static function flashMode(BuildPlan $plan): string
    {
        $board = $plan->board;
        $mode = $board->flashMode ?? 'quad';
        if ($mode !== 'quad' && (!preg_match('/^[a-z0-9]+$/D', $mode) || !is_file(self::chipFragment($plan, "flash-{$mode}")))) {
            throw new SpecException("board {$board->id}: chip '{$board->chip}' does not support flash-mode '{$mode}'");
        }
        return $mode;
    }

    /** The chip revision family (chips/<chip>/sdkconfig.rev-<revision>): v1 by default where the chip has them, '' where it has none. */
    public static function revision(BuildPlan $plan): string
    {
        $board = $plan->board;
        if ($board->revision === null) {
            return is_file(self::chipFragment($plan, 'rev-v1')) ? 'v1' : '';
        }
        if (!preg_match('/^[a-z0-9]+$/D', $board->revision) || !is_file(self::chipFragment($plan, "rev-{$board->revision}"))) {
            throw new SpecException("board {$board->id}: chip '{$board->chip}' has no revision '{$board->revision}'");
        }
        return $board->revision;
    }

    /** The SDMMC slot's pins (sdio microSD) must be GPIO numbers of the chip (the P4: 0-54). */
    private static function checkSdmmcPins(BuildPlan $plan): void
    {
        $board = $plan->board;
        $slot = $board->peripherals['microsd'] ?? null;
        if ($plan->storage->id !== 'microsd' || !is_array($slot) || ($slot['type'] ?? null) !== 'sdio') {
            return;
        }
        foreach (['clk', 'cmd', 'd0', 'd1', 'd2', 'd3', 'pwr_en'] as $pin) {
            if (array_key_exists($pin, $slot) && !($pin === 'pwr_en' && $slot[$pin] === -1) && (!is_int($slot[$pin]) || $slot[$pin] < 0 || $slot[$pin] > self::SDMMC_MAX_GPIO)) {
                throw new SpecException(sprintf('board %s: microsd.%s must be a GPIO number from 0 to %d', $board->id, $pin, self::SDMMC_MAX_GPIO));
            }
        }
    }

    private static function chipFragment(BuildPlan $plan, string $suffix): string
    {
        return "{$plan->sdk->dir}/chips/{$plan->board->chip}/sdkconfig.{$suffix}";
    }

    /** The board's network type when the network is on, '' when it is off. */
    public static function network(BuildPlan $plan): string
    {
        $board = $plan->board;
        $by = match (true) {
            ($plan->options['network'] ?? 'off') === 'on' => "option network=on: board '{$board->id}' has no network",
            in_array('network', $plan->runtime->requires->capabilities, true) => "runtime '{$plan->runtime->id}' needs a network, but board '{$board->id}' has none",
            in_array('network', $plan->storage->requires->capabilities, true) => "storage '{$plan->storage->id}' needs a network, but board '{$board->id}' has none",
            default => null,
        };
        if ($by === null) {
            return '';
        }
        if (!$board->has('network')) {
            throw new SpecException($by);
        }
        $type = (string) $board->peripherals['network']['type'];
        if ($type !== 'wifi' && !is_dir("{$plan->sdk->dir}/networks/{$type}")) {
            throw new SpecException("network type '{$type}' of board '{$board->id}' is not supported by this sdk");
        }
        if (in_array($type, self::WIFI_NETWORKS, true) && !$plan->manifest->has('php-baremetal/ext-wifi')) {
            throw new SpecException("board '{$board->id}' connects over Wi-Fi from PHP: composer require php-baremetal/ext-wifi");
        }
        return $type;
    }

    /**
     * The network type whose component and sdkconfig go into the firmware: the active network, or the
     * Wi-Fi companion (wifi-c6) whenever ext-wifi is required, since only its component reaches the radio.
     */
    public static function networkDriver(BuildPlan $plan, string $network): string
    {
        $board = $plan->board;
        $type = $board->has('network') ? (string) $board->peripherals['network']['type'] : '';
        if ($plan->manifest->has('php-baremetal/ext-wifi')
            && !in_array($type, self::WIFI_NETWORKS, true)
            && !in_array('wifi', $plan->chip->capabilities['network'] ?? [], true)) {
            throw new SpecException("board '{$board->id}' has no Wi-Fi: remove php-baremetal/ext-wifi");
        }
        if ($network === '' && $type === 'wifi-c6' && $plan->manifest->has('php-baremetal/ext-wifi')) {
            return $type;
        }
        return $network;
    }

    /** The network sdkconfig fragment (networks/<type>/sdkconfig), '' when there is none. */
    private static function networkSdkconfig(BuildPlan $plan, string $type): string
    {
        $file = "{$plan->sdk->dir}/networks/{$type}/sdkconfig";
        return $type !== '' && is_file($file) ? self::cmakePath($file) : '';
    }

    /** The network component directory (networks/<type>/phpbm_<type>), '' when the network needs none. */
    private static function networkComponent(BuildPlan $plan, string $type): string
    {
        $dir = "{$plan->sdk->dir}/networks/{$type}/phpbm_{$type}";
        return $type !== '' && is_dir($dir) ? self::cmakePath($dir) : '';
    }

    /** @param array<string,string> $env */
    private static function envSource(array $env): string
    {
        $entries = '';
        foreach ($env as $key => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $key)) {
                throw new SpecException("invalid env variable name '{$key}'");
            }
            $entries .= sprintf("    \"%s\", \"%s\",\n", self::c((string) $key), self::c($value));
        }
        return "#include <stddef.h>\n\nconst char *const phpbm_env[] = {\n{$entries}    NULL,\n};\nconst size_t phpbm_env_count = " . count($env) . ";\n";
    }

    private static function optionsHeader(BuildPlan $plan, string $network, string $driver): string
    {
        $sources = ['the sdk' => $plan->sdk->descriptor->options, "runtime {$plan->runtime->id}" => $plan->runtime->options, "storage {$plan->storage->id}" => $plan->storage->options];
        foreach ($plan->phpExtensions as $extension) {
            $sources["php extension {$extension->name}"] = $extension->options;
        }
        $declared = [];
        $owners = [];
        foreach ($sources as $owner => $options) {
            foreach ($options as $name => $option) {
                if (isset($declared[$name])) {
                    throw new SpecException("option '{$name}' is declared by both {$owners[$name]} and {$owner}");
                }
                $declared[$name] = $option;
                $owners[$name] = $owner;
            }
        }
        ksort($declared);
        $header = "#pragma once\n/* Generated by phpbm from extra.baremetal.options. */\n";
        $macros = [];
        $define = static function (string $macro, string $option) use (&$macros): void {
            if (isset($macros[$macro])) {
                throw new SpecException("options '{$macros[$macro]}' and '{$option}' both define the macro {$macro}");
            }
            $macros[$macro] = $option;
        };
        foreach ($declared as $name => $option) {
            $value = $plan->options[$name] ?? $option->default;
            $macro = 'PHPBM_OPTION_' . self::name((string) $name);
            $define($macro, (string) $name);
            if ($option->type === 'enum') {
                $header .= sprintf("#define %s \"%s\"\n", $macro, self::c((string) $value));
                $values = $option->values;
                sort($values);
                foreach ($values as $allowed) {
                    $define("{$macro}_" . self::name($allowed), (string) $name);
                    $header .= sprintf("#define %s_%s %d\n", $macro, self::name($allowed), $allowed === $value ? 1 : 0);
                }
            } elseif ($option->type === 'string') {
                $header .= sprintf("#define %s \"%s\"\n", $macro, self::c((string) $value));
            } elseif ($option->type === 'bool') {
                $header .= sprintf("#define %s %d\n", $macro, $value ? 1 : 0);
            } else {
                $header .= sprintf("#define %s %d\n", $macro, (int) $value);
            }
        }
        $extensionsKb = 0;
        foreach ($plan->phpExtensions as $extension) {
            $header .= sprintf("#define PHPBM_PHP_EXT_%s 1\n", self::name($extension->name));
            $extensionsKb += $extension->resolve($plan->options)['startupKb'];
        }
        $startupKb = $plan->php->startupKb ?? throw new SpecException("sdk.json php.{$plan->php->minor}: 'startup-kb' is required by the esp32 driver");
        // Persistent PSRAM of the engine and of the optional extensions at startup (memory.c budgets it).
        $header .= sprintf("#define PHPBM_PHP_STARTUP_KB %d\n#define PHPBM_PHP_EXT_STARTUP_KB %d\n", $startupKb, $extensionsKb);
        if ($plan->storage->id === 'microsd') {
            $header .= "#define PHPBM_STORAGE_MICROSD 1\n";
        }
        $header .= sprintf(
            "#define PHPBM_NETWORK_ENABLED %d\n#define PHPBM_NETWORK_ACTIVE \"%s\"\n#define PHPBM_NETWORK_UP %d\n#define PHPBM_NETWORK_COMPANION %d\n",
            $network !== '' ? 1 : 0,
            self::c($network),
            self::networkComponent($plan, $network) !== '' && !in_array($network, self::WIFI_NETWORKS, true) ? 1 : 0,
            self::networkComponent($plan, $driver) !== '' && in_array($driver, self::WIFI_NETWORKS, true) ? 1 : 0,
        );
        return $header;
    }

    private static function c(string $value): string
    {
        $escaped = strtr($value, ['\\' => '\\\\', '"' => '\"', "\n" => '\n', "\r" => '\r', "\t" => '\t']);
        return (string) preg_replace_callback('/[\x00-\x1F\x7F]/', static fn (array $m): string => sprintf('\x%02x""', ord($m[0])), $escaped);
    }

    private static function name(string $value): string
    {
        return (string) preg_replace('/[^A-Z0-9]/', '_', strtoupper($value));
    }

    private static function scalar(string $board, string $key, mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'ON' : 'OFF';
        }
        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }
        throw new SpecException("board {$board}: {$key} must be a number or a string");
    }

    private static function megabytesToKilobytes(string $board, string $size): int
    {
        if (!preg_match('/^(\d+)MB$/D', $size, $m)) {
            throw new SpecException("board {$board}: unsupported flash size '{$size}' (use e.g. 4MB)");
        }
        return (int) $m[1] * 1024;
    }

    public static function put(string $path, string $contents): void
    {
        if (is_file($path) && file_get_contents($path) === $contents) {
            return;
        }
        if (@file_put_contents($path, $contents) === false) {
            throw new SpecException("cannot write {$path}");
        }
    }
}
