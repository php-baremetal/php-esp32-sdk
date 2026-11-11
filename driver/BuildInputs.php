<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;
use PhpBaremetal\Spec\SpecException;

final class BuildInputs
{
    /** Network types the script connects with ext-wifi; the host only starts lwIP and the event loop. */
    private const WIFI_NETWORKS = ['wifi', 'wifi-c6'];
    /** The highest GPIO number of each chip (a chip not listed: 54). */
    private const MAX_GPIO = ['esp32s3' => 48, 'esp32p4' => 54];
    /**
     * The pins of the SDK's blocks per type: the ones the build needs ('required'), the ones that have a default
     * ('optional'), and those of either that may be -1 for "not connected" ('none'). A type not listed takes no pin.
     */
    private const BLOCK_PINS = [
        'microsd' => [
            'spi' => ['required' => ['cs', 'sclk', 'mosi', 'miso']],
            'sdio' => ['optional' => ['clk', 'cmd', 'd0', 'd1', 'd2', 'd3', 'pwr_en'], 'none' => ['pwr_en']],
        ],
        'network' => [
            'w5500' => ['required' => ['cs', 'int', 'rst', 'sclk', 'mosi', 'miso'], 'none' => ['rst']],
            'emac' => ['required' => ['rst'], 'none' => ['rst']],
        ],
    ];

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
        self::checkPath('the image directory', self::imageDir($plan));
        foreach ($plan->sourceDirs as $name => $dir) {
            self::checkPath("the source {$name}", $dir);
        }
        self::checkBlocks($plan);
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
            'LOG' => self::log($plan),
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
            'APP_SRC' => self::cmakePath(self::imageDir($plan)),
            'APP_ENTRY' => $plan->entry(),
            'ASSETS_MICROSD' => self::assetsOnCard($plan) ? '1' : '0',
            'INCLUDE_DIR' => self::cmakePath("{$build}/include"),
            'ENV_C' => self::cmakePath("{$build}/env.c"),
            'BOARD_C' => self::cmakePath("{$build}/phpbm_board.c"),
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
        $boardSource = self::boardSource($board);
        $peripherals = $board->peripherals;
        ksort($peripherals);
        $vars['PERIPHERALS'] = implode(';', array_keys($peripherals));
        $blockVars = [];
        $blockKeys = [];
        foreach ($peripherals as $peripheral => $block) {
            ksort($block);
            foreach ($block as $key => $value) {
                $name = self::name($peripheral) . '_' . self::name((string) $key);
                $blockVars[$name] = self::scalar($board->id, "{$peripheral}.{$key}", $value);
                $blockKeys[$name] = "{$peripheral}.{$key}";
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
        // A block (board-overrides can add any) must not set a variable of the build itself, such as
        // PHPBM_SDK_DIR or PHPBM_NETWORK_COMPONENT. The block variables follow PERIPHERALS.
        foreach (array_intersect_key($blockVars, $vars) as $name => $value) {
            throw new SpecException("board {$board->id}: {$blockKeys[$name]} would set PHPBM_{$name}, which the build sets itself: rename the block or the key");
        }
        $at = array_search('PERIPHERALS', array_keys($vars), true) + 1;
        $vars = array_slice($vars, 0, $at, true) + $blockVars + array_slice($vars, $at, null, true);

        $cmake = '';
        foreach ($vars as $name => $value) {
            $cmake .= sprintf("set(PHPBM_%s \"%s\")\n", $name, self::cmakeValue($value));
        }

        self::ensureDir($build);
        self::put("{$build}/board.sdkconfig", "CONFIG_ESPTOOLPY_FLASHSIZE_{$board->flash}=y\nCONFIG_ESPTOOLPY_FLASHSIZE=\"{$board->flash}\"\n");
        self::put("{$build}/env.c", $envSource);
        self::put("{$build}/phpbm_board.c", $boardSource);
        self::ensureDir("{$build}/include");
        self::put("{$build}/include/phpbm_options.h", self::optionsHeader($plan, $network, $driver));
        // phpbm may still vendor a spec whose BuildPlan has no `fonts` property: read it defensively.
        $packages->writeComponent("{$build}/" . Packages::COMPONENT, $plan->fonts ?? []);
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

    /** The log option: host/sdkconfig/log-<value>. */
    public static function log(BuildPlan $plan): string
    {
        return (string) ($plan->options['log'] ?? ($plan->sdk->descriptor->options['log']->default ?? 'info'));
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

    /*
     * phpbm 0.2.x vendors spec 1.2, whose BuildPlan has no imageDir() and no $assets and whose Project has no $assets:
     * the driver reads them through these, so that such a phpbm still builds projects without assets.
     */

    /** The folder that goes into the flash image (sources + embedded assets); the sources with spec 1.2. */
    public static function imageDir(object $plan): string
    {
        return method_exists($plan, 'imageDir') ? $plan->imageDir() : $plan->srcDir();
    }

    /** @return list<\PhpBaremetal\Spec\Sdk\PreparedAsset> every prepared asset of the plan; none with spec 1.2 */
    public static function assets(object $plan): array
    {
        return $plan->assets ?? [];
    }

    /** @return list<object{dir: string, storage: string}> the declared asset folders; none with spec 1.2 */
    public static function assetDirs(object $plan): array
    {
        return ($plan->manifest->project->assets ?? null)?->dirs ?? [];
    }

    /**
     * Whether the project has assets on the microSD card: a declared microsd asset folder, even an empty one (its old
     * files on the card are then cleared), or a prepared microsd asset. The firmware then mounts the card and serves
     * the sync whatever the storage of the code; Driver::sync copies them.
     */
    public static function assetsOnCard(object $plan): bool
    {
        foreach (self::assetDirs($plan) as $dir) {
            if ($dir->storage === 'microsd') {
                return true;
            }
        }
        foreach (self::assets($plan) as $asset) {
            if ($asset->storage === 'microsd') {
                return true;
            }
        }
        return false;
    }

    /**
     * The SDK's blocks of the board (microsd, network), as the build sees them after board-overrides: a type the
     * chip supports, every pin the type needs, each a GPIO number of the chip. Checked whether the build uses the
     * block or not, before anything is written, so that a missing pin is not a C compile error.
     */
    private static function checkBlocks(BuildPlan $plan): void
    {
        $board = $plan->board;
        if (self::assetsOnCard($plan) && !$board->has('microsd')) {
            throw new SpecException("board '{$board->id}' has no microSD slot for the assets of storage 'microsd'");
        }
        $max = self::MAX_GPIO[$board->chip] ?? 54;
        foreach (self::BLOCK_PINS as $name => $types) {
            $block = $board->peripherals[$name] ?? null;
            if (!is_array($block)) {
                continue;
            }
            $type = (string) $block['type'];
            $supported = $plan->chip->capabilities[$name] ?? null;
            if ($supported !== null && !in_array($type, $supported, true)) {
                throw new SpecException(sprintf("board %s: chip '%s' does not support %s '%s' (it supports %s)", $board->id, $board->chip, $name, $type, implode(', ', $supported)));
            }
            $pins = $types[$type] ?? [];
            $required = $pins['required'] ?? [];
            foreach ($required as $pin) {
                if (!array_key_exists($pin, $block)) {
                    throw new SpecException(sprintf("board %s: %s.%s is missing (a '%s' %s needs %s)", $board->id, $name, $pin, $type, $name, implode(', ', $required)));
                }
            }
            $none = $pins['none'] ?? [];
            foreach ([...$required, ...($pins['optional'] ?? [])] as $pin) {
                if (!array_key_exists($pin, $block)) {
                    continue;
                }
                $value = $block[$pin];
                $ok = is_int($value) && (($value >= 0 && $value <= $max) || ($value === -1 && in_array($pin, $none, true)));
                if (!$ok) {
                    throw new SpecException(sprintf('board %s: %s.%s must be a GPIO number from 0 to %d%s', $board->id, $name, $pin, $max, in_array($pin, $none, true) ? ', or -1 for none' : ''));
                }
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

    /** The board blocks as a C table read by packages through bm_board.h. */
    private static function boardSource(Board $board): string
    {
        $peripherals = $board->peripherals;
        ksort($peripherals);
        $entries = '';
        foreach ($peripherals as $block => $keys) {
            ksort($keys);
            foreach ($keys as $key => $value) {
                if (is_bool($value)) {
                    $value = (int) $value;
                }
                if (is_int($value)) {
                    $entries .= sprintf("    { \"%s\", \"%s\", true, %d, NULL },\n", self::c((string) $block), self::c((string) $key), $value);
                } elseif (is_string($value)) {
                    $entries .= sprintf("    { \"%s\", \"%s\", false, 0, \"%s\" },\n", self::c((string) $block), self::c((string) $key), self::c($value));
                } elseif (is_float($value)) {
                    throw new SpecException("board {$board->id}: {$block}.{$key} must be an integer or a string");
                } else {
                    self::scalar($board->id, "{$block}.{$key}", $value);
                }
            }
        }
        if ($entries === '') {
            $entries = "    { NULL, NULL, false, 0, NULL },\n";
        }
        $id = self::c($board->id);
        return <<<C
            #include <stdbool.h>
            #include <stddef.h>
            #include <string.h>

            typedef struct { const char *block, *key; bool is_int; int i; const char *s; } phpbm_board_value_t;

            static const phpbm_board_value_t values[] = {
            {$entries}};

            const char *bm_board_id(void) { return "{$id}"; }

            static const phpbm_board_value_t *find(const char *block, const char *key)
            {
                for (size_t i = 0; i < sizeof values / sizeof values[0]; i++) {
                    if (!values[i].block) { continue; }
                    if (strcmp(values[i].block, block) == 0 && strcmp(values[i].key, key) == 0) {
                        return &values[i];
                    }
                }
                return NULL;
            }

            const char *bm_board_type(const char *block) { const phpbm_board_value_t *v = find(block, "type"); return v ? v->s : NULL; }
            bool bm_board_int(const char *block, const char *key, int *out) { const phpbm_board_value_t *v = find(block, key); if (!v || !v->is_int || !out) { return false; } *out = v->i; return true; }
            const char *bm_board_str(const char *block, const char *key) { const phpbm_board_value_t *v = find(block, key); return v && !v->is_int ? v->s : NULL; }

            C;
    }

    private static function optionsHeader(BuildPlan $plan, string $network, string $driver): string
    {
        $sources = ['the sdk' => $plan->sdk->descriptor->options, "runtime {$plan->runtime->id}" => $plan->runtime->options, "storage {$plan->storage->id}" => $plan->storage->options];
        foreach ($plan->phpExtensions as $extension) {
            $sources["php extension {$extension->name}"] = $extension->options;
        }
        foreach ($plan->manifest->packages as $package) {
            $sources["package {$package->name}"] = $package->options();
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
            } elseif ($option->type === 'set') {
                $chosen = array_values(array_filter($option->values, static fn (string $allowed): bool => in_array($allowed, (array) $value, true)));
                $header .= sprintf("#define %s \"%s\"\n", $macro, self::c(implode(',', $chosen)));
                $values = $option->values;
                sort($values);
                foreach ($values as $allowed) {
                    $define("{$macro}_" . self::name($allowed), (string) $name);
                    $header .= sprintf("#define %s_%s %d\n", $macro, self::name($allowed), in_array($allowed, $chosen, true) ? 1 : 0);
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
        if (self::assetsOnCard($plan)) {
            $header .= "#define PHPBM_ASSETS_MICROSD 1\n";
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

    public static function c(string $value): string
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
