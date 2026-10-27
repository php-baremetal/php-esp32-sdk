#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/stat.h>
#include <unistd.h>

#include "freertos/FreeRTOS.h"
#include "freertos/semphr.h"
#include "esp_heap_caps.h"
#include "esp_timer.h"
#include "esp_http_server.h"
#include "lwip/inet.h"
#include "lwip/sockets.h"

#include "php.h"
#include "php_main.h"
#include "php_variables.h"
#include "SAPI.h"
#include "phpbm.h"
#include "phpbm_options.h"

/* esp_http_server in front, PHP behind: the httpd task (core 0, internal stack) parses the
 * request into the single slot below, wakes the PHP task and waits; the PHP task runs the entry
 * script in a fresh request and captures status, headers and output; the httpd task sends them.
 * The httpd task never calls into PHP and serves static files itself.
 *
 * Each parked request has a sequence number; PHP publishes the number it finished. The httpd task
 * waits at most request-timeout + 5 s, then answers 503 and leaves the slot to PHP: the slot is
 * reused (and the old body freed) only once PHP has finished that request. */

#define WEB_MAX_HDR 48
#define WEB_OUT_KEEP (64 * 1024)
#define WEB_RECV_TIMEOUT_S 5
#define WEB_BODY_BASE_US (10 * 1000000LL)
#define WEB_BODY_RATE (16 * 1024)
#define WEB_REQ_HDR_MAX 2048
#define WEB_WAIT_EXTRA_S 5
#define WEB_FATAL (E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR | E_PARSE)

enum { H_HOST, H_COOKIE, H_CTYPE, H_UA, H_ACCEPT, H_LANG, H_REFERER, H_XRW, H_AUTH, H_COUNT };

static const char *const web_in_names[H_COUNT] = {
    "Host", "Cookie", "Content-Type", "User-Agent", "Accept", "Accept-Language", "Referer", "X-Requested-With",
    "Authorization",
};

typedef struct {
    int method;
    const char *method_str;
    char uri[CONFIG_HTTPD_MAX_URI_LEN + 1];
    char path[CONFIG_HTTPD_MAX_URI_LEN + 1];
    char file[CONFIG_HTTPD_MAX_URI_LEN + 1];
    const char *query;
    /* The captured request headers ("" when absent), stored in in_buf: all of them fit, because
     * httpd answers 431 to a header block longer than WEB_REQ_HDR_MAX. */
    const char *in[H_COUNT];
    char in_buf[WEB_REQ_HDR_MAX];
    char server_name[192];
    char remote_addr[48];
    char remote_port[8];
    char *body;
    size_t body_len;
    size_t body_pos;

    int status;
    char status_line[48];
    char resp_ctype[192];
    char *hdr_buf;
    size_t hdr_len;
    size_t hdr_cap;
    size_t hdr_off[WEB_MAX_HDR];
    int hdr_count;
    char *out;
    size_t out_len;
    size_t out_cap;
    bool out_failed;
} web_slot_t;

static web_slot_t *s_web;
static const char *s_entry;
static char s_init[256];
static char s_docroot[256];
static char s_port[8];
static size_t s_post_max;
static volatile bool s_serving;
static volatile bool s_request_open;
static SemaphoreHandle_t s_req_ready;
static SemaphoreHandle_t s_resp_ready;
static uint32_t s_park_seq;
static uint32_t s_done_seq;
static int64_t s_wait_us;

static const char *web_method_str(int m)
{
    switch (m) {
    case HTTP_POST: return "POST";
    case HTTP_PUT: return "PUT";
    case HTTP_PATCH: return "PATCH";
    case HTTP_DELETE: return "DELETE";
    case HTTP_HEAD: return "HEAD";
    case HTTP_OPTIONS: return "OPTIONS";
    default: return "GET";
    }
}

