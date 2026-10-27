<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Spec\Testing\DriverConformanceTestCase;

final class DriverConformanceTest extends DriverConformanceTestCase
{
    protected function sdkDir(): string
    {
        return dirname(__DIR__);
    }
}
