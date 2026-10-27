<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\ChipProbe;
use PHPUnit\Framework\TestCase;

final class ChipProbeTest extends TestCase
{
    public function test_esptool_v4(): void
    {
        $chip = ChipProbe::parse((string) file_get_contents(__DIR__ . '/fixtures/esptool-v4.txt'));
        self::assertNotNull($chip);
        self::assertSame('esp32s3', $chip->target);
        self::assertSame('ESP32-S3 (QFN56)', $chip->name);
        self::assertSame('v0.2', $chip->revision);
        self::assertSame('16MB', $chip->flashSize);
        self::assertSame('28:84:85:54:d0:50', $chip->mac);
        self::assertSame(['WiFi', 'BLE', 'Embedded PSRAM 8MB (AP_3v3)'], $chip->features);
    }

    public function test_esptool_v5(): void
    {
        $chip = ChipProbe::parse((string) file_get_contents(__DIR__ . '/fixtures/esptool-v5.txt'));
        self::assertNotNull($chip);
        self::assertSame('esp32p4', $chip->target);
        self::assertSame('ESP32-P4', $chip->name);
        self::assertSame('v1.0', $chip->revision);
        self::assertSame('32MB', $chip->flashSize);
        self::assertSame('60:55:f9:00:00:01', $chip->mac);
        self::assertSame(['Dual Core + LP Core', '400MHz'], $chip->features);
    }

    public function test_windows_line_endings(): void
    {
        $output = str_replace("\n", "\r\n", (string) file_get_contents(__DIR__ . '/fixtures/esptool-v5.txt'));
        self::assertSame('32MB', ChipProbe::parse($output)?->flashSize);
    }

    public function test_without_revision(): void
    {
        $chip = ChipProbe::parse("Chip is ESP32-S3\n");
        self::assertSame('esp32s3', $chip?->target);
        self::assertSame('ESP32-S3', $chip?->name);
        self::assertNull($chip?->revision);
    }

    public function test_classic_esp32_package_names_map_to_esp32(): void
    {
        self::assertSame('esp32', ChipProbe::parse("Chip is ESP32-D0WD-V3 (revision v3.0)\n")?->target);
        self::assertSame('ESP32-D0WD-V3', ChipProbe::parse("Chip is ESP32-D0WD-V3 (revision v3.0)\n")?->name);
        self::assertSame('esp32c61', ChipProbe::parse("Chip type: ESP32-C61 (revision v1.0)\n")?->target);
    }

    public function test_unknown_or_empty_chip_names_give_null(): void
    {
        self::assertNull(ChipProbe::parse("Chip is (QFN56)\n"));
        self::assertNull(ChipProbe::parse("Chip is RP2040\n"));
        self::assertNull(ChipProbe::parse("Chip type:\nFeatures: WiFi\n"));
    }

    public function test_ansi_colours_and_lone_carriage_returns(): void
    {
        $chip = ChipProbe::parse("Connecting...\rChip type:   \e[1mESP32-S3 (QFN56)\e[0m (revision v0.2)\nDetected flash size: 8MB\n");
        self::assertSame('esp32s3', $chip?->target);
        self::assertSame('8MB', $chip?->flashSize);
    }

    public function test_unknown_flash_size_is_null(): void
    {
        self::assertNull(ChipProbe::parse("Chip is ESP32-S3 (QFN56)\nDetected flash size: Unknown\n")?->flashSize);
    }

    public function test_unrelated_output(): void
    {
        self::assertNull(ChipProbe::parse("A fatal error occurred: Failed to connect to Espressif device\n"));
    }
}