static const char *web_reason(int code)
{
    switch (code) {
    case 100: return "Continue";
    case 101: return "Switching Protocols";
    case 200: return "OK";
    case 201: return "Created";
    case 202: return "Accepted";
    case 203: return "Non-Authoritative Information";
    case 204: return "No Content";
    case 205: return "Reset Content";
    case 206: return "Partial Content";
    case 207: return "Multi-Status";
    case 300: return "Multiple Choices";
    case 301: return "Moved Permanently";
    case 302: return "Found";
    case 303: return "See Other";
    case 304: return "Not Modified";
    case 307: return "Temporary Redirect";
    case 308: return "Permanent Redirect";
    case 400: return "Bad Request";
    case 401: return "Unauthorized";
    case 402: return "Payment Required";
    case 403: return "Forbidden";
    case 404: return "Not Found";
    case 405: return "Method Not Allowed";
    case 406: return "Not Acceptable";
    case 407: return "Proxy Authentication Required";
    case 408: return "Request Timeout";
    case 409: return "Conflict";
    case 410: return "Gone";
    case 411: return "Length Required";
    case 412: return "Precondition Failed";
    case 413: return "Content Too Large";
    case 414: return "URI Too Long";
    case 415: return "Unsupported Media Type";
    case 416: return "Range Not Satisfiable";
    case 417: return "Expectation Failed";
    case 418: return "I'm a teapot";
    case 419: return "Page Expired";
    case 421: return "Misdirected Request";
    case 422: return "Unprocessable Content";
    case 423: return "Locked";
    case 424: return "Failed Dependency";
    case 425: return "Too Early";
    case 426: return "Upgrade Required";
    case 428: return "Precondition Required";
    case 429: return "Too Many Requests";
    case 431: return "Request Header Fields Too Large";
    case 451: return "Unavailable For Legal Reasons";
    case 500: return "Internal Server Error";
    case 501: return "Not Implemented";
    case 502: return "Bad Gateway";
    case 503: return "Service Unavailable";
    case 504: return "Gateway Timeout";
    case 505: return "HTTP Version Not Supported";
    case 507: return "Insufficient Storage";
    case 511: return "Network Authentication Required";
    default: return "Unknown";
    }
}

static void web_out_reset(void)
{
    if (s_web->out_cap > WEB_OUT_KEEP) {
        heap_caps_free(s_web->out);
        s_web->out = NULL;
        s_web->out_cap = 0;
    }
    if (s_web->hdr_cap > WEB_OUT_KEEP) {
        heap_caps_free(s_web->hdr_buf);
        s_web->hdr_buf = NULL;
        s_web->hdr_cap = 0;
    }
    s_web->out_len = 0;
    s_web->out_failed = false;
    s_web->hdr_len = 0;
    s_web->hdr_count = 0;
}

