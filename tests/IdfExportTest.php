<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\IdfExport;
use PHPUnit\Framework\TestCase;

final class IdfExportTest extends TestCase
{
    public function test_unix_output(): void
    {
        $output = "IDF_PYTHON_ENV_PATH=/t/python_env/idf6.1_py3.12_env\nESP_IDF_VERSION=6.1\nPATH=/t/tools/xtensa/bin:/idf/tools:\$PATH\nIDF_DEACTIVATE_FILE_PATH=/tmp/x\n";
        self::assertSame([
            'IDF_PYTHON_ENV_PATH' => '/t/python_env/idf6.1_py3.12_env',
            'ESP_IDF_VERSION' => '6.1',
            'PATH' => '/t/tools/xtensa/bin:/idf/tools:/usr/bin:/bin',
        ], IdfExport::parse($output, '/usr/bin:/bin', ':'));
    }

    public function test_windows_output(): void
    {
        $output = "PATH=C:\\t\\xtensa\\bin;C:\\idf\\tools;%PATH%\r\nIDF_PYTHON_ENV_PATH=C:\\t\\python_env\\idf6.1_py3.12_env\r\n";
        self::assertSame([
            'PATH' => 'C:\\t\\xtensa\\bin;C:\\idf\\tools;C:\\Windows',
            'IDF_PYTHON_ENV_PATH' => 'C:\\t\\python_env\\idf6.1_py3.12_env',
        ], IdfExport::parse($output, 'C:\\Windows', ';'));
    }

    public function test_empty_current_path_and_noise(): void
    {
        self::assertSame(['PATH' => '/a'], IdfExport::parse("WARNING: something\nPATH=/a:\$PATH\n", '', ':'));
    }

    public function test_current_path_is_inserted_literally(): void
    {
        $vars = IdfExport::parse("PATH=C:\\a;%PATH%\n", 'C:\\2024\\tools;C:\\T\\$1', ';');
        self::assertSame('C:\\a;C:\\2024\\tools;C:\\T\\$1', $vars['PATH']);
    }
}
