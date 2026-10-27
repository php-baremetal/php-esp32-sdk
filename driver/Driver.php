<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Artifacts;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\ChipInfo;
use PhpBaremetal\Spec\Sdk\Context;
use PhpBaremetal\Spec\Sdk\Driver as DriverContract;
use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Port;
use PhpBaremetal\Spec\SpecException;

final class Driver implements DriverContract
{
    private readonly Toolchain $toolchain;
    private readonly Idf $idf;
    private readonly SerialPorts $ports;

    /** @param array<string,string>|null $environment */
    public function __construct(
        private readonly Context $context,
        ?array $environment = null,
        ?SerialPorts $ports = null,
        ?string $osFamily = null,
    ) {
        $this->toolchain = new Toolchain($context, $environment ?? getenv(), $osFamily ?? PHP_OS_FAMILY);
        $this->idf = new Idf($context->process, $this->toolchain);
        $this->ports = $ports ?? new SerialPorts();
    }

    public function toolchain(): Toolchain
    {
        return $this->toolchain;
    }

    public function build(BuildPlan $plan, Output $out): Artifacts
    {
        if ($this->toolchain->windows()) {
            foreach ([$plan->projectDir, $plan->buildDir, $this->context->sdkDir] as $path) {
                if (preg_match('/[^\x21-\x7E]/', $path)) {
                    throw new SpecException("ESP-IDF cannot build from a path with spaces or non-ASCII characters ({$path}): move the project, or set PHPBM_HOME for the sdk");
                }
            }
        }
        $this->toolchain->env();
        BuildInputs::network($plan);
        $build = $plan->buildDir;
        if ($plan->clean || self::movedCache("{$build}/idf", $this->toolchain->windows())) {
            Files::deleteTree("{$build}/idf");
            Files::deleteTree("{$build}/sdkconfig");
        }
        $packages = Packages::fromPlan($plan);
        $inputs = BuildInputs::write($plan, $packages, ProjectVersion::of($plan->projectDir, $this->context->process));
        $this->refreshCaches($plan, $packages);
        $project = BuildInputs::project($build, $this->context->sdkDir);
        $cmakeBuild = BuildInputs::cmakePath($build);

        $out->step("building for {$plan->board->name}");
        $log = new BuildLog($out);
        $result = $this->idf->idfPy([
            '-C', $project, '-B', "{$cmakeBuild}/idf",
            "-DSDKCONFIG={$cmakeBuild}/sdkconfig",
            "-DPHPBM_INPUTS={$inputs}",
            "-DIDF_TARGET={$plan->chip->target}",
            'build',
        ], $project, $log, ['IDF_TARGET' => $plan->chip->target]);

        if (!$result->successful()) {
            foreach ($log->unseenTail(40) as $line) {
                $out->line($line);
            }
            throw new SpecException(sprintf('the firmware build failed (exit %d): see the output above', $result->exitCode));
        }

        return FlashFiles::read($build);
    }

    public function flash(Artifacts $artifacts, BuildPlan $plan, Port $port, Output $out): void
    {
        $out->step("flashing {$port->path}");
        $result = $this->idf->python([
            '-m', 'esptool', '--chip', $plan->chip->target, '-p', $port->path, '-b', '460800',
            '--before', 'default-reset', '--after', 'hard-reset', 'write-flash', '@flash_args',
        ], (string) ($artifacts->extra['idf_build_dir'] ?? "{$plan->buildDir}/idf"), $out);
        if (!$result->successful()) {
            throw new SpecException(sprintf('flashing %s failed: %s; check the cable, or hold BOOT while pressing RESET and try again', $port->path, Toolchain::lastLine($result)));
        }
    }

    public function sync(BuildPlan $plan, Port $port, Output $out): bool
    {
        if ($plan->storage->id !== 'microsd') {
            return false;
        }
        $out->step("syncing {$plan->manifest->project->src} to the microSD card on {$port->path}");
        $args = ['-u', __DIR__ . '/tools/sync.py', '--port', $port->path];
        if ($plan->manifest->project->env !== null) {
            $args[] = '--exclude';
            $args[] = $plan->projectDir . '/' . $plan->manifest->project->env;
        }
        $args[] = $plan->srcDir();
        $result = $this->idf->python($args, $plan->projectDir, $out, ['PYTHONIOENCODING' => 'utf-8']);
        if (!$result->successful()) {
            $why = (string) preg_replace('/^error: /', '', Toolchain::lastLine($result));
            throw new SpecException(sprintf('syncing to the microSD card on %s failed: %s', $port->path, $why));
        }
        return true;
    }

    public function monitor(Port $port): int
    {
        return $this->idf->pythonTty(['-m', 'esp_idf_monitor', '-p', $port->path, '-b', '115200']);
    }

