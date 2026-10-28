---
eyebrow: 'SDK · Modules'
lede:    'Open an I2C bus on two pins, scan it, read chips, and let a driver sample a sensor in the background. Three drivers are packaged: an IMU, a magnetometer and a real-time clock.'
see_also:
  - { href: './events-and-timers.md', meta: 'Module' }
  - { href: '../reference/power-save.md', meta: 'Reference' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c', meta: 'Package', label: 'ext-i2c' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/imu-qmi8658', meta: 'Package', label: 'imu-qmi8658' }
prev: { label: 'LED strips', href: './led-strips.md' }
next: { label: 'SPI and displays', href: './spi-and-displays.md' }
---

# I2C and sensors

[ext-i2c](https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c) is an I2C master. `Baremetal\I2c\Bus`
opens a bus on an SDA and an SCL pin, `Baremetal\I2c\Device` reads and writes one chip, and driver packages add
typed classes under `Baremetal\I2c\Driver\`.

```bash
composer require php-baremetal/ext-i2c          # bus, scan, raw devices
composer require php-baremetal/imu-qmi8658      # QMI8658 accelerometer and gyroscope
composer require php-baremetal/mag-mmc5603      # MMC5603 magnetometer
composer require php-baremetal/rtc-pcf85063     # PCF85063 real-time clock
```

A driver requires ext-i2c, so a driver alone is enough. ext-i2c also pulls in
[ext-events](https://repository.php-baremetal.com/packages/php-baremetal/ext-events), and the conductor adds the
esp32 HAL (`esp32-i2c-hal`).

## Bus and scan

`new Bus(int $sda, int $scl, int $port = -1, int $owner = Bus::SYNC)` opens the bus. A second `new Bus()` with the
same pins returns the same bus, so libraries can each create their own object. `Bus::scan()` probes the
addresses `0x08` to `0x77` and returns the ones that answered, with the name of the driver mounted there.

The example `05-i2c-sensors/i2c-scan` (board `waveshare-esp32-s3-touch-lcd-1.85b`, `init-loop` runtime, only
`ext-i2c` required):

<!-- @code-block language="php" label="05-i2c-sensors/i2c-scan/project-src/index.php" -->
```php
<?php
use Baremetal\I2c\Bus;

const SDA = 11;
const SCL = 10;

$bus = new Bus(sda: SDA, scl: SCL);

printf("I2C scan (sda=%d scl=%d):\n", SDA, SCL);
$found = $bus->scan();
foreach ($found as $addr => $info) {
    printf("  0x%02X  %s\n", $addr, $info['driver'] ?? '(no driver)');
}
printf("%d device(s)\n", count($found));
```
<!-- @endcode-block -->

## Raw devices

`Bus::device(int $address, int $hz = 100000): Device` attaches a chip at a 7-bit address. A `Device` has
`probe()`, `read()`, `write()`, `readReg()` and `writeReg()`. Registers are binary strings: use `ord()` for one
byte and `unpack()` for more.

<!-- @code-block language="php" label="read three registers of a clock chip" -->
```php
<?php
use Baremetal\I2c\Bus;

$rtc = (new Bus(sda: 11, scl: 10))->device(0x51);

if (!$rtc->probe()) {
    exit("no chip at 0x51\n");
}
$raw = $rtc->readReg(0x04, 3);        // seconds, minutes, hours (BCD)
```
<!-- @endcode-block -->

## The drivers

| Package | Class | Default address | Clock | What it gives |
| --- | --- | --- | --- | --- |
| imu-qmi8658 | `Qmi8658` | `0x6B` (`0x6A` when strapped) | 400 kHz | `accel()` in g, `gyro()` in degrees per second, `temp()` in degrees C |
| mag-mmc5603 | `Mmc5603` | `0x30` | 400 kHz | `mag()` in microtesla, `heading()` in degrees (0 to 360, board flat, not calibrated), `temp()` |
| rtc-pcf85063 | `Pcf85063` | `0x51` | 400 kHz | `now()` with `year`, `month`, `day`, `hour`, `minute`, `second` and `valid`; `set()` |

The constructor is `new Qmi8658(Bus $bus, int $address = 0x6B, int $hz = 400000)`, and similarly for the others.
The constructor talks to the chip: it checks the identity and configures it, so it throws if nothing answers.
`valid` of the clock is `false` when it lost power: set the time only then, as in `05-i2c-sensors/rtc-pcf85063`:

<!-- @code-block language="php" label="05-i2c-sensors/rtc-pcf85063/project-src/index.php" -->
```php
<?php
use Baremetal\I2c\Bus;
use Baremetal\I2c\Driver\Pcf85063;

$bus = new Bus(sda: 11, scl: 10);   // onboard I2C
$rtc = new Pcf85063($bus);

