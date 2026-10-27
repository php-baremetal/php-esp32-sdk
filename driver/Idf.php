<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Output;
use PhpBaremetal\Spec\Sdk\Process;
use PhpBaremetal\Spec\Sdk\ProcessResult;

final readonly class Idf
{
    public function __construct(private Process $process, private Toolchain $toolchain)
    {
    }

    /**
     * @param list<string>          $args
     * @param array<string,string>  $extraEnv
     */
    public function python(array $args, ?string $cwd = null, ?Output $out = null, array $extraEnv = []): ProcessResult
    {
        return $this->process->run([$this->toolchain->python(), ...$args], $cwd, [...$this->toolchain->env(), ...$extraEnv], $out);
    }

    /**
     * @param list<string>          $args
     * @param array<string,string>  $extraEnv
     */
    public function idfPy(array $args, ?string $cwd = null, ?Output $out = null, array $extraEnv = []): ProcessResult
    {
        return $this->python([$this->toolchain->idfPath() . '/tools/idf.py', ...$args], $cwd, $out, $extraEnv);
    }

    /** @param list<string> $args */
    public function pythonTty(array $args, ?string $cwd = null): int
    {
        return $this->process->tty([$this->toolchain->python(), ...$args], $cwd, $this->toolchain->env());
    }
}