    public function identify(Port $port): ?ChipInfo
    {
        try {
            $result = $this->idf->python(['-m', 'esptool', '--chip', 'auto', '-p', $port->path, '--connect-attempts', '2', 'flash-id']);
            return $result->successful() ? ChipProbe::parse($result->output) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function ports(): array
    {
        try {
            $result = $this->idf->python(['-m', 'serial.tools.list_ports', '-v']);
            if ($result->successful()) {
                return SerialPorts::fromPyserial($result->output);
            }
        } catch (\Throwable) {
        }
        return $this->ports->list();
    }

    /** A CMake cache written in another directory (a copied project): CMake would refuse it. */
    /** Whether <dir>/CMakeCache.txt was made for another directory (paths compare case-insensitively on Windows). */
    public static function movedCache(string $dir, bool $windows = false): bool
    {
        $cache = @file_get_contents("{$dir}/CMakeCache.txt");
        if ($cache === false || !preg_match('/^CMAKE_CACHEFILE_DIR:INTERNAL=(.*)$/m', $cache, $m)) {
            return false;
        }
        $cached = rtrim($m[1], "\r");
        $same = static fn (string $path): bool => $windows ? strcasecmp($cached, BuildInputs::cmakePath($path)) === 0 : $cached === BuildInputs::cmakePath($path);
        $real = realpath($dir);
        return !$same($dir) && ($real === false || !$same($real));
    }

    private function refreshCaches(BuildPlan $plan, Packages $packages): void
    {
        $board = $plan->board;
        $peripherals = $board->peripherals;
        ksort($peripherals);
        $new = [
            'target' => $plan->chip->target,
            'board' => ['id' => $board->id, 'flash' => $board->flash, 'psram' => $board->psram, 'flashMode' => $board->flashMode, 'revision' => $board->revision, 'console' => $board->console, 'peripherals' => $peripherals],
            'runtime' => $plan->runtime->id,
            'storage' => $plan->storage->id,
            'php' => $plan->php->minor,
            'sdkDir' => $this->context->sdkDir,
            'sdkVersion' => $plan->sdk->descriptor->version,
            'sdkContent' => self::sdkContentHash($plan),
            'idfPath' => $this->toolchain->idfPath(),
            'idfVersion' => $this->toolchain->version(),
            'fragments' => $this->fragmentsHash($plan, $packages),
        ];
        $new = json_decode((string) json_encode($new), true);
        $file = "{$plan->buildDir}/fingerprint.json";
        $old = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if ($old == $new) {
            return;
        }
        if (is_array($old) && array_filter(['target', 'sdkDir', 'sdkVersion', 'sdkContent', 'idfPath', 'idfVersion'], static fn (string $k) => ($old[$k] ?? null) !== $new[$k]) !== []) {
            Files::deleteTree("{$plan->buildDir}/idf");
        }
        Files::deleteTree("{$plan->buildDir}/sdkconfig");
        if (@file_put_contents($file, (string) json_encode($new, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new SpecException("cannot write {$file}");
        }
    }

    /**
     * The sdk sources this build compiles (host components, the PHP minor's compat/patches/descriptors, the
     * runtime, storage and network in use, external source patches). A reinstalled or unpacked sdk keeps
     * old mtimes, so ninja cannot see the change: a different hash starts idf/ from scratch. sdkconfig
     * fragments are left out: fragmentsHash() covers them, and they only drop sdkconfig.
     */
    public static function sdkContentHash(BuildPlan $plan): string
    {
        $dirs = ['host', "php/{$plan->php->minor}", "runtimes/{$plan->runtime->id}", "storages/{$plan->storage->id}", 'sources'];
        $network = BuildInputs::networkDriver($plan, BuildInputs::network($plan));
        if ($network !== '') {
            $dirs[] = "networks/{$network}";
        }
        $hash = hash_init('sha256');
        foreach ($dirs as $dir) {
            hash_update($hash, "{$dir}\0");
            $files = array_filter(
                Packages::treeFiles("{$plan->sdk->dir}/{$dir}"),
                static fn (string $file): bool => !self::isSdkconfigFragment(substr($file, strlen($plan->sdk->dir))),
            );
            Packages::hashFiles($hash, $plan->sdk->dir, array_values($files));
        }
        return hash_final($hash);
    }

    /** sdkconfig, sdkconfig.<x> (not a source such as sdkconfig.cmake) and the files under a sdkconfig/ directory. */
    public static function isSdkconfigFragment(string $relative): bool
    {
        $segments = explode('/', trim(str_replace('\\', '/', $relative), '/'));
        $name = (string) array_pop($segments);
        if (in_array('sdkconfig', $segments, true) || $name === 'sdkconfig') {
            return true;
        }
        return (bool) preg_match('/^sdkconfig\.[^.]+$/D', $name)
            && !in_array(substr($name, 10), ['c', 'cmake', 'h', 'json', 'py', 'txt', 'yml'], true);
    }

    private function fragmentsHash(BuildPlan $plan, Packages $packages): string
    {
        $chip = $plan->board->chip;
        $fragments = [
            'host/sdkconfig.defaults',
            "chips/{$chip}/sdkconfig",
            "chips/{$chip}/sdkconfig.{$plan->board->psram}",
            "chips/{$chip}/sdkconfig.rev-" . BuildInputs::revision($plan),
            "chips/{$chip}/sdkconfig.flash-" . BuildInputs::flashMode($plan),
            'host/sdkconfig/console-' . ($plan->board->console ?? 'uart'),
            'host/sdkconfig/optimization-' . BuildInputs::optimization($plan),
            ...(BuildInputs::powerSave($plan) ? ['host/sdkconfig/power-save'] : []),
            "runtimes/{$plan->runtime->id}/sdkconfig",
            "storages/{$plan->storage->id}/sdkconfig",
        ];
        $network = BuildInputs::networkDriver($plan, BuildInputs::network($plan));
        if ($network !== '') {
            $fragments[] = "networks/{$network}/sdkconfig";
        }
        $hash = hash_init('sha256');
        foreach ($fragments as $fragment) {
            $file = "{$plan->sdk->dir}/{$fragment}";
            if (is_file($file)) {
                $content = (string) file_get_contents($file);
                hash_update($hash, $fragment . "\0" . strlen($content) . "\0" . $content);
            }
        }
        foreach ($packages->sdkconfigFragmentsByPackage() as $name => $file) {
            $content = (string) file_get_contents($file);
            hash_update($hash, "package:{$name}\0" . strlen($content) . "\0" . $content);
        }
        return hash_final($hash);
    }
}
