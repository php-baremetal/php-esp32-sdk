<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Artifacts;
use PhpBaremetal\Spec\SpecException;

final class FlashFiles
{
    public static function read(string $buildDir): Artifacts
    {
        $idf = "{$buildDir}/idf";
        $file = "{$idf}/flasher_args.json";
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data) || !is_array($data['flash_files'] ?? null) || $data['flash_files'] === []) {
            throw new SpecException("{$file} is missing or invalid: the build did not complete, run `phpbm build --clean`");
        }

        $images = [];
        foreach ($data['flash_files'] as $offset => $path) {
            $offset = (string) $offset;
            if (!preg_match('/^0x[0-9a-fA-F]+$/D', $offset)) {
                throw new SpecException("{$file}: invalid offset '{$offset}'");
            }
            if (!is_string($path) || $path === '') {
                throw new SpecException("{$file}: invalid path '" . (is_string($path) ? $path : json_encode($path)) . "'");
            }
            $absolute = str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[A-Za-z]:/', $path);
            $images[(int) hexdec($offset)] = $absolute ? $path : "{$idf}/{$path}";
        }
        ksort($images);

        return new Artifacts($buildDir, $images, ['idf_build_dir' => $idf]);
    }
}
