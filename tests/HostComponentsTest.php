<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PHPUnit\Framework\TestCase;

final class HostComponentsTest extends TestCase
{
    public function test_php_component_compiles_without_strict_aliasing(): void
    {
        $cmake = (string) file_get_contents(__DIR__ . '/../host/components/php/CMakeLists.txt');
        self::assertSame(1, preg_match('/target_compile_options\(\$\{COMPONENT_LIB\}\s+PRIVATE\s+([^)]*)\)/', $cmake, $m), 'php component compile options not found');
        self::assertContains('-fno-strict-aliasing', preg_split('/\s+/', trim($m[1])));
    }
}
