<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\Port;

final readonly class SerialPorts
{
    private const PATTERNS = ['ttyACM*', 'ttyUSB*', 'cu.usbmodem*', 'cu.usbserial*', 'cu.wchusbserial*', 'cu.SLAB_USBtoUART*'];

    public function __construct(private string $dev = '/dev', private string $sysfs = '/sys/class/tty')
    {
    }

    /** @return list<Port> */
    public static function fromPyserial(string $output): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (trim($line) === '' || preg_match('/^\d+ ports? found/', trim($line))) {
                continue;
            }
            if (!preg_match('/^\s/', $line)) {
                if ($current !== null) {
                    $entries[] = $current;
                }
                $current = ['path' => trim($line), 'desc' => null, 'usb' => null];
                continue;
            }
            if ($current === null) {
                continue;
            }
            if (preg_match('/^\s+desc:\s*(.*)$/', $line, $m) && !in_array(trim($m[1]), ['', 'n/a'], true)) {
                $current['desc'] = trim($m[1]);
            } elseif (preg_match('/VID:PID=([0-9A-Fa-f]{4}):([0-9A-Fa-f]{4})/', $line, $m)) {
                $current['usb'] = strtolower("{$m[1]}:{$m[2]}");
            }
        }
        if ($current !== null) {
            $entries[] = $current;
        }

        $ports = [];
        foreach ($entries as $entry) {
            if ($entry['usb'] !== null && !str_starts_with($entry['path'], '/dev/tty.')) {
                $ports[] = new Port($entry['path'], $entry['desc'], $entry['usb']);
            }
        }
        return $ports;
    }

    /** @return list<Port> */
    public function list(): array
    {
        $ports = [];
        foreach (self::PATTERNS as $pattern) {
            $paths = glob("{$this->dev}/{$pattern}") ?: [];
            natsort($paths);
            foreach ($paths as $path) {
                $ports[] = $this->describe($path);
            }
        }
        return $ports;
    }

    private function describe(string $path): Port
    {
        $dir = realpath("{$this->sysfs}/" . basename($path) . '/device');
        while (is_string($dir) && $dir !== '/' && $dir !== '' && $dir !== dirname($dir)) {
            if (is_file("{$dir}/idVendor")) {
                $read = static fn (string $file): string => is_file("{$dir}/{$file}") ? trim((string) file_get_contents("{$dir}/{$file}")) : '';
                $label = trim($read('manufacturer') . ' ' . $read('product'));
                return new Port($path, $label === '' ? null : $label, $read('idVendor') . ':' . $read('idProduct'));
            }
            $dir = dirname($dir);
        }
        return new Port($path);
    }
}
