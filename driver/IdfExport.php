<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

final class IdfExport
{
    /** @return array<string,string> */
    public static function parse(string $output, string $currentPath, string $separator): array
    {
        $vars = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', trim($line), $m) || $m[1] === 'IDF_DEACTIVATE_FILE_PATH') {
                continue;
            }
            $value = $m[2];
            if ($m[1] === 'PATH') {
                $stripped = (string) preg_replace('/' . preg_quote($separator, '/') . '(?:\$PATH|%PATH%)$/', '', $value, 1, $count);
                $value = $count > 0 && $currentPath !== '' ? $stripped . $separator . $currentPath : $stripped;
            }
            $vars[$m[1]] = $value;
        }
        return $vars;
    }
}
