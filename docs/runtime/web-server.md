---
eyebrow: 'SDK · Runtimes'
lede:    'An HTTP server in the firmware that runs your entry script for every request, each time with a fresh PHP state, like PHP-FPM.'
see_also:
  - { href: './network.md', meta: 'Network' }
  - { href: './storage.md', meta: 'Storage' }
  - { href: './runtimes.md', meta: 'Overview' }
  - { href: 'https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi', meta: 'Extension', label: 'ext-wifi' }
prev: { label: 'event-driven', href: './event-driven.md' }
next: { label: 'Storage', href: './storage.md' }
---

# web-server

Set `"runtime": "web-server"` in `extra.baremetal`. The runtime requires the `network` capability: the board
needs a network type (see [Network](./network.md)), and the network is switched on for you, without the
`network` option. Wired boards get an address by DHCP before PHP starts. On a Wi-Fi board the `init` script
connects with [ext-wifi](https://repository.php-baremetal.com/packages/php-baremetal/ext-wifi).

## Lifecycle

<!-- @steps -->
- **The init script runs (optional)**

  With the `init` option set, that script runs once, before the server starts, with the same time limit as a
  request. If it fails, the program ends.

- **The server starts**

  An HTTP server listens on `port` and the console prints `--- <entry> (web-server) http://<ip>:<port>/ ---`.

- **Each request runs the entry script**

  A request starts a new PHP request, runs the entry script, sends what it printed, and shuts the request down.
  Nothing survives to the next request: no globals, no statics, no open resources.
<!-- @endsteps -->

The runtime never calls `setup()` or `loop()`.

## Options

| Option | Values | Default | Effect |
| --- | --- | --- | --- |
| `init` | A relative `.php` path, or empty | empty | Script run once at boot, before the server starts, for example to connect to Wi-Fi. Relative to the source directory. If the file is missing the program stops with `--- stopped: init script <path> not found ---`. |
| `port` | 1 to 65535 | `80` | TCP port of the server. |
| `request-timeout` | 0 to 3600 (s) | `30` | Time limit for one request; see below. This SDK-wide option is read by this runtime. |

<!-- @code-block language="json" label="composer.json, extra.baremetal" -->
```json
{
  "runtime": "web-server",
  "options": { "init": "connect.php", "port": 8080, "request-timeout": 10 }
}
```
<!-- @endcode-block -->

## Requests and the time limit

The server takes one request at a time: while PHP runs a request, the next one waits, for at most
`request-timeout` plus 5 seconds.

- PHP gets `max_execution_time` equal to `request-timeout`; `0` means no limit.
- If PHP has not answered `request-timeout` plus 5 seconds after the request arrived, the client gets
  `503 Service Unavailable`, and the console prints `phpbm: <method> <uri>: no answer within N s, 503 sent`. PHP
  keeps running the request until it ends; its answer is dropped, and the server takes the next request only
  then.
- A request that waits that long for a free slot also gets 503.

The server answers some requests without running PHP:

| Case | Answer |
| --- | --- |
| A `GET` or `HEAD` for an existing file that is not a `.php` file, under the directory of the entry script | The file, served directly, with a type taken from its extension |
| A path with a `..` segment, a backslash or a NUL after decoding | `404 Not Found` |
| A header block larger than 2048 bytes | `431 Request Header Fields Too Large` |
| A body larger than `post_max_size` | `413 Content Too Large` |
| A body that does not arrive in time (10 s plus 1 s per 16 KB) | `408 Request Timeout` |

Every other path runs the entry script, so the script is also the router: read `$_SERVER['REQUEST_URI']`.
Methods `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, `HEAD` and `OPTIONS` are served; `HEAD` runs like `GET` and
sends the headers and `Content-Length` without the body.

## What PHP sees

| Input | Content |
| --- | --- |
| `$_SERVER` | `REQUEST_METHOD`, `REQUEST_URI`, `QUERY_STRING`, `DOCUMENT_ROOT` (the directory of the entry script), `SERVER_PROTOCOL` (`HTTP/1.1`), `GATEWAY_INTERFACE`, `SERVER_NAME`, `SERVER_PORT`, `REMOTE_ADDR`, `REMOTE_PORT`, `SERVER_ADDR` (when the board has an address), `SERVER_SOFTWARE` (`phpbm`), `SCRIPT_FILENAME`, `SCRIPT_NAME`, `PHP_SELF`, and the environment of the env file |
| Request headers | Only nine are passed: `HTTP_HOST`, `HTTP_USER_AGENT`, `HTTP_ACCEPT`, `HTTP_ACCEPT_LANGUAGE`, `HTTP_COOKIE`, `HTTP_REFERER`, `HTTP_X_REQUESTED_WITH`, `HTTP_AUTHORIZATION` and `CONTENT_TYPE`, each present only when the client sent it. Other headers are not available |
| `CONTENT_LENGTH` | Set when the request has a body |
| `$_GET`, `$_POST`, `$_COOKIE` | Filled by PHP from the query string, the body and the `Cookie` header |
| `php://input` | The raw request body |

The response is what the script prints. `header()`, `http_response_code()` and `setcookie()` work: the status
defaults to 200, and `Content-Type` defaults to `text/html; charset=UTF-8`. At most 48 response headers are
sent; a header past that limit is dropped with a console message.

## Errors

`on-error` has no effect here. An error ends only the request that raised it, and the server keeps serving.

- A fatal error or an uncaught exception answers `500`, and the console prints
  `phpbm: <method> <uri>: <message>`. With `display_errors` on (the firmware default) the error text is the
  body; otherwise the body is `Internal Server Error`.
- An error the engine cannot recover inside the request also answers 500 and the server goes on.
- `exit()` ends the request normally.
- If the output does not fit in memory the answer is 500.

The program ends only when PHP can no longer start a request (the console prints
`phpbm: web-server stopped: PHP cannot start a request`), or when a request restarts the board with
`sys_restart()` or `sys_deep_sleep()`: that request is answered, later ones get 503, and the board restarts or
sleeps. Then `restart-delay` applies as in [Runtimes](./runtimes.md).

<!-- @callout variant="note" title="ext-events does not work here" -->
Every request starts a fresh PHP state, while ext-events keeps its listener table for the whole run. For HTTP
with state kept between requests, use `ext-web` in the [event-driven](./event-driven.md) runtime.
<!-- @endcallout -->

## Example

`new_examples/08-network-web/web-server`: the Waveshare ESP32-P4-ETH board serves its sources from the microSD card.

<!-- @code-block language="json" label="composer.json" -->
```json
{
  "require": {
    "php-baremetal/conductor": "^1.0"
  },
  "extra": {
    "baremetal": {
      "sdk": "esp32@^2.0",
      "board": "waveshare-esp32-p4-eth",
      "runtime": "web-server",
      "storage": "microsd"
    }
  }
}
```
<!-- @endcode-block -->

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
// This runs fresh for every HTTP request, behind the firmware's HTTP server -- the `web-server`
// project type, shared-nothing the way a script runs under Apache / PHP-FPM. You don't manage
// the socket or a loop: just produce the page, and whatever you echo becomes the response body.
// The firmware fills a minimal $_SERVER (method + URI) before running this.

$uri    = $_SERVER['REQUEST_URI']    ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

echo "<!doctype html>\n";
echo "<html><head><meta charset=\"utf-8\"><title>PHP on ESP32-P4</title></head>\n";
echo "<body style=\"font-family:sans-serif;max-width:34rem;margin:3rem auto\">\n";
echo "<h1>Hello from PHP " . PHP_VERSION . "</h1>\n";
echo "<p>This page ran <em>fresh</em> for your request, behind an HTTP server on an"
   . " ESP32-P4 microcontroller.</p>\n";
echo "<ul>\n";
echo "  <li>you requested: <code>" . htmlspecialchars("$method $uri") . "</code></li>\n";
// Each request is a clean PHP run, so there's no counter to keep -- a random number shows
// that this really executed again rather than being cached.
echo "  <li>a fresh random number: " . random_int(1000, 9999) . "</li>\n";
echo "</ul>\n";
echo "</body></html>\n";
```
<!-- @endcode-block -->

`new_examples/08-network-web/json-api` routes on `$_SERVER` and reads a JSON body from `php://input`:

<!-- @code-block language="php" label="project-src/index.php" -->
```php
<?php
// A tiny JSON API in the web-server model: this runs fresh for every request, behind the firmware's
// HTTP server. Route on $_SERVER, return JSON. Point a browser or curl at http://<board-ip>/.

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