static bool web_grow(char **buf, size_t *cap, size_t need)
{
    if (need <= *cap) {
        return true;
    }
    size_t size = need * 2 + 1024;
    char *p = heap_caps_realloc(*buf, size, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (p == NULL) {
        return false;
    }
    *buf = p;
    *cap = size;
    return true;
}

static size_t web_ub_write(const char *str, size_t len)
{
    web_slot_t *w = s_web;
    if (w->out_failed) {
        return len;
    }
    if (!web_grow(&w->out, &w->out_cap, w->out_len + len)) {
        w->out_failed = true;
        printf("phpbm: %s %s: no memory for %zu bytes of output\n", w->method_str, w->uri, w->out_len + len);
        return len;
    }
    memcpy(w->out + w->out_len, str, len);
    w->out_len += len;
    return len;
}

static void web_set_body(const char *text)
{
    s_web->out_len = 0;
    s_web->out_failed = false;
    web_ub_write(text, strlen(text));
}

static char *web_read_cookies(void)
{
    return s_web->in[H_COOKIE][0] != '\0' ? (char *) s_web->in[H_COOKIE] : NULL;
}

static size_t web_read_post(char *buffer, size_t count)
{
    web_slot_t *w = s_web;
    size_t n = w->body_len - w->body_pos;
    if (n > count) {
        n = count;
    }
    memcpy(buffer, w->body + w->body_pos, n);
    w->body_pos += n;
    return n;
}

static void web_register_server_vars(zval *arr)
{
    web_slot_t *w = s_web;
    php_import_environment_variables(arr);
    phpbm_server_vars(arr, s_entry);
    php_register_variable("REQUEST_METHOD", w->method_str, arr);
    php_register_variable("REQUEST_URI", w->uri, arr);
    php_register_variable("QUERY_STRING", w->query, arr);
    php_register_variable("DOCUMENT_ROOT", s_docroot, arr);
    php_register_variable("SERVER_PROTOCOL", "HTTP/1.1", arr);
    php_register_variable("GATEWAY_INTERFACE", "CGI/1.1", arr);
    php_register_variable("SERVER_NAME", w->server_name, arr);
    php_register_variable("SERVER_PORT", s_port, arr);
    php_register_variable("REMOTE_ADDR", w->remote_addr, arr);
    php_register_variable("REMOTE_PORT", w->remote_port, arr);
    const struct {
        const char *name;
        const char *value;
    } optional[] = {
        { "HTTP_HOST", w->in[H_HOST] },
        { "HTTP_USER_AGENT", w->in[H_UA] },
        { "HTTP_ACCEPT", w->in[H_ACCEPT] },
        { "HTTP_ACCEPT_LANGUAGE", w->in[H_LANG] },
        { "HTTP_COOKIE", w->in[H_COOKIE] },
        { "HTTP_REFERER", w->in[H_REFERER] },
        { "HTTP_X_REQUESTED_WITH", w->in[H_XRW] },
        { "HTTP_AUTHORIZATION", w->in[H_AUTH] },
        { "CONTENT_TYPE", w->in[H_CTYPE] },
    };
    for (size_t i = 0; i < sizeof(optional) / sizeof(optional[0]); i++) {
        if (optional[i].value[0] != '\0') {
            php_register_variable(optional[i].name, optional[i].value, arr);
        }
    }
    if (w->body_len > 0) {
        char length[16];
        snprintf(length, sizeof(length), "%zu", w->body_len);
        php_register_variable("CONTENT_LENGTH", length, arr);
    }
}

static int web_send_headers(sapi_headers_struct *headers)
{
    web_slot_t *w = s_web;
    w->status = headers->http_response_code != 0 ? headers->http_response_code : 200;
    zend_llist_position pos;
    for (sapi_header_struct *h = zend_llist_get_first_ex(&headers->headers, &pos); h != NULL;
         h = zend_llist_get_next_ex(&headers->headers, &pos)) {
        const char *colon = h->header != NULL ? strchr(h->header, ':') : NULL;
        if (colon == NULL) {
            continue;
        }
        size_t name_len = (size_t) (colon - h->header);
        const char *value = colon + 1;
        while (*value == ' ') {
            value++;
        }
        if (name_len == 12 && strncasecmp(h->header, "Content-Type", 12) == 0) {
            snprintf(w->resp_ctype, sizeof(w->resp_ctype), "%s", value);
        } else {
            size_t value_len = strlen(value);
            if (w->hdr_count >= WEB_MAX_HDR || !web_grow(&w->hdr_buf, &w->hdr_cap, w->hdr_len + name_len + value_len + 2)) {
                printf("phpbm: %s %s: response header %.*s dropped (%s)\n", w->method_str, w->uri, (int) name_len,
                       h->header, w->hdr_count >= WEB_MAX_HDR ? "too many headers" : "no memory");
                continue;
            }
            char *slot = w->hdr_buf + w->hdr_len;
            memcpy(slot, h->header, name_len);
            slot[name_len] = '\0';
            memcpy(slot + name_len + 1, value, value_len + 1);
            w->hdr_off[w->hdr_count++] = w->hdr_len;
            w->hdr_len += name_len + value_len + 2;
        }
    }
    return SAPI_HEADER_SENT_SUCCESSFULLY;
}

static void web_copy(char *dst, size_t size, const char *src)
{
    size_t n = strnlen(src, size - 1);
    memcpy(dst, src, n);
    dst[n] = '\0';
}

static int web_hex(char c)
{
    if (c >= '0' && c <= '9') {
        return c - '0';
    }
    c = (char) (c | 0x20);
    return c >= 'a' && c <= 'f' ? c - 'a' + 10 : -1;
}

/* Splits uri into path and query and percent-decodes the path into file; false when the
 * decoded path is unsafe: a ".." segment, a NUL or a backslash. */
static bool web_split_uri(const char *uri, char *path, char *file, const char **query)
{
    const char *qm = strchr(uri, '?');
    size_t len = qm != NULL ? (size_t) (qm - uri) : strlen(uri);
    memcpy(path, uri, len);
    path[len] = '\0';
    *query = qm != NULL ? qm + 1 : "";

    size_t o = 0;
    for (size_t i = 0; i < len; i++) {
        char c = path[i];
        if (c == '%' && i + 2 < len && web_hex(path[i + 1]) >= 0 && web_hex(path[i + 2]) >= 0) {
            c = (char) (web_hex(path[i + 1]) * 16 + web_hex(path[i + 2]));
            i += 2;
        }
        if (c == '\0' || c == '\\') {
            return false;
        }
        file[o++] = c;
    }
    file[o] = '\0';
    for (const char *p = file; (p = strstr(p, "..")) != NULL; p += 2) {
        if ((p == file || p[-1] == '/') && (p[2] == '\0' || p[2] == '/')) {
            return false;
        }
    }
    return true;
}

static const char *web_mime(const char *path)
{
    static const char *const types[][2] = {
        { "html", "text/html; charset=UTF-8" }, { "htm", "text/html; charset=UTF-8" },
        { "txt", "text/plain; charset=UTF-8" }, { "css", "text/css" },
        { "js", "application/javascript" }, { "mjs", "application/javascript" },
        { "json", "application/json" }, { "map", "application/json" },
        { "xml", "application/xml" }, { "svg", "image/svg+xml" },
        { "png", "image/png" }, { "jpg", "image/jpeg" }, { "jpeg", "image/jpeg" },
        { "gif", "image/gif" }, { "webp", "image/webp" }, { "ico", "image/x-icon" },
        { "woff", "font/woff" }, { "woff2", "font/woff2" }, { "ttf", "font/ttf" },
        { "eot", "application/vnd.ms-fontobject" }, { "pdf", "application/pdf" },
        { "wasm", "application/wasm" },
    };
    const char *dot = strrchr(path, '.');
    if (dot != NULL && strchr(dot, '/') == NULL) {
        for (size_t i = 0; i < sizeof(types) / sizeof(types[0]); i++) {
            if (strcasecmp(dot + 1, types[i][0]) == 0) {
                return types[i][1];
            }
        }
    }
    return "application/octet-stream";
}

static esp_err_t web_send_text(httpd_req_t *req, const char *status, const char *body)
{
    httpd_resp_set_status(req, status);
    httpd_resp_set_type(req, "text/plain; charset=UTF-8");
    return httpd_resp_send(req, body, HTTPD_RESP_USE_STRLEN);
}

static bool web_send_all(httpd_req_t *req, const char *buf, size_t len)
{
    while (len > 0) {
        int r = httpd_send(req, buf, len);
        if (r <= 0) {
            return false;
        }
        buf += r;
        len -= (size_t) r;
    }
    return true;
}

/* A HEAD answer written by hand: httpd_resp_send always sends its buffer and derives
 * Content-Length from it. Headers come from the slot when hdrs is set. */
static esp_err_t web_send_head(httpd_req_t *req, const char *status, const char *ctype, const web_slot_t *hdrs,
                               size_t content_len)
{
    size_t size = strlen(status) + strlen(ctype) + 96;
    int count = hdrs != NULL ? hdrs->hdr_count : 0;
    for (int i = 0; i < count; i++) {
        const char *name = hdrs->hdr_buf + hdrs->hdr_off[i];
        size += strlen(name) + strlen(name + strlen(name) + 1) + 4;
    }
    char *head = heap_caps_malloc(size, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (head == NULL) {
        return ESP_FAIL;
    }
    size_t n = (size_t) snprintf(head, size, "HTTP/1.1 %s\r\nContent-Type: %s\r\n", status, ctype);
    for (int i = 0; i < count; i++) {
        const char *name = hdrs->hdr_buf + hdrs->hdr_off[i];
        n += (size_t) snprintf(head + n, size - n, "%s: %s\r\n", name, name + strlen(name) + 1);
    }
    n += (size_t) snprintf(head + n, size - n, "Content-Length: %zu\r\n\r\n", content_len);
    bool sent = web_send_all(req, head, n);
    heap_caps_free(head);
    return sent ? ESP_OK : ESP_FAIL;
}

/* An existing non-.php file under the document root is served from here, without PHP. A path
 * ending in a dot or a space is never a file (FatFs strips those, so "index.php." would open
 * index.php): it goes to the front controller. */
static bool web_try_static(httpd_req_t *req, const char *file)
{
    size_t len = strlen(file);
    if (len < 2 || file[len - 1] == '/' || file[len - 1] == '.' || file[len - 1] == ' ' ||
        (len >= 4 && strcasecmp(file + len - 4, ".php") == 0)) {
        return false;
    }
    char path[sizeof(s_docroot) + CONFIG_HTTPD_MAX_URI_LEN + 1];
    snprintf(path, sizeof(path), "%s%s", s_docroot, file);
    struct stat st;
    if (stat(path, &st) != 0 || !S_ISREG(st.st_mode)) {
        return false;
    }
    FILE *f = fopen(path, "rb");
    if (f == NULL) {
        return false;
    }
    if (req->method == HTTP_HEAD) {
        fclose(f);
        if (web_send_head(req, "200 OK", web_mime(file), NULL, (size_t) st.st_size) != ESP_OK) {
            printf("phpbm: HEAD %s: send failed\n", file);
        }
        return true;
    }
    httpd_resp_set_type(req, web_mime(file));
    char *buf = malloc(4096);
    if (buf == NULL) {
        fclose(f);
        web_send_text(req, "500 Internal Server Error", "Internal Server Error");
        return true;
    }
    size_t n;
    while ((n = fread(buf, 1, 4096, f)) > 0 && httpd_resp_send_chunk(req, buf, n) == ESP_OK) {
    }
    httpd_resp_send_chunk(req, NULL, 0);
    free(buf);
    fclose(f);
    return true;
}

static void web_peer(httpd_req_t *req, web_slot_t *w)
{
    strcpy(w->remote_addr, "0.0.0.0");
    strcpy(w->remote_port, "0");
    struct sockaddr_storage sa;
    socklen_t len = sizeof(sa);
    if (getpeername(httpd_req_to_sockfd(req), (struct sockaddr *) &sa, &len) != 0) {
        return;
    }
    if (sa.ss_family == AF_INET6) {
        struct sockaddr_in6 *s6 = (struct sockaddr_in6 *) &sa;
        inet_ntop(AF_INET6, &s6->sin6_addr, w->remote_addr, sizeof(w->remote_addr));
        snprintf(w->remote_port, sizeof(w->remote_port), "%u", ntohs(s6->sin6_port));
        if (strncasecmp(w->remote_addr, "::ffff:", 7) == 0) {
            memmove(w->remote_addr, w->remote_addr + 7, strlen(w->remote_addr + 7) + 1);
        }
    } else {
        struct sockaddr_in *s4 = (struct sockaddr_in *) &sa;
        inet_ntop(AF_INET, &s4->sin_addr, w->remote_addr, sizeof(w->remote_addr));
        snprintf(w->remote_port, sizeof(w->remote_port), "%u", ntohs(s4->sin_port));
    }
}

/* Reads the whole body into the slot; NULL on success, else the error status line. */
static const char *web_read_body(httpd_req_t *req, web_slot_t *w)
{
    w->body = NULL;
    w->body_len = 0;
    w->body_pos = 0;
    if (req->content_len == 0) {
        return NULL;
    }
    if (s_post_max > 0 && req->content_len > s_post_max) {
        return "413 Content Too Large";
    }
    w->body = heap_caps_malloc(req->content_len, MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    if (w->body == NULL) {
        return "413 Content Too Large";
    }
    /* One recv timeout ends the read, and a wall-clock deadline stops a client that drips bytes
     * just fast enough to never time out. */
    int64_t deadline = esp_timer_get_time() + WEB_BODY_BASE_US + (int64_t) req->content_len * 1000000LL / WEB_BODY_RATE;
    while (w->body_len < req->content_len) {
        int r = httpd_req_recv(req, w->body + w->body_len, req->content_len - w->body_len);
        if (r <= 0 || esp_timer_get_time() > deadline) {
            heap_caps_free(w->body);
            w->body = NULL;
            return r > 0 || r == HTTPD_SOCK_ERR_TIMEOUT ? "408 Request Timeout" : "400 Bad Request";
        }
        w->body_len += (size_t) r;
    }
    return NULL;
}

/* Copies the captured request headers into the slot; false if they do not fit (never with httpd's own
 * limit, which is the size of in_buf). */
static bool web_read_headers(httpd_req_t *req, web_slot_t *w)
{
    size_t used = 0;
    for (int i = 0; i < H_COUNT; i++) {
        w->in[i] = "";
        size_t len = httpd_req_get_hdr_value_len(req, web_in_names[i]);
        if (len == 0) {
            continue;
        }
        if (used + len + 1 > sizeof(w->in_buf) ||
            httpd_req_get_hdr_value_str(req, web_in_names[i], w->in_buf + used, len + 1) != ESP_OK) {
            return false;
        }
        w->in[i] = w->in_buf + used;
        used += len + 1;
    }
    return true;
}

/* Waits until PHP has finished request seq; false when the deadline (0: none) passed or the server
 * stopped. A stale wake-up only costs one more turn of the loop. */
static bool web_wait_php(uint32_t seq, int64_t deadline)
{
    while (__atomic_load_n(&s_done_seq, __ATOMIC_ACQUIRE) != seq) {
        if (!s_serving) {
            return false;
        }
        TickType_t wait = pdMS_TO_TICKS(1000);
        if (deadline != 0) {
            int64_t left = deadline - esp_timer_get_time();
            if (left <= 0) {
                return false;
            }
            if (left < 1000000) {
                wait = pdMS_TO_TICKS(left / 1000) + 1;
            }
        }
        xSemaphoreTake(s_resp_ready, wait);
    }
    return true;
}

static int64_t web_deadline(void)
{
    return s_wait_us > 0 ? esp_timer_get_time() + s_wait_us : 0;
}

static esp_err_t web_handle(httpd_req_t *req)
{
    if (!s_serving) {
        return web_send_text(req, "503 Service Unavailable", "Service Unavailable");
    }
    /* Static files and 404s never wait for PHP: they use the httpd task's own buffers. */
    static char uri[CONFIG_HTTPD_MAX_URI_LEN + 1];
    static char path[CONFIG_HTTPD_MAX_URI_LEN + 1];
    static char file[CONFIG_HTTPD_MAX_URI_LEN + 1];
    const char *query;
    web_copy(uri, sizeof(uri), req->uri);
    if (!web_split_uri(uri, path, file, &query)) {
        return web_send_text(req, "404 Not Found", "Not Found");
    }
    if ((req->method == HTTP_GET || req->method == HTTP_HEAD) && web_try_static(req, file)) {
        return ESP_OK;
    }

    web_slot_t *w = s_web;
    /* The slot still belongs to PHP while it runs a request whose client already got 503. */
    uint32_t seq = __atomic_load_n(&s_park_seq, __ATOMIC_RELAXED);
    if (!web_wait_php(seq, web_deadline())) {
        return web_send_text(req, "503 Service Unavailable", "Service Unavailable");
    }
    heap_caps_free(w->body);
    w->body = NULL;

    w->method = req->method;
    w->method_str = web_method_str(req->method);
    memcpy(w->uri, uri, sizeof(w->uri));
    memcpy(w->path, path, sizeof(w->path));
    memcpy(w->file, file, sizeof(w->file));
    w->query = query[0] != '\0' ? w->uri + (query - uri) : "";

    if (!web_read_headers(req, w)) {
        return web_send_text(req, "431 Request Header Fields Too Large", "Request Header Fields Too Large");
    }
    web_copy(w->server_name, sizeof(w->server_name), w->in[H_HOST][0] != '\0' ? w->in[H_HOST] : "localhost");
    char *colon = strchr(w->server_name, ':');
    if (colon != NULL) {
        *colon = '\0';
    }
    web_peer(req, w);
    const char *body_err = web_read_body(req, w);
    if (body_err != NULL) {
        /* The unread body stays in the socket: answer, then fail so httpd closes the connection
         * instead of draining it. */
        httpd_resp_set_hdr(req, "Connection", "close");
        web_send_text(req, body_err, body_err + 4);
        return ESP_FAIL;
    }

    w->status = 200;
    w->resp_ctype[0] = '\0';
    seq++;
    __atomic_store_n(&s_park_seq, seq, __ATOMIC_RELEASE);
    xSemaphoreGive(s_req_ready);
    if (!web_wait_php(seq, web_deadline())) {
        /* PHP keeps the slot (and the body) until it finishes; its answer is dropped. */
        if (s_serving) {
            printf("phpbm: %s %s: no answer within %lld s, 503 sent\n", w->method_str, w->uri,
                   (long long) (s_wait_us / 1000000));
        }
        return web_send_text(req, "503 Service Unavailable", "Service Unavailable");
    }

    if (w->status < 100 || w->status > 599) {
        w->status = 500;
    }
    snprintf(w->status_line, sizeof(w->status_line), "%d %s", w->status, web_reason(w->status));
    const char *ctype = w->resp_ctype[0] != '\0' ? w->resp_ctype : "text/html; charset=UTF-8";
    esp_err_t err;
    if (req->method == HTTP_HEAD) {
        err = web_send_head(req, w->status_line, ctype, w, w->out_len);
    } else {
        httpd_resp_set_status(req, w->status_line);
        httpd_resp_set_type(req, ctype);
        for (int i = 0; i < w->hdr_count; i++) {
            const char *name = w->hdr_buf + w->hdr_off[i];
            httpd_resp_set_hdr(req, name, name + strlen(name) + 1);
        }
        err = httpd_resp_send(req, w->out != NULL ? w->out : "", (ssize_t) w->out_len);
    }
    heap_caps_free(w->body);
    w->body = NULL;
    return err;
}

static void web_request_clear(void)
{
    SG(server_context) = NULL;
    memset(&SG(request_info), 0, sizeof(SG(request_info)));
}

/* Runs the parked request; false when PHP cannot start a request any more. */
static bool web_serve_one(void)
{
    web_slot_t *w = s_web;
    web_out_reset();
    web_request_clear();
    SG(server_context) = w;
    SG(request_info).request_method = w->method_str;
    SG(request_info).request_uri = w->path;
    SG(request_info).query_string = (char *) w->query;
    SG(request_info).content_type = w->in[H_CTYPE][0] != '\0' ? w->in[H_CTYPE] : NULL;
    SG(request_info).content_length = (zend_long) w->body_len;
    SG(sapi_headers).http_response_code = 200;

    bool started = php_request_startup() == SUCCESS;
    if (started) {
        s_request_open = true;
        SG(request_info).proto_num = 1001;
        /* HEAD runs like GET so the answer carries the real Content-Length; the body is not sent. */
        SG(request_info).headers_only = 0;
        volatile bool ok = false;
        zend_try {
            ok = phpbm_run_file(s_entry);
            if (!SG(headers_sent)) {
                ok = sapi_send_headers() == SUCCESS && ok;
            }
        } zend_catch {
            ok = false;
        } zend_end_try();
        bool fatal = !ok && PG(last_error_message) != NULL && (PG(last_error_type) & WEB_FATAL) != 0;
        bool display = PG(display_errors) != 0;
        if (fatal) {
            printf("phpbm: %s %s: %s\n", w->method_str, w->uri, ZSTR_VAL(PG(last_error_message)));
        }
        php_request_shutdown(NULL);
        s_request_open = false;
        if (fatal || w->out_failed) {
            w->status = 500;
            if (w->out_failed) {
                w->hdr_count = 0;
            }
            if (!display || w->out_failed) {
                strcpy(w->resp_ctype, "text/plain; charset=UTF-8");
                web_set_body("Internal Server Error");
            }
        }
    }
    web_request_clear();
    return started;
}

static httpd_handle_t web_start(void)
{
    httpd_config_t cfg = HTTPD_DEFAULT_CONFIG();
    cfg.server_port = PHPBM_OPTION_PORT;
    cfg.core_id = 0;
    cfg.task_caps = MALLOC_CAP_INTERNAL | MALLOC_CAP_8BIT;
    cfg.stack_size = 8192;
    cfg.uri_match_fn = httpd_uri_match_wildcard;
    cfg.lru_purge_enable = true;
    cfg.max_uri_handlers = 8;
    cfg.max_resp_headers = WEB_MAX_HDR;
    cfg.max_req_hdr_len = WEB_REQ_HDR_MAX;
    cfg.recv_wait_timeout = WEB_RECV_TIMEOUT_S;
    httpd_handle_t server = NULL;
    esp_err_t err = httpd_start(&server, &cfg);
    if (err != ESP_OK) {
        printf("phpbm: web-server cannot listen on port %d: %s\n", PHPBM_OPTION_PORT, esp_err_to_name(err));
        return NULL;
    }
    static const httpd_method_t methods[] = { HTTP_GET, HTTP_POST, HTTP_PUT, HTTP_PATCH, HTTP_DELETE, HTTP_HEAD, HTTP_OPTIONS };
    for (size_t i = 0; i < sizeof(methods) / sizeof(methods[0]); i++) {
        httpd_uri_t uri = { .uri = "/*", .method = methods[i], .handler = web_handle };
        httpd_register_uri_handler(server, &uri);
    }
    return server;
}

static bool web_server(const char *entry_script)
{
    if (s_init[0] != '\0') {
        printf("--- init: %s ---\n", s_init);
        phpbm_register_server_vars(s_init);
        if (!phpbm_run_file(s_init)) {
            return false;
        }
    }
    s_web = heap_caps_calloc(1, sizeof(*s_web), MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT);
    s_req_ready = xSemaphoreCreateBinary();
    s_resp_ready = xSemaphoreCreateBinary();
    if (s_web == NULL || s_req_ready == NULL || s_resp_ready == NULL) {
        printf("phpbm: no memory for the web-server\n");
        return true;
    }
    snprintf(s_docroot, sizeof(s_docroot), "%s", entry_script);
    char *slash = strrchr(s_docroot, '/');
    if (slash != NULL && slash != s_docroot) {
        *slash = '\0';
    }
    snprintf(s_port, sizeof(s_port), "%d", PHPBM_OPTION_PORT);
    s_post_max = SG(post_max_size) > 0 ? (size_t) SG(post_max_size) : 0;
    s_wait_us = PHPBM_OPTION_REQUEST_TIMEOUT > 0 ? (int64_t) (PHPBM_OPTION_REQUEST_TIMEOUT + WEB_WAIT_EXTRA_S) * 1000000 : 0;

    fflush(stdout);
    php_request_shutdown(NULL);
    sapi_module_struct saved = sapi_module;
    sapi_module.ub_write = web_ub_write;
    sapi_module.send_headers = web_send_headers;
    sapi_module.read_post = web_read_post;
    sapi_module.read_cookies = web_read_cookies;
    sapi_module.register_server_variables = web_register_server_vars;

    s_serving = true;
    httpd_handle_t server = web_start();
    if (server != NULL) {
        const char *ip = phpbm_network_ip();
        if (ip[0] == '\0') {
            printf("phpbm: web-server on port %d without a network address\n", PHPBM_OPTION_PORT);
            ip = "0.0.0.0";
        }
        printf("--- %s (web-server) http://%s:%d/ ---\n", entry_script, ip, PHPBM_OPTION_PORT);
        volatile bool running = true;
        while (running) {
            xSemaphoreTake(s_req_ready, portMAX_DELAY);
            uint32_t seq = __atomic_load_n(&s_park_seq, __ATOMIC_ACQUIRE);
            volatile bool escaped = false;
            zend_try {
                running = web_serve_one();
            } zend_catch {
                escaped = true;
            } zend_end_try();
            if (escaped) {
                /* A bailout outside the request's own zend_try: close the request if it is still
                 * open, answer 500 and keep serving. */
                printf("phpbm: %s %s: engine bailout outside the script, request aborted\n", s_web->method_str,
                       s_web->uri);
                if (s_request_open) {
                    zend_try {
                        php_request_shutdown(NULL);
                    } zend_end_try();
                    s_request_open = false;
                }
                web_request_clear();
                s_web->status = 500;
                s_web->hdr_count = 0;
                strcpy(s_web->resp_ctype, "text/plain; charset=UTF-8");
                web_set_body("Internal Server Error");
            } else if (!running) {
                s_serving = false;
                s_web->status = 503;
                s_web->hdr_count = 0;
                strcpy(s_web->resp_ctype, "text/plain; charset=UTF-8");
                web_set_body("Service Unavailable");
            }
            const bool stopping = running && phpbm_after_stop() != NULL;
            if (stopping) {
                /* sys_restart() and the like stop the program: this request is answered, later ones get 503. */
                s_serving = false;
            }
            __atomic_store_n(&s_done_seq, seq, __ATOMIC_RELEASE);
            xSemaphoreGive(s_resp_ready);
            if (stopping) {
                running = false;
            }
        }
        if (phpbm_after_stop() == NULL) {
            printf("phpbm: web-server stopped: PHP cannot start a request\n");
        }
        httpd_stop(server);
    }
    s_serving = false;

    sapi_module.ub_write = saved.ub_write;
    sapi_module.send_headers = saved.send_headers;
    sapi_module.read_post = saved.read_post;
    sapi_module.read_cookies = saved.read_cookies;
    sapi_module.register_server_variables = saved.register_server_variables;
    php_request_startup();
    return true;
}

void phpbm_runtime_run(const char *entry_script)
{
    s_entry = entry_script;
    phpbm_startup_time_limit(PHPBM_OPTION_REQUEST_TIMEOUT);   /* the init script and every request */
    if (PHPBM_OPTION_INIT[0] != '\0') {
        snprintf(s_init, sizeof(s_init), "%s/%s", phpbm_source_dir(), PHPBM_OPTION_INIT);
        if (access(s_init, R_OK) != 0) {
            printf("--- stopped: init script %s not found ---\n", s_init);
            return;
        }
    }
    phpbm_run_protected(web_server, entry_script);
}
