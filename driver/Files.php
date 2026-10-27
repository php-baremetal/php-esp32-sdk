<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

final class Files
{
    public static function deleteTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666);
            if (!@unlink($path) && is_dir($path)) {
                @rmdir($path);
            }
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::deleteTree("{$path}/{$entry}");
            }
        }
        @rmdir($path);
    }
}
