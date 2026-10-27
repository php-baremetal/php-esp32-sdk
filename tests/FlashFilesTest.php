<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\FlashFiles;
use PhpBaremetal\Esp32\Tests\Support\Plans;
use PhpBaremetal\Spec\SpecException;
use PHPUnit\Framework\TestCase;

final class FlashFilesTest extends TestCase
{
    public function test_reads_images_from_flasher_args(): void
    {
        $build = Plans::tempDir();
        mkdir("{$build}/idf", 0777, true);
        file_put_contents("{$build}/idf/flasher_args.json", json_encode(['flash_files' => [
            '0x10000' => 'firmware.bin',
            '0x0' => 'bootloader/bootloader.bin',
            '0x8000' => 'partition_table/partition-table.bin',
        ]]));

        $artifacts = FlashFiles::read($build);

        self::assertSame($build, $artifacts->buildDir);
        self::assertSame([
            0x0 => "{$build}/idf/bootloader/bootloader.bin",
            0x8000 => "{$build}/idf/partition_table/partition-table.bin",
            0x10000 => "{$build}/idf/firmware.bin",
        ], $artifacts->images);
        self::assertSame("{$build}/idf", $artifacts->extra['idf_build_dir']);
    }

    public function test_missing_flasher_args(): void
    {
        $build = Plans::tempDir();
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("{$build}/idf/flasher_args.json is missing or invalid: the build did not complete, run `phpbm build --clean`");
        FlashFiles::read($build);
    }

    public function test_empty_flash_files(): void
    {
        $build = Plans::tempDir();
        mkdir("{$build}/idf", 0777, true);
        file_put_contents("{$build}/idf/flasher_args.json", json_encode(['flash_files' => []]));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("{$build}/idf/flasher_args.json is missing or invalid");
        FlashFiles::read($build);
    }

    public function test_rejects_a_bad_offset(): void
    {
        $build = Plans::tempDir();
        mkdir("{$build}/idf", 0777, true);
        file_put_contents("{$build}/idf/flasher_args.json", json_encode(['flash_files' => ['bad' => 'x.bin']]));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("{$build}/idf/flasher_args.json: invalid offset 'bad'");
        FlashFiles::read($build);
    }

    public function test_keeps_absolute_paths_as_they_are(): void
    {
        $build = Plans::tempDir();
        mkdir("{$build}/idf", 0777, true);
        file_put_contents("{$build}/idf/flasher_args.json", json_encode(['flash_files' => ['0x0' => '/opt/x/boot.bin', '0x1000' => 'C:\\x\\a.bin', '0x2000' => 'a.bin']]));
        self::assertSame([0 => '/opt/x/boot.bin', 0x1000 => 'C:\\x\\a.bin', 0x2000 => "{$build}/idf/a.bin"], FlashFiles::read($build)->images);
    }

    public function test_rejects_a_non_string_path(): void
    {
        $build = Plans::tempDir();
        mkdir("{$build}/idf", 0777, true);
        file_put_contents("{$build}/idf/flasher_args.json", json_encode(['flash_files' => ['0x0' => '']]));
        $this->expectException(SpecException::class);
        $this->expectExceptionMessage("{$build}/idf/flasher_args.json: invalid path ''");
        FlashFiles::read($build);
    }
}
