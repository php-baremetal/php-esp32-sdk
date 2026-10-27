<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Output;

final class BuildLog implements Output
{
    private const KEEP = 200;
    private const FORWARD = '/error:|Error:|ERROR:|FAILED:|CMake Error|Configuring incomplete|undefined reference|multiple definition|will not fit in region|region .* overflowed|cannot find -l|No such file or directory|build stopped/';

    /** @var list<array{string,bool}> line and whether it was already forwarded */
    private array $lines = [];
    private int $percent = 0;
    private bool $configuring = false;
    private bool $linking = false;

    public function __construct(private readonly Output $inner)
    {
    }

    public function step(string $message): void
    {
        $this->inner->step($message);
    }

    public function warning(string $message): void
    {
        $this->inner->warning($message);
    }

    public function line(string $message): void
    {
        $forward = preg_match(self::FORWARD, $message) === 1;
        $this->lines[] = [$message, $forward];
        if (count($this->lines) > self::KEEP) {
            array_shift($this->lines);
        }

        if (preg_match('/^\[(\d+)\/(\d+)\]/', $message, $m) && (int) $m[2] > 0) {
            $percent = intdiv((int) $m[1] * 10, (int) $m[2]) * 10;
            if ($percent > $this->percent) {
                $this->percent = $percent;
                $this->inner->step("compiling {$percent}%");
            }
        } elseif (!$this->configuring && !$forward && (str_contains($message, 'Running cmake') || str_starts_with($message, '-- Configuring'))) {
            $this->configuring = true;
            $this->inner->step('configuring');
        }

        if ($forward) {
            $this->inner->line($message);
        }

        if (!$this->linking && preg_match('/Linking (CXX|C) executable|Generating binary image/', $message)) {
            $this->linking = true;
            $this->inner->step('linking');
        }
    }

    /** @return list<string> */
    public function tail(int $count): array
    {
        return $count <= 0 ? [] : array_map(static fn (array $line): string => $line[0], array_slice($this->lines, -$count));
    }

    /** @return list<string> */
    public function unseenTail(int $count): array
    {
        if ($count <= 0) {
            return [];
        }
        $unseen = [];
        foreach ($this->lines as [$message, $forwarded]) {
            if (!$forwarded) {
                $unseen[] = $message;
            }
        }
        return array_slice($unseen, -$count);
    }
}
