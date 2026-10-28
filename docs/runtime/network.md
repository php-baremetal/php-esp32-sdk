---
eyebrow: 'SDK · Runtimes'
lede:    'The network option, the network type of each board, and what the firmware brings up before PHP starts.'
see_also:
  - { href: './web-server.md', meta: 'Runtime' }
  - { href: '../boards/supported-boards.md', meta: 'Boards' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi', meta: 'Extension', label: 'ext-wifi' }
prev: { label: 'Storage', href: './storage.md' }
next: { label: 'Available modules', href: '../modules/overview.md' }
---

# Network

lwIP and the default event loop are always started, so PHP sockets (`stream_socket_server()`,
`stream_socket_client()`) always find a network stack. What changes is whether the firmware also brings the
board's network interface up before PHP runs.

## The network option

| Option | Values | Default | Effect |
| --- | --- | --- | --- |
| `network` | `off`, `on` | `off` | Bring up the board's network before PHP starts. |

The network is also switched on when the runtime or the storage requires the `network` capability, which only
`web-server` does. The board's `network` block gives the type. The build fails when:

- the network is needed (`network` is `on`, or the runtime requires it) and the board has no `network` block:
  for example `runtime 'web-server' needs a network, but board 'generic-esp32p4' has none`;
- the network is needed on a Wi-Fi board and `php-baremetal/ext-wifi` is not required.

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "runtime": "init-loop",
  "storage": "microsd",
  "options": { "network": "on" }
}
```
<!-- @endcode-block -->

## Network types

| Type | Boards | How it connects |
| --- | --- | --- |
| `w5500` | `waveshare-esp32-s3-eth` | Wired, a W5500 on SPI2 at 20 MHz; the firmware brings it up |
| `emac` | `waveshare-esp32-p4-eth` | Wired, the ESP32-P4 EMAC with an IP101 PHY, PHY reset on GPIO 51; the firmware brings it up |
| `wifi` | The `espressif-esp32-s3-devkitc-1-*`, `generic-esp32s3-*`, `waveshare-esp32-s3-touch-lcd-1.85b` and `waveshare-esp32-s3-zero*` boards | The chip's own radio; the script connects with ext-wifi |
| `wifi-c6` | `waveshare-esp32-p4-wifi6` | An ESP32-C6 companion over SDIO; the script connects with ext-wifi |

`waveshare-esp32-p4-pico` and `generic-esp32p4` have no network block. The chip limits the types: the
ESP32-S3 supports `w5500` and `wifi`, the ESP32-P4 supports `emac` and `wifi-c6`.

## What is up before PHP

<!-- @steps -->
- **Wired boards (w5500, emac)**

  After the storage is mounted, the firmware waits for the link (up to 4 s for `w5500`, 6 s for `emac`), then for
  a DHCP address (up to 15 s). It prints `phpbm: network <type> <ip>`. If a timeout passes it prints
  `phpbm: network <type> without an address` and starts PHP anyway.

- **Wi-Fi boards (wifi, wifi-c6)**

  The firmware connects to nothing and reads no credentials. It prints
  `phpbm: network <type> (connect from PHP with ext-wifi)`. The script scans and connects with
  [ext-wifi](https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi), usually in `setup()` or
  in the `init` script of `web-server`.

- **PHP runs**

  `$_SERVER['SERVER_ADDR']` holds the address of the default interface when it has one.
<!-- @endsteps -->

For `wifi-c6` the firmware starts the link to the ESP32-C6 whenever `ext-wifi` is installed, even with the
`network` option off, because ext-wifi needs it. If the C6 does not answer, the console says so and Wi-Fi
calls fail.

Keep Wi-Fi credentials out of the sources: put them in the project's `env` file, which becomes `$_ENV`
(see the [env file](https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md)).

## sdkconfig fragments

Each network type can add a fragment, `networks/<type>/sdkconfig`, to the firmware configuration, and a component
`networks/<type>/phpbm_<type>`. Only the type in use is added.

| Type | Component | Fragment |
| --- | --- | --- |
| `w5500` | `phpbm_w5500` (espressif/w5500, espressif/wiznet_common) | None |
| `emac` | `phpbm_emac` (espressif/ip101) | None |
| `wifi` | None | None |
| `wifi-c6` | `phpbm_wifi-c6` (esp_hosted, esp_wifi_remote and their dependencies) | The ESP32-C6 target and the Function-EV-Board SDIO wiring, esp_hosted started from the firmware instead of automatically, no restart on a link failure, and larger remote Wi-Fi buffers |
