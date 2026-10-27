<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests\Support;

use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\FakeProcess;

final class FakeIdf
{
    public static function install(string $idfPath, string $toolsPath, string $version = '6.1.0', bool $windows = false): string
    {
        [$major, $minor, $patch] = explode('.', $version);
        @mkdir("{$idfPath}/tools", 0777, true);
        @mkdir("{$idfPath}/components/esp_common/include", 0777, true);
        touch("{$idfPath}/tools/idf.py");
        touch("{$idfPath}/tools/idf_tools.py");
        file_put_contents("{$idfPath}/components/esp_common/include/esp_idf_version.h", "#define ESP_IDF_VERSION_MAJOR   {$major}\n#define ESP_IDF_VERSION_MINOR   {$minor}\n#define ESP_IDF_VERSION_PATCH   {$patch}\n");

        $venv = "{$toolsPath}/python_env/idf{$major}.{$minor}_py3.12_env";
        $dir = $windows ? 'Scripts' : 'bin';
        @mkdir("{$venv}/{$dir}", 0777, true);
        touch("{$venv}/{$dir}/" . ($windows ? 'python.exe' : 'python'));
        return $venv;
    }

    public static function process(string $idfPath, string $venv, string $toolsPath): FakeProcess
    {
        return (new FakeProcess())
            ->on('python3 --version', new ProcessResult(0, "Python 3.12.3\n"))
            ->on("python3 {$idfPath}/tools/idf_tools.py --non-interactive export", new ProcessResult(0, "IDF_PYTHON_ENV_PATH={$venv}\nPATH={$toolsPath}/tools/xtensa/bin:{$idfPath}/tools:\$PATH\nIDF_DEACTIVATE_FILE_PATH=/tmp/x\n"));
    }
}
