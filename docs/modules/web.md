---
eyebrow: 'SDK · Modules'
lede:    'Serve HTTP and WebSocket from PHP. With ext-web in the event-driven runtime a request is an event and state stays in PHP variables; the web-server runtime runs the script afresh per request.'
see_also:
  - { href: '../runtime/web-server.md', meta: 'Runtime' }
  - { href: '../runtime/event-driven.md', meta: 'Runtime' }
  - { href: './wifi.md', meta: 'Module' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-web', meta: 'Extension', label: 'ext-web' }
prev: { label: 'Wi-Fi', href: './wifi.md' }
next: { label: 'Saving data', href: './saving-data.md' }
---

# HTTP and the web

There are two ways to serve HTTP from PHP on a board. Pick by what the script must remember.

| | ext-web in `event-driven` | `web-server` runtime |
| --- | --- | --- |
| Needs | `composer require php-baremetal/ext-web` | Nothing to require |
| A request is | A `Baremetal\Http\Request` event; the listener returns a `Response` | A fresh run of the entry script that prints the answer, like PHP-FPM |
| State between requests | Kept: variables, objects, open drivers stay | None: no globals, statics or resources survive |
| Other events (timers, sensors) | Same queue, same task | Not supported |
| WebSocket | `serve_ws()` | No |
| Network | You bring it up (`network` option, or ext-wifi) | Switched on for you |

Use `web-server` for pages that depend only on the request. Use ext-web when a request reads a sensor kept open,
a counter, a cache, or when timers and WebSocket frames share the same program. The runtime itself is described
in [web-server](../runtime/web-server.md); the rest of this page covers ext-web.

## Install

<!-- @code-block language="bash" label="in the project folder" -->
```bash
composer require php-baremetal/ext-web
```
<!-- @endcode-block -->

The conductor adds the ESP32 HALs (`esp32-web-hal`, and `esp32-events-hal` through ext-events): never require
them. ext-web needs the `event-driven` runtime, and requires [ext-events](./events-and-timers.md), which comes
in with it. On a Wi-Fi board also require [ext-wifi](./wifi.md); on a wired board set the `network` option to
`on` (see [Network](../runtime/network.md)).

## The main functions and classes

| Name | Meaning |
| --- | --- |
| `serve_http(int $port = 80)` | Start the server; every request becomes a `Request` event. |
| `serve_ws(string $path = '/ws')` | Add a WebSocket endpoint; each inbound frame becomes a `Message` event. |
| `ws_broadcast(string $data)` | Queue a text frame to every connected WebSocket client. |
| `Baremetal\Http\Request` | `$method`, `$path`, `$query`, `$body`, `$ip`, `$headers`; `json()`, `header()`. |
| `Baremetal\Http\Response` | `$status`, `$body`, `$contentType`, `$headers`; helpers `ok()`, `text()`, `html()`, `json()`, `notFound()`, `noContent()`, `header()`. |
| `Baremetal\Http\Message` | `$text`, `$client`, `$binary`; `reply()` answers the sender. |

Routing is plain PHP (`match`, or a router library): the firmware ships no router. Listeners run in
registration order, and the first one that returns an object answers; if it is not a `Response` the client
gets 500. If none returns an object the client gets 404, and with no `Request` listener at all it gets 503.
`$_GET`, `$_POST`, `$_REQUEST` and `$_SERVER` are filled for each request; `$_COOKIE` and `$_FILES` are not.
See the [ext-web package](https://repository.php-baremetal.com/packages/php-baremetal/ext-web) for the full API.

## HTTP as events

`new_examples/07-event-driven/http-events` opens an access point, then serves a page, a JSON status and an echo.
Its `composer.json` requires `ext-events`, `ext-wifi` and `ext-web` on the Waveshare ESP32-S3-Zero (N8R8) with
`"runtime": "event-driven"`.

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
use Baremetal\Events;
use Baremetal\Http\Request;
use Baremetal\Http\Response;

const AP_SSID = 'php-baremetal';
const AP_PASS = 'baremetal';   // >= 8 chars for WPA2; '' for an open network

if (!wifi_available()) {
    echo "wifi not built -- require php-baremetal/ext-wifi on a WiFi-capable board\n";
    return;
}

if (!wifi_ap_start(AP_SSID, AP_PASS !== '' ? AP_PASS : null)) {
    echo "failed to start the access point\n";
    return;
}

$requests = 0;

Events::listen(Request::class, function (Request $r) use (&$requests): Response {
    $requests++;
    return match (true) {
        $r->method === 'GET'  && $r->path === '/' => Response::html(
            "<!doctype html><meta name=viewport content='width=device-width,initial-scale=1'>"
            . "<h1>php-baremetal</h1><p>event-driven HTTP source.</p>"
            . "<p>requests served: {$requests}</p>"
        ),
        $r->method === 'GET'  && $r->path === '/status' => Response::json([
            'requests'   => $requests,
            'ap_clients' => wifi_ap_clients(),
            'ssid'       => AP_SSID,
        ]),
        $r->method === 'POST' && $r->path === '/echo' => Response::json([
            'you_sent' => $r->json(),
        ]),
        default => Response::notFound(),
    };
});

serve_http(80);

printf("AP '%s' up at %s -- join it and open http://%s/\n", AP_SSID, wifi_ap_ip(), wifi_ap_ip());
```
<!-- @endcode-block -->

Register the listener and bring the network up before `serve_http()`, which throws without a network interface.
`$requests` keeps counting between requests because the script never restarts.

Two more examples in `new_examples/08-network-web`:

- `sensor-webpage` (Waveshare ESP32-S3-Touch-LCD-1.85B): a listener reads an I2C magnetometer on each request and
  answers an HTML page that refreshes itself, or JSON at `/heading`. It ties Wi-Fi, HTTP and a sensor together;
  see [I2C and sensors](./i2c-and-sensors.md).
- `wifi-ap-s3-rgb-manage`: an HTTP page plus `serve_ws('/ws')`; a browser drags sliders, the board drives the
  onboard LED and broadcasts the state to every open browser.

## The same JSON API on the web-server runtime

`new_examples/08-network-web/json-api` needs no extension: it runs fresh for each request on the
[web-server](../runtime/web-server.md) runtime of a wired board and answers JSON.

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
header('Content-Type: application/json');

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$reply = match (true) {
    $method === 'GET' && $path === '/' => [
        'service' => 'php-baremetal json-api',
        'php'     => PHP_VERSION,
        'routes'  => ['GET /', 'GET /ping', 'POST /echo'],
    ],
    $method === 'GET' && $path === '/ping' => [
        'pong' => true,
        'rand' => random_int(1000, 9999),
    ],
    $method === 'POST' && $path === '/echo' => [
        'you_sent' => json_decode(file_get_contents('php://input') ?: 'null', true),
    ],
    default => null,
};

if ($reply === null) {
    http_response_code(404);
    $reply = ['error' => 'not found', 'path' => $path];
}

echo json_encode($reply, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
```
<!-- @endcode-block -->

## Behaviour on the board

- **One request at a time.** The HTTP server is one task on core 0 that never calls PHP: it reads the whole
  request, hands it to the reactor and waits. While PHP handles one request the server serves nothing else.
- **The queue.** Requests share the 32-message event queue with every other event. A request that finds it full
  is answered 503 at once; a WebSocket frame waits up to 2 s for room, then is dropped.
- **Requests read in full.** The header block is limited to 2048 bytes (431), the body to `post_max_size` (413),
  and it must arrive within 10 s plus 1 s per 16 KB (408). The body is held in RAM.
- **Headers.** Only eight request headers reach PHP: `Host`, `Content-Type`, `User-Agent`, `Accept`, `Cookie`,
  `Authorization`, `X-Requested-With` and `Accept-Language`. A response carries at most 16 headers besides
  `Content-Type`.
- **Methods.** `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` and `OPTIONS`. For `HEAD` only the status line and
  headers are sent.
- **No stop.** The server lives until the board restarts.
- **WebSocket.** Up to 5 endpoints next to `serve_http()`, and 16 tracked connections. `serve_ws()` starts the
  server on port 80 if `serve_http()` has not run, so call `serve_http($port)` first for another port.
  Sends never block; a client that cannot take data at that moment is skipped for that frame.
- **Power.** Network activity wakes the board from light sleep, so a request is served with `power-save` on.
  See [Power saving](../reference/power-save.md).

## request-timeout

The SDK option `request-timeout` (0 to 3600 seconds, default 30) sets two limits in both HTTP models.

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{ "options": { "request-timeout": 10 } }
```
<!-- @endcode-block -->

| Limit | Value |
| --- | --- |
| `max_execution_time` of the handler | `request-timeout`; `0` means no limit |
| How long the server waits for the answer | `request-timeout` plus 5 s, then the client gets 503 |

What happens past the limit differs by model:

- **ext-web.** Going over `max_execution_time` is a fatal error, and every fatal error stops the
  `event-driven` program: that request gets 500, waiting and later requests get 503 until the board restarts
  after `restart-delay`. An uncaught exception answers 500 and then follows `on-error`: with the default `stop`
  the program ends too; set `"on-error": "continue"` to keep serving.
- **web-server.** The client gets 503 after `request-timeout` plus 5 s, PHP finishes the request and drops its
  answer, and the server goes on. `on-error` has no effect.

<!-- @callout variant="warning" title="Keep handlers short" -->
A slow handler delays timers and other requests, and over `request-timeout` it stops the program. A blocking
Wi-Fi call such as `wifi_connect()` inside a handler counts against the same limit.
<!-- @endcallout -->

## Common errors

| Message or status | Cause |
| --- | --- |
| `serve_http(): no network interface (turn the network option on, or connect with ext-wifi first)` | No network yet: set `network` to `on` on a wired board, or connect or start the access point first on a Wi-Fi board. `serve_ws()` has the same message. |
| `serve_http(): the server already runs on port ..` | The server was started on another port, also by `serve_ws()` (port 80). |
| `serve_ws(): cannot register the WS handler` | The path is already registered, or every route slot is used. |
| 500 `handler did not return a Response` | The first listener that returned an object did not return a `Response`. |
| 503 for every request | The program has stopped (fatal error, `exit()`, restart), the queue is full, or no `Request` listener is registered. |
| 503 after a delay | No answer within `request-timeout` plus 5 s. |
| `\ValueError` from `Response::header()` | An invalid header name, `Content-Length` or `Transfer-Encoding` (set by the server), or a value with CR, LF or NUL. |