if (!$rtc->now()['valid']) {
    $rtc->set(2026, 10, 4, 12, 0, 0);   // only on a fresh (power-lost) RTC
}
```
<!-- @endcode-block -->

The other examples of the group use the same board: `05-i2c-sensors/mmc5603` and `05-i2c-sensors/compass` (a
serial compass; the magnetometer is on SDA 7, SCL 8), and `05-i2c-sensors/rtc-clock`.

## Background sampling and events

A driver that has a sampler (`Qmi8658` and `Mmc5603`) can read the chip on its own, without your code touching
the bus:

| Method | Meaning |
| --- | --- |
| `poll(int $hz, int $depth = 64, ?string $event = null)` | Start sampling at `$hz` into a ring of `$depth` samples (at most 256); the oldest are overwritten when it is full |
| `sample(): ?string` | The latest raw sample, or `null` |
| `drain(): array` | Every unread sample, oldest first, at most 256 per call |
| `decode(string $raw): array` | Turn a raw sample into numbers (`accel` and `gyro` for the IMU) |

With `$event` set to `Baremetal\Sensor\Imu\SamplesReady::class`, every stored sample also raises that event, which the
`event-driven` runtime delivers to your listener on the PHP task. The sampler itself runs on a separate executor
task. The example `05-i2c-sensors/imu-events` requires `ext-events`, `ext-i2c` and `imu-qmi8658`:

<!-- @code-block language="php" label="05-i2c-sensors/imu-events/project-src/index.php" -->
```php
<?php
use Baremetal\Events;
use Baremetal\I2c\Bus;
use Baremetal\I2c\Driver\Qmi8658;
use Baremetal\Sensor\Imu\SamplesReady;

$imu = new Qmi8658(new Bus(sda: 11, scl: 10));

$seen = 0;

Events::listen(SamplesReady::class, function (SamplesReady $e) use (&$seen): void {
    foreach ($e->device->drain() as $raw) {          // every sample since the last event, from the ring
        ['accel' => [$ax, $ay, $az]] = $e->device->decode($raw);
        printf("samples-ready #%d  accel = [% .2f % .2f % .2f] g\n", ++$seen, $ax, $ay, $az);
    }
});

$imu->poll(hz: 10, depth: 32, event: SamplesReady::class);   // 10 Hz
```
<!-- @endcode-block -->

If the event queue is full the event is lost, but the sample stays in the ring, so a handler that calls `drain()`
catches up. `05-i2c-sensors/imu-ws-stream` sends the samples to a browser over a WebSocket.

## Bus::SYNC and Bus::CORE1

The fourth argument of `Bus` says which task drives the wire. It is honoured only when the bus is first created.

| Owner | Who runs the transactions |
| --- | --- |
| `Bus::SYNC` (default) | The PHP task, under a lock per bus |
| `Bus::CORE1` | The executor task: every call from PHP is queued to it and the PHP task waits |

The name describes the ownership mode, not the core: the executor task is pinned to core 0, and PHP runs on core 1.
Both behave the same from PHP. Pick `CORE1` when the bus carries background samplers and you want your own
transactions serialised with them on one task. The example `05-i2c-sensors/i2c-core1` opens
`new Bus(sda: 11, scl: 10, owner: Bus::CORE1)`, scans it and reads the IMU in `loop()`.

<!-- @callout variant="warning" title="Power saving" -->
With the `power-save` option on, `new Bus(..., owner: Bus::CORE1)` throws
`I2cBus::CORE1 polling keeps the CPU busy and defeats power_save; use I2cBus::SYNC`. A sampler on a `SYNC` bus wakes
the chip once per period, so the board never sleeps longer than `1000 / $hz` ms, and a sampler cannot be stopped:
start one only when you need it. See [Power saving](../reference/power-save.md).
<!-- @endcallout -->

## What the ESP32 does

| Behaviour | Detail |
| --- | --- |
| Opening a bus | On the requested port, or the first free one; internal pull-ups on SDA and SCL, glitch filter on, and the bus is reset right after it opens |
| Addressing | 7 bit only |
| Timeout | 1000 ms per transaction; a timeout or an invalid state resets the bus and retries once |
| Probe and scan | Each probe waits 50 ms; a scan stops after 8 consecutive timeouts and throws |
| Limits | 4 buses, 32 devices in all, 8 background samplers in the whole firmware, samples up to 64 bytes, 16 event source classes shared with ext-events |
| Rate | The sampling period is whole milliseconds, so rates above 1000 Hz run at 1000 Hz |
| Lifetime | Buses and devices stay open until the board restarts |

## Common errors

| Message | Cause |
| --- | --- |
| `cannot open I2C bus (sda=.. scl=..): ...` | A pin or the port is used by another bus, no bus slot is free, or the port could not be opened |
| `I2C scan aborted, bus stuck (repeated timeouts on SDA=.. SCL=..): ...` | Missing pull-ups, swapped pins or a chip holding the line |
| `cannot init qmi8658 at 0x..: ESP_ERR_TIMEOUT` | No chip answers at that address (the driver name changes with the class) |
| `cannot init qmi8658 at 0x..: ESP_ERR_NOT_FOUND` | Something answered with the wrong identity |
| `address 0x.. on this bus is already used by another driver` | The address is held by a raw device or by another driver |
| `this device has no poller (driver has no poll block)` | `poll()` on a raw device or on `Pcf85063` |
| `not polling; call poll() first` | `sample()` before `poll()` |
| `cannot start poller (too many, or out of memory)` | Eight samplers are already running |

The full API is on the [ext-i2c](https://repository.php-baremetal.com/packages/php-baremetal/ext-i2c) package page;
each driver package documents its own methods.
