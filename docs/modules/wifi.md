---
eyebrow: 'SDK · Modules'
lede:    'Scan, join a network or open the board''s own access point from PHP with ext-wifi. The firmware never connects by itself and keeps no credentials: connecting is a call in your script.'
see_also:
  - { href: '../runtime/network.md', meta: 'Runtime' }
  - { href: '../runtime/web-server.md', meta: 'Runtime' }
  - { href: './web.md', meta: 'Module' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi', meta: 'Extension', label: 'ext-wifi' }
prev: { label: 'Events and timers', href: './events-and-timers.md' }
next: { label: 'HTTP and the web', href: './web.md' }
---

# Wi-Fi

ext-wifi gives the script control of the board's radio. As a station it scans for networks and joins one,
waiting until it has an IP address. As an access point it opens the board's own network, with a DHCP server,
so a phone or laptop joins it directly. Both roles can run at the same time.

## Install

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/ext-wifi
```
<!-- @endcode-block -->

The conductor adds the ESP32 HAL (`esp32-wifi-hal`) for you: never require it. ext-wifi works in every runtime
and does not need ext-events.

## Which boards have Wi-Fi

| Network type | Boards | ext-wifi |
| --- | --- | --- |
| `wifi` | `espressif-esp32-s3-devkitc-1-n16r8`, `-n8r2`, `-n8r8`, `generic-esp32s3-quad`, `generic-esp32s3-octal`, `waveshare-esp32-s3-touch-lcd-1.85b`, `waveshare-esp32-s3-zero`, `waveshare-esp32-s3-zero-n8r8` | Required to get any network |
| `wifi-c6` | `waveshare-esp32-p4-wifi6`: an ESP32-C6 companion chip over SDIO | Required; the firmware starts the link to the companion whenever ext-wifi is installed |
| `w5500` | `waveshare-esp32-s3-eth`: wired, and the ESP32-S3 has a radio | Optional: Wi-Fi then runs next to Ethernet |
| none or wired without a radio | `waveshare-esp32-p4-eth`, `waveshare-esp32-p4-pico`, `generic-esp32p4` | Not supported: the build fails with `board '...' has no Wi-Fi: remove php-baremetal/ext-wifi` |

On a `wifi` or `wifi-c6` board, the `network` option set to `on` (or a runtime that needs the network) without
ext-wifi is a build error: `board '...' connects over Wi-Fi from PHP: composer require php-baremetal/ext-wifi`.
Wired boards get an address before PHP starts; see [Network](../runtime/network.md). `waveshare-esp32-p4-wifi6`
builds with ext-wifi, but Wi-Fi has not been verified on the hardware yet.

## The main functions

| Function | Returns | Meaning |
| --- | --- | --- |
| `wifi_scan()` | `array` or `false` | Networks in range, up to 48: `ssid`, `bssid`, `rssi`, `channel`, `auth`. |
| `wifi_connect(string $ssid, ?string $password = null, int $timeout_ms = 15000)` | `bool` | Join a network and wait for an IP address. |
| `wifi_disconnect()` | `bool` | Leave the network. |
| `wifi_connected()` | `bool` | Whether the station has an IP address. |
| `wifi_ip()`, `wifi_rssi()` | `?string`, `?int` | Station address and signal in dBm; `null` when not connected. |
| `wifi_ap_start(string $ssid, ?string $password = null, int $channel = 1, int $max_conn = 4)` | `bool` | Open the board's own network. |
| `wifi_ap_stop()` | `bool` | Close it. |
| `wifi_ap_ip()`, `wifi_ap_clients()` | `?string`, `int` | Access point address and number of joined stations. |
| `wifi_available()` | `bool` | `true` whenever ext-wifi is built in. |

No function throws for a Wi-Fi failure: each reports it in its return value. Signatures and the other
details are in the [ext-wifi package](https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi).

## Join a network

`new_examples/08-network-web/wifi-connect` scans, then connects with the credentials of the project's env file.
Copy its `.env.example` to `.env` and fill it in; the file is baked into the firmware and read as `$_ENV`, and it
stays out of the sources and out of git (see the
[env file](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md)).

<!-- @code-block language="ini" label=".env.example" -->
```ini
WIFI_SSID=
WIFI_PASSWORD=
```
<!-- @endcode-block -->

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "sdk": "esp32@^2.0",
  "board": "waveshare-esp32-s3-zero-n8r8",
  "runtime": "init-loop",
  "storage": "embedded",
  "env": ".env"
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
$SSID = $_ENV['WIFI_SSID'] ?? '';
$PASS = $_ENV['WIFI_PASSWORD'] ?? '';   // empty for an open network

function setup(): void
{
    global $SSID, $PASS;

    $aps = wifi_scan();   // array of APs, or false if the scan itself failed
    foreach ($aps ?: [] as $ap) {
        printf("  %-32s ch%-3d %4d dBm  %s\n",
               $ap['ssid'] !== '' ? $ap['ssid'] : '(hidden)',
               $ap['channel'], $ap['rssi'], $ap['auth']);
    }

    if ($SSID === '') {
        echo "\nno WIFI_SSID set -- copy .env.example to .env and fill it in to connect\n";
        return;
    }

    echo "\nconnecting to '" . $SSID . "'...\n";
    if (wifi_connect($SSID, $PASS !== '' ? $PASS : null)) {
        echo "connected! IP " . wifi_ip() . "  (" . wifi_rssi() . " dBm)\n";
    } else {
        echo "connect failed -- check the credentials in .env\n";
    }
}

function loop(int $tick): void
{
    if (wifi_connected()) {
        echo "tick $tick: online, IP " . wifi_ip() . ", rssi " . wifi_rssi() . " dBm\n";
    } else {
        echo "tick $tick: offline\n";
    }
    delay(5000);
}
```
<!-- @endcode-block -->

Credentials are never a project setting: the SSID and password go into each call. Besides the env file they
can come from [ext-store](./saving-data.md), for example collected by a setup page on an access point.

## Open an access point

`new_examples/08-network-web/wifi-ap` opens a network and reports how many devices joined. Join it with a phone
or laptop: the board runs a DHCP server, and with the ESP-IDF defaults its own address is `192.168.4.1`.

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
const AP_SSID = 'php-baremetal';
const AP_PASS = 'baremetal';   // >= 8 chars for WPA2; '' (empty) for an open network

function setup(): void
{
    if (wifi_ap_start(AP_SSID, AP_PASS !== '' ? AP_PASS : null)) {
        echo "access point '" . AP_SSID . "' is up at " . wifi_ap_ip() . "\n";
    } else {
        echo "failed to start the access point\n";
    }
}

function loop(int $tick): void
{
    echo "tick $tick: " . wifi_ap_clients() . " device(s) connected to " . AP_SSID . "\n";
    delay(5000);
}
```
<!-- @endcode-block -->

<!-- @callout variant="danger" title="A short password opens the network" -->
An access point password shorter than 8 characters is not an error: the network starts open, with no password.
`null` and `''` also open it. Check the length yourself when the password comes from user input.
<!-- @endcallout -->

For an access point with a web page on it, combine ext-wifi with [ext-web](./web.md) in the `event-driven`
runtime: `new_examples/08-network-web/wifi-ap-s3-rgb-manage` serves a colour control page for the onboard RGB LED
over HTTP and WebSocket from the board's own access point.

## Behaviour on the board

- **Blocking.** `wifi_scan()` waits up to 15 s and `wifi_connect()` up to `$timeout_ms` (a value of 0 or less
  means 15000). The script is blocked meanwhile: under `init-loop` the next `loop()` runs after the call; under
  `event-driven` no event is delivered, and HTTP requests queue up and may time out. The other functions return
  within milliseconds.
- **The first call sets up the radio.** The first scan, connect or access point call initialises the `nvs`
  partition and the Wi-Fi driver. If the partition is full or in a newer format it is erased and formatted
  again. If setup fails, every function reports failure (`false`, `null` or `0`).
- **Which task does the work.** Driver calls run on the executor task (core 0); the PHP task waits for them.
  If the executor queue stays full for 1 s the call fails.
- **Connecting.** All channels are scanned and the strongest access point with that SSID is chosen. With a
  password the minimum is WPA2-PSK, and WPA3 is accepted. The SSID is limited to its first 31 bytes, so a
  32-byte name cannot be joined. A dropped association during the attempt is retried up to 10 times.
- **When the link drops.** The driver retries on its own while retries are left from the last `wifi_connect()`;
  after that the station stays down. Watch `wifi_connected()` and call `wifi_connect()` again.
- **Access point.** The radio follows the station's channel while the station is connected. `$max_conn`
  defaults to 4.
- **Power.** ext-wifi does not change the radio's power mode: the ESP-IDF default (modem sleep while connected)
  applies, with or without the `power-save` option. Wi-Fi with `power-save` on has not been verified on
  hardware. See [Power saving](../reference/power-save.md).

## With the web-server runtime

The [web-server](../runtime/web-server.md) runtime needs a network, and on a Wi-Fi board it does not connect for
you. Connect in the `init` script, which runs once before the server starts and has the same time limit as a
request. If the script fails, the program ends.

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "runtime": "web-server",
  "options": { "init": "connect.php", "port": 8080 }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="project-src/connect.php" -->
```php
<?php
if (!wifi_connect($_ENV['WIFI_SSID'] ?? '', $_ENV['WIFI_PASSWORD'] ?? null)) {
    exit("wifi: connect failed\n");
}
echo "online at ", wifi_ip(), "\n";
```
<!-- @endcode-block -->

With `event-driven` and ext-web, connect or open the access point in the entry script before `serve_http()`.

## Common errors

| Symptom | Cause |
| --- | --- |
| `connect failed` (`wifi_connect()` returns `false`) | Wrong credentials, network not in range or a slow DHCP server, all after `$timeout_ms`. The console prints `sta disconnected, reason=..`. |
| `wifi_scan()` returns `false` | The scan could not start or did not finish within 15 s. |
| The access point has no password | The password was `null`, empty or under 8 characters. |
| `serve_http(): no network interface (turn the network option on, or connect with ext-wifi first)` | `serve_http()` ran before `wifi_connect()` or `wifi_ap_start()`. |
| Build error `has no Wi-Fi: remove php-baremetal/ext-wifi` | The board has no radio. |
| Wi-Fi calls fail on `waveshare-esp32-p4-wifi6` | The ESP32-C6 companion did not answer at boot; the console says so. |
