<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests\Support;

use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;
use PhpBaremetal\Spec\Testing\FakeProcess;

final class RecordingProcess implements Process
{
    /** @var list<array{command: list<string>, cwd: ?string, env: array<string,string>}> */
    public array $runs = [];

    public function __construct(public readonly FakeProcess $inner)
    {
    }

    public function run(array $command, ?string $cwd = null, array $env = [], ?Output $out = null): ProcessResult
    {
        $this->runs[] = ['command' => $command, 'cwd' => $cwd, 'env' => $env];
        return $this->inner->run($command, $cwd, $env, $out);
    }

    public function tty(array $command, ?string $cwd = null, array $env = []): int
    {
        return $this->inner->tty($command, $cwd, $env);
    }

    /** @return array{command: list<string>, cwd: ?string, env: array<string,string>} */
    public function last(): array
    {
        return $this->runs[count($this->runs) - 1];
    }
}
