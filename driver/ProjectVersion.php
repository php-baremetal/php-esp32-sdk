<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Process;

/** The firmware's PROJECT_VER: composer.json "version", else `git describe` of the project's own repository, else 0.0.0. */
final class ProjectVersion
{
    public const FALLBACK = '0.0.0';
    /** esp_app_desc_t.version is 32 bytes with the terminator. */
    private const MAX = 31;

    public static function of(string $projectDir, ?Process $process = null): string
    {
        $composer = "{$projectDir}/composer.json";
        $data = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
        $version = is_array($data) && is_string($data['version'] ?? null) ? trim($data['version']) : '';
        if ($version === '' && $process !== null && is_dir($projectDir)) {
            $version = self::git($projectDir, $process);
        }
        $version = (string) preg_replace('/[\x00-\x1F\x7F;]/', '', $version);
        return $version === '' ? self::FALLBACK : substr($version, 0, self::MAX);
    }

    /** `git describe` only when the project dir is the top of a repository, not a folder inside another one. */
    private static function git(string $projectDir, Process $process): string
    {
        try {
            $top = $process->run(['git', 'rev-parse', '--show-toplevel'], $projectDir);
            if (!$top->successful() || !self::sameDir(trim($top->output), $projectDir)) {
                return '';
            }
            $result = $process->run(['git', 'describe', '--tags', '--always'], $projectDir);
            return $result->successful() ? trim(explode("\n", trim($result->output))[0]) : '';
        } catch (\Throwable) {
            return '';
        }
    }

    private static function sameDir(string $a, string $b): bool
    {
        $a = realpath($a);
        $b = realpath($b);
        if ($a === false || $b === false) {
            return false;
        }
        return PHP_OS_FAMILY === 'Windows' ? strcasecmp($a, $b) === 0 : $a === $b;
    }
}
