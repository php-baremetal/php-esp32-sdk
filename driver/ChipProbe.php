<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\ChipInfo;

final class ChipProbe
{
    public static function parse(string $output): ?ChipInfo
    {
        $output = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
        $output = str_replace(["\r\n", "\r"], ["\n", "\n"], $output);
        if (!preg_match('/^(?:Chip is|Chip type:)[ \t]*(\S.*?)(?:[ \t]+\(revision (v?[\d.]+)\))?[ \t]*$/m', $output, $chip)) {
            return null;
        }
        $name = $chip[1];
        if (!preg_match('/^ESP(32|8266)(?:-([SCHP]\d+))?/i', $name, $t)) {
            return null;
        }
        $target = strtolower('esp' . $t[1] . ($t[2] ?? ''));
        $features = self::field($output, 'Features');

        return new ChipInfo(
            $target,
            $name,
            ($chip[2] ?? '') === '' ? null : $chip[2],
            ($size = self::field($output, 'Detected flash size')) === null || strcasecmp($size, 'Unknown') === 0 ? null : $size,
            self::field($output, 'MAC'),
            $features === null ? [] : array_values(array_filter(array_map('trim', explode(',', $features)), static fn ($f) => $f !== '')),
        );
    }

    private static function field(string $output, string $label): ?string
    {
        return preg_match('/^' . preg_quote($label, '/') . ':[ \t]*(\S.*?)[ \t]*$/m', $output, $m) ? $m[1] : null;
    }
}
