<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests;

use PhpBaremetal\Esp32\SerialPorts;
use PhpBaremetal\Esp32\Tests\Support\Plans;
use PHPUnit\Framework\TestCase;

final class SerialPortsTest extends TestCase
{
    public function test_pyserial_on_linux(): void
    {
        $ports = SerialPorts::fromPyserial((string) file_get_contents(__DIR__ . '/fixtures/list-ports-linux.txt'));
        self::assertSame(['/dev/ttyACM0'], array_map(static fn ($p) => $p->path, $ports));
        self::assertSame('USB JTAG/serial debug unit', $ports[0]->description);
        self::assertSame('303a:1001', $ports[0]->usbId);
    }

    public function test_pyserial_on_macos_keeps_cu_devices_only(): void
    {
        $output = "/dev/cu.usbmodem14101\n    desc: USB JTAG/serial debug unit\n    hwid: USB VID:PID=303A:1001 SER=F4:12 LOCATION=20-1\n/dev/tty.usbmodem14101\n    desc: USB JTAG/serial debug unit\n    hwid: USB VID:PID=303A:1001 SER=F4:12 LOCATION=20-1\n/dev/cu.Bluetooth-Incoming-Port\n    desc: n/a\n    hwid: n/a\n3 ports found\n";
        $ports = SerialPorts::fromPyserial($output);
        self::assertSame(['/dev/cu.usbmodem14101'], array_map(static fn ($p) => $p->path, $ports));
        self::assertSame('303a:1001', $ports[0]->usbId);
    }

    public function test_usb_port_with_unknown_description(): void
    {
        $ports = SerialPorts::fromPyserial("/dev/ttyUSB0\n    desc: n/a\n    hwid: USB VID:PID=1A86:7523 LOCATION=1-1\n");
        self::assertNull($ports[0]->description);
        self::assertSame('1a86:7523', $ports[0]->usbId);
    }

    public function test_pyserial_on_windows(): void
    {
        $output = str_replace("\n", "\r\n", (string) file_get_contents(__DIR__ . '/fixtures/list-ports-windows.txt'));
        $ports = SerialPorts::fromPyserial($output);
        self::assertSame('COM3', $ports[0]->path);
        self::assertSame('USB-SERIAL CH340 (COM3)', $ports[0]->description);
        self::assertSame('1a86:7523', $ports[0]->usbId);
    }

    public function test_fallback_lists_known_patterns_with_usb_details(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('symlinks and /dev layout');
        }
        $root = Plans::tempDir();
        mkdir("{$root}/dev", 0777, true);
        foreach (['ttyACM0', 'ttyS0', 'ttyUSB1', 'cu.usbmodem14101', 'ttyACM1', 'ttyACM10', 'ttyACM2'] as $node) {
            touch("{$root}/dev/{$node}");
        }
        mkdir("{$root}/devices/usb1/1-1/1-1:1.0", 0777, true);
        file_put_contents("{$root}/devices/usb1/1-1/idVendor", "303a\n");
        file_put_contents("{$root}/devices/usb1/1-1/idProduct", "1001\n");
        file_put_contents("{$root}/devices/usb1/1-1/manufacturer", "Espressif\n");
        file_put_contents("{$root}/devices/usb1/1-1/product", "USB JTAG/serial debug unit\n");
        mkdir("{$root}/sys/ttyACM0", 0777, true);
        symlink("{$root}/devices/usb1/1-1/1-1:1.0", "{$root}/sys/ttyACM0/device");

        $ports = (new SerialPorts("{$root}/dev", "{$root}/sys"))->list();

        self::assertSame(
            ["{$root}/dev/ttyACM0", "{$root}/dev/ttyACM1", "{$root}/dev/ttyACM2", "{$root}/dev/ttyACM10", "{$root}/dev/ttyUSB1", "{$root}/dev/cu.usbmodem14101"],
            array_map(static fn ($port) => $port->path, $ports),
        );
        self::assertSame('Espressif USB JTAG/serial debug unit', $ports[0]->description);
        self::assertSame('303a:1001', $ports[0]->usbId);
        self::assertNull($ports[1]->usbId);
    }

    public function test_fallback_with_nothing_connected(): void
    {
        $root = Plans::tempDir();
        self::assertSame([], (new SerialPorts($root, $root))->list());
    }
}
