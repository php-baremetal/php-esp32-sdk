<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\BuildLog;
use PhpBaremetal\Spec\Testing\BufferedOutput;
use PHPUnit\Framework\TestCase;

final class BuildLogTest extends TestCase
{
    public function test_summarises_a_build(): void
    {
        $inner = new BufferedOutput();
        $log = new BuildLog($inner);
        foreach ([
            '-- Configuring done (4.2s)',
            '-- Generating done (0.3s)',
            '[1/200] Building C object a.o',
            '[25/200] Building C object b.o',
            '[40/200] Building C object c.o',
            '../main/boot.c:12:5: error: unknown type name',
            '[200/200] Linking CXX executable app.elf',
            'Generating binary image from built executable',
        ] as $line) {
            $log->line($line);
        }
        $log->warning('careful');

        self::assertSame([
            'step: configuring',
            'step: compiling 10%',
            'step: compiling 20%',
            'line: ../main/boot.c:12:5: error: unknown type name',
            'step: compiling 100%',
            'step: linking',
            'warning: careful',
        ], $inner->lines);
        self::assertSame(['[200/200] Linking CXX executable app.elf', 'Generating binary image from built executable'], $log->tail(2));
    }

    public function test_keeps_only_the_last_200_lines(): void
    {
        $log = new BuildLog(new BufferedOutput());
        for ($i = 1; $i <= 250; $i++) {
            $log->line("line {$i}");
        }
        self::assertSame('line 51', $log->tail(500)[0]);
        self::assertCount(200, $log->tail(500));
    }

    public function test_static_libraries_do_not_announce_linking(): void
    {
        $inner = new BufferedOutput();
        $log = new BuildLog($inner);
        $log->line('[50/200] Linking C static library esp-idf/log/liblog.a');
        self::assertSame(['step: compiling 20%'], $inner->lines);
    }

    public function test_linker_and_cmake_diagnostics_are_shown(): void
    {
        $inner = new BufferedOutput();
        $log = new BuildLog($inner);
        foreach ([
            '-- Configuring incomplete, errors occurred!',
            'main.c:(.text+0x1c): undefined reference to `php_boot\'',
            'ld: region `dram0_0_seg\' overflowed by 1024 bytes',
            'ERROR: the build failed',
            'ninja: build stopped: subcommand failed.',
            '[10/200] FAILED: esp-idf/main/CMakeFiles/x.c.obj',
        ] as $line) {
            $log->line($line);
        }
        self::assertSame([
            'line: -- Configuring incomplete, errors occurred!',
            'line: main.c:(.text+0x1c): undefined reference to `php_boot\'',
            'line: ld: region `dram0_0_seg\' overflowed by 1024 bytes',
            'line: ERROR: the build failed',
            'line: ninja: build stopped: subcommand failed.',
            'line: [10/200] FAILED: esp-idf/main/CMakeFiles/x.c.obj',
        ], $inner->lines);
    }

    public function test_unseen_tail_skips_the_lines_already_forwarded(): void
    {
        $log = new BuildLog(new BufferedOutput());
        foreach (['a', 'x error: y', 'b'] as $line) {
            $log->line($line);
        }
        self::assertSame(['a', 'b'], $log->unseenTail(10));
        self::assertSame([], $log->unseenTail(0));
    }

    public function test_tail_of_nothing(): void
    {
        $log = new BuildLog(new BufferedOutput());
        $log->line('x');
        self::assertSame([], $log->tail(0));
    }
}
