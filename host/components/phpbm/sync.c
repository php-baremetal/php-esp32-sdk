/*
 * Serial sync of the project sources (phpbm sync), served from app_main on the console.
 *
 * host: PHPBM-SYNC 1            fw: PHPBM-SYNC READY <free bytes>
 * host: LIST                    fw: F <sha256> <size> <path> ... END
 * host: PUT <size> <sha256> <path>   fw: OK | ERR <why>
 *       then <len>\n + len bytes (len <= 4096), each answered OK | ERR <why>
 *                               fw: DONE <path> | ERR <why>
 * host: DEL <path>              fw: OK | ERR <why>
 * host: BAUD <n>                fw: OK, then switches to n and waits SYNC_BAUD_MS for PING (answering PONG),
 *                               else goes back; ERR not needed (USB-JTAG console)
 * host: BYE                     fw: BYE (the caller restarts)
 * Paths are relative to dir, '/'-separated, without "", "." or ".." segments.
 * PUT writes <path>.part, then renames the old file to <path>.old, .part to <path> and deletes .old;
 * the session start removes orphan .part files and puts back an .old whose file is missing.
 */
#include <dirent.h>
#include <errno.h>
#include <inttypes.h>
#include <stdarg.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>
#include <strings.h>
#include <sys/stat.h>
#include <unistd.h>

#include "freertos/FreeRTOS.h"
#include "freertos/task.h"
#include "esp_vfs_fat.h"
#include "psa/crypto.h"
#include "sdkconfig.h"
#include "phpbm.h"

#if CONFIG_ESP_CONSOLE_USB_SERIAL_JTAG
#include "driver/usb_serial_jtag.h"
#include "driver/usb_serial_jtag_vfs.h"
#elif CONFIG_ESP_CONSOLE_UART
#include "driver/uart.h"
#include "driver/uart_vfs.h"
#endif

#define SYNC_HELLO "PHPBM-SYNC 1"
#define SYNC_WINDOW_MS 1500
#define SYNC_IDLE_MS 10000
#define SYNC_CHUNK 4096
#define SYNC_PATH_MAX 256
#define SYNC_DEPTH 16
#define SYNC_BAUD_MS 1000
#define SYNC_RX_FULL 64 /* of the 128-byte UART RX FIFO: room for 0.7 ms of interrupt latency at 921600 */

static char s_rx[SYNC_CHUNK];
static size_t s_rx_pos, s_rx_len;
static uint8_t s_buf[SYNC_CHUNK];
static char s_full[SYNC_PATH_MAX + 64];
static char s_tmp[SYNC_PATH_MAX + 64];
static const char *s_dir;

/* --- console ------------------------------------------------------------------------------ */

static bool con_open(void)
{
#if CONFIG_ESP_CONSOLE_USB_SERIAL_JTAG
    usb_serial_jtag_driver_config_t cfg = {.tx_buffer_size = 1024, .rx_buffer_size = SYNC_CHUNK + 512};
    if (usb_serial_jtag_driver_install(&cfg) != ESP_OK) {
        return false;
    }
    usb_serial_jtag_vfs_use_driver();
    return true;
#elif CONFIG_ESP_CONSOLE_UART
    if (uart_driver_install(CONFIG_ESP_CONSOLE_UART_NUM, 2 * SYNC_CHUNK, 0, 0, NULL, ESP_INTR_FLAG_IRAM) != ESP_OK) {
        return false;
    }
    uart_set_rx_full_threshold(CONFIG_ESP_CONSOLE_UART_NUM, SYNC_RX_FULL);
    /* Hellos sent before the install may have overflowed the RX FIFO, which then stops receiving. */
    uart_flush_input(CONFIG_ESP_CONSOLE_UART_NUM);
    uart_vfs_dev_use_driver(CONFIG_ESP_CONSOLE_UART_NUM);
    return true;
#else
    return false;
#endif
}

static void con_close(void)
{
    fflush(stdout);
#if CONFIG_ESP_CONSOLE_USB_SERIAL_JTAG
    usb_serial_jtag_wait_tx_done(pdMS_TO_TICKS(500));
    usb_serial_jtag_vfs_use_nonblocking();
    usb_serial_jtag_driver_uninstall();
#elif CONFIG_ESP_CONSOLE_UART
    uart_wait_tx_done(CONFIG_ESP_CONSOLE_UART_NUM, pdMS_TO_TICKS(500));
    uart_set_baudrate(CONFIG_ESP_CONSOLE_UART_NUM, CONFIG_ESP_CONSOLE_UART_BAUDRATE);
    uart_vfs_dev_use_nonblocking(CONFIG_ESP_CONSOLE_UART_NUM);
    uart_driver_delete(CONFIG_ESP_CONSOLE_UART_NUM);
#endif
}

static int con_read(void *buf, size_t len, TickType_t ticks)
{
#if CONFIG_ESP_CONSOLE_USB_SERIAL_JTAG
    return usb_serial_jtag_read_bytes(buf, len, ticks);
#elif CONFIG_ESP_CONSOLE_UART
    /* uart_read_bytes waits for all len bytes: wait for one, then take what is buffered. */
    int n = uart_read_bytes(CONFIG_ESP_CONSOLE_UART_NUM, buf, 1, ticks);
    size_t more = 0;
    if (n == 1 && uart_get_buffered_data_len(CONFIG_ESP_CONSOLE_UART_NUM, &more) == ESP_OK && more > 0) {
        int m = uart_read_bytes(CONFIG_ESP_CONSOLE_UART_NUM, (uint8_t *) buf + 1, more < len - 1 ? more : len - 1, 0);
        n += m > 0 ? m : 0;
    }
    return n;
#else
    return 0;
#endif
}

static void con_write(const char *data, size_t len)
{
    fflush(stdout);
#if CONFIG_ESP_CONSOLE_USB_SERIAL_JTAG
    usb_serial_jtag_write_bytes(data, len, pdMS_TO_TICKS(1000));
#elif CONFIG_ESP_CONSOLE_UART
    uart_write_bytes(CONFIG_ESP_CONSOLE_UART_NUM, data, len);
#endif
}

static void reply(const char *fmt, ...) __attribute__((format(printf, 1, 2)));
static void reply(const char *fmt, ...)
{
    char line[SYNC_PATH_MAX + 128];
    va_list ap;
    va_start(ap, fmt);
    int n = vsnprintf(line, sizeof(line) - 1, fmt, ap);
    va_end(ap);
    if (n < 0) {
        return;
    }
    if ((size_t) n > sizeof(line) - 2) {
        n = (int) sizeof(line) - 2;
    }
    line[n++] = '\n';
    con_write(line, (size_t) n);
}

/* Fills the receive buffer; false once the deadline passes without data. */
static bool rx_fill(TickType_t deadline)
{
    while (s_rx_pos >= s_rx_len) {
        TickType_t now = xTaskGetTickCount();
        if ((int32_t) (deadline - now) <= 0) {
            return false;
        }
        int n = con_read(s_rx, sizeof(s_rx), deadline - now);
        if (n > 0) {
            s_rx_pos = 0;
            s_rx_len = (size_t) n;
        }
    }
    return true;
}

/* Reads one line without the line ending; false on timeout. An over-long line comes back as "". */
static bool read_line(char *line, size_t max, uint32_t timeout_ms)
{
    TickType_t deadline = xTaskGetTickCount() + pdMS_TO_TICKS(timeout_ms);
    size_t len = 0;
    bool overflow = false;
    for (;;) {
        if (!rx_fill(deadline)) {
            return false;
        }
        char c = s_rx[s_rx_pos++];
        if (c == '\n') {
            break;
        }
        if (c == '\r') {
            continue;
        }
        if (len + 1 < max) {
            line[len++] = c;
        } else {
            overflow = true;
        }
    }
    line[overflow ? 0 : len] = '\0';
    return true;
}

static bool read_bytes(uint8_t *buf, size_t len, uint32_t timeout_ms)
{
    TickType_t deadline = xTaskGetTickCount() + pdMS_TO_TICKS(timeout_ms);
    while (len > 0) {
        if (!rx_fill(deadline)) {
            return false;
        }
        size_t n = s_rx_len - s_rx_pos < len ? s_rx_len - s_rx_pos : len;
        memcpy(buf, s_rx + s_rx_pos, n);
        s_rx_pos += n;
        buf += n;
        len -= n;
    }
    return true;
}

/* --- files -------------------------------------------------------------------------------- */

static bool valid_path(const char *path)
{
    size_t len = strlen(path);
    if (len == 0 || len >= SYNC_PATH_MAX || path[0] == '/' || path[len - 1] == '/') {
        return false;
    }
    const char *seg = path;
    for (const char *p = path;; p++) {
        if (*p == '/' || *p == '\0') {
            size_t n = (size_t) (p - seg);
            if (n == 0 || (n == 1 && seg[0] == '.') || (n == 2 && seg[0] == '.' && seg[1] == '.') || seg[n - 1] == '.' || seg[n - 1] == ' ') {
                return false;
            }
            if (*p == '\0') {
                return true;
            }
            seg = p + 1;
        } else if ((unsigned char) *p < 0x20 || (unsigned char) *p > 0x7e || strchr("\\:\"*<>?|", *p) != NULL) {
            return false;
        }
    }
}

static void full_path(char *out, const char *rel, const char *suffix)
{
    snprintf(out, sizeof(s_full), "%s/%s%s", s_dir, rel, suffix);
}

static void hex(const uint8_t *digest, char *out)
{
    for (int i = 0; i < 32; i++) {
        sprintf(out + 2 * i, "%02x", digest[i]);
    }
}

static bool hash_file(const char *path, char *out)
{
    FILE *f = fopen(path, "rb");
    if (f == NULL) {
        return false;
    }
    psa_hash_operation_t op = PSA_HASH_OPERATION_INIT;
    bool ok = psa_hash_setup(&op, PSA_ALG_SHA_256) == PSA_SUCCESS;
    size_t n;
    while (ok && (n = fread(s_buf, 1, sizeof(s_buf), f)) > 0) {
        ok = psa_hash_update(&op, s_buf, n) == PSA_SUCCESS;
    }
    ok = ok && !ferror(f);
    fclose(f);
    uint8_t digest[32];
    size_t dlen = 0;
    if (ok && psa_hash_finish(&op, digest, sizeof(digest), &dlen) == PSA_SUCCESS && dlen == 32) {
        hex(digest, out);
        return true;
    }
    psa_hash_abort(&op);
    return false;
}

/* Lists the files under s_dir/rel (rel is "" or ends with '/'). */
static void list_dir(char *rel, int depth)
{
    snprintf(s_full, sizeof(s_full), "%s/%s", s_dir, rel);
    DIR *dir = opendir(s_full);
    if (dir == NULL) {
        return;
    }
    size_t base = strlen(rel);
    struct dirent *e;
    while ((e = readdir(dir)) != NULL) {
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || base + strlen(e->d_name) + 2 >= SYNC_PATH_MAX) {
            continue;
        }
        strcpy(rel + base, e->d_name);
        full_path(s_full, rel, "");
        struct stat st;
        if (stat(s_full, &st) != 0) {
            continue;
        }
        if (S_ISDIR(st.st_mode)) {
            if (depth < SYNC_DEPTH) {
                strcat(rel, "/");
                list_dir(rel, depth + 1);
            }
        } else {
            char sha[65];
            if (hash_file(s_full, sha)) {
                reply("F %s %ld %s", sha, (long) st.st_size, rel);
            }
        }
        rel[base] = '\0';
    }
    closedir(dir);
}

/* Creates the directories of rel's parent under s_dir. */
static bool make_parents(const char *rel)
{
    for (const char *p = strchr(rel, '/'); p != NULL; p = strchr(p + 1, '/')) {
        snprintf(s_tmp, sizeof(s_tmp), "%s/%.*s", s_dir, (int) (p - rel), rel);
        struct stat st;
        if (stat(s_tmp, &st) == 0) {
            if (!S_ISDIR(st.st_mode)) {
                return false;
            }
        } else if (mkdir(s_tmp, 0777) != 0) {
            return false;
        }
    }
    return true;
}

/* Removes the parents of rel left empty, up to s_dir. */
static void remove_empty_parents(const char *rel)
{
    snprintf(s_tmp, sizeof(s_tmp), "%.*s", SYNC_PATH_MAX, rel); /* rel is a valid_path(): shorter */
    char *slash;
    while ((slash = strrchr(s_tmp, '/')) != NULL) {
        *slash = '\0';
        full_path(s_full, s_tmp, "");
        if (rmdir(s_full) != 0) {
            break;
        }
    }
}

/* --- commands ----------------------------------------------------------------------------- */

static bool cmd_del(const char *rel)
{
    if (!valid_path(rel)) {
        reply("ERR invalid path");
        return true;
    }
    full_path(s_full, rel, "");
    struct stat st;
    if (stat(s_full, &st) != 0 || S_ISDIR(st.st_mode)) {
        reply("ERR not a file");
        return true;
    }
    if (unlink(s_full) != 0) {
        reply("ERR cannot delete (%d)", errno);
        return true;
    }
    remove_empty_parents(rel);
    reply("OK");
    return true;
}

/* False when the session is lost (timeout or a broken chunk header). */
static bool cmd_put(char *args)
{
    char *end;
    long size = strtol(args, &end, 10);
    char *sha = end + 1;
    char *rel = sha + 65;
    if (end == args || *end != ' ' || size < 0 || strlen(sha) < 66 || sha[64] != ' ') {
        reply("ERR bad PUT");
        return true;
    }
    sha[64] = '\0';
    if (!valid_path(rel)) {
        reply("ERR invalid path");
        return true;
    }
    char part[SYNC_PATH_MAX + 64];
    full_path(part, rel, ".part");
    FILE *f = make_parents(rel) ? fopen(part, "wb") : NULL;
    if (f == NULL) {
        reply("ERR cannot create %s", rel);
        return true;
    }
    reply("OK");

    psa_hash_operation_t op = PSA_HASH_OPERATION_INIT;
    bool ok = psa_hash_setup(&op, PSA_ALG_SHA_256) == PSA_SUCCESS;
    long left = size;
    while (left > 0) {
        char head[16];
        if (!read_line(head, sizeof(head), SYNC_IDLE_MS)) {
            fclose(f);
            unlink(part);
            psa_hash_abort(&op);
            return false;
        }
        long len = strtol(head, &end, 10);
        if (end == head || *end != '\0' || len <= 0 || len > SYNC_CHUNK || len > left || !read_bytes(s_buf, (size_t) len, SYNC_IDLE_MS)) {
            fclose(f);
            unlink(part);
            psa_hash_abort(&op);
            return false;
        }
        left -= len;
        if (fwrite(s_buf, 1, (size_t) len, f) != (size_t) len || psa_hash_update(&op, s_buf, (size_t) len) != PSA_SUCCESS) {
            fclose(f);
            unlink(part);
            psa_hash_abort(&op);
            reply("ERR write failed (%d)", errno);
            return true;
        }
        reply("OK");
    }
    uint8_t digest[32];
    size_t dlen = 0;
    char got[65] = "";
    if (psa_hash_finish(&op, digest, sizeof(digest), &dlen) == PSA_SUCCESS && dlen == 32) {
        hex(digest, got);
    } else {
        psa_hash_abort(&op);
    }
    ok = fclose(f) == 0 && ok;
    if (!ok || strcmp(got, sha) != 0) {
        unlink(part);
        reply(ok ? "ERR sha256 mismatch" : "ERR write failed");
        return true;
    }
    /* The old file stays as <path>.old until the new one is in place (see recover_dir). */
    char old[SYNC_PATH_MAX + 64];
    full_path(old, rel, ".old");
    full_path(s_full, rel, "");
    struct stat st;
    bool had = stat(s_full, &st) == 0;
    if (had) {
        unlink(old);
        if (S_ISDIR(st.st_mode) || rename(s_full, old) != 0) {
            unlink(part);
            reply("ERR cannot replace %s (%d)", rel, errno);
            return true;
        }
    }
    if (rename(part, s_full) != 0) {
        int err = errno;
        if (had) {
            rename(old, s_full);
        }
        unlink(part);
        reply("ERR cannot rename (%d)", err);
        return true;
    }
    if (had) {
        unlink(old);
    }
    reply("DONE %s", rel);
    return true;
}

static bool has_suffix(const char *name, const char *suffix)
{
    size_t n = strlen(name), m = strlen(suffix);
    return n > m && strcasecmp(name + n - m, suffix) == 0;
}

/* Under s_dir/rel (rel is "" or ends with '/'): removes the .part files of interrupted transfers and
 * finishes the replacements a reset cut short: <path>.old goes if <path> exists, else it becomes <path>. */
static int recover_dir(char *rel, int depth)
{
    snprintf(s_full, sizeof(s_full), "%s/%s", s_dir, rel);
    DIR *dir = opendir(s_full);
    if (dir == NULL) {
        return 0;
    }
    int fixed = 0;
    size_t base = strlen(rel);
    struct dirent *e;
    while ((e = readdir(dir)) != NULL) {
        if (strcmp(e->d_name, ".") == 0 || strcmp(e->d_name, "..") == 0 || base + strlen(e->d_name) + 2 >= SYNC_PATH_MAX) {
            continue;
        }
        strcpy(rel + base, e->d_name);
        full_path(s_full, rel, "");
        struct stat st;
        if (stat(s_full, &st) == 0 && S_ISDIR(st.st_mode)) {
            if (depth < SYNC_DEPTH) {
                strcat(rel, "/");
                fixed += recover_dir(rel, depth + 1);
            }
        } else if (has_suffix(e->d_name, ".part")) {
            fixed += unlink(s_full) == 0;
        } else if (has_suffix(e->d_name, ".old")) {
            snprintf(s_tmp, sizeof(s_tmp), "%.*s", (int) (strlen(s_full) - 4), s_full);
            fixed += stat(s_tmp, &st) == 0 ? unlink(s_full) == 0 : rename(s_full, s_tmp) == 0;
        }
        rel[base] = '\0';
    }
    closedir(dir);
    return fixed;
}

static void cmd_baud(const char *arg)
{
#if CONFIG_ESP_CONSOLE_UART
    const uart_port_t uart = CONFIG_ESP_CONSOLE_UART_NUM;
    char *end;
    unsigned long baud = strtoul(arg, &end, 10);
    uint32_t old = 0;
    if (end == arg || *end != '\0' || baud < 9600 || baud > 2000000 || uart_get_baudrate(uart, &old) != ESP_OK) {
        reply("ERR bad baud rate");
        return;
    }
    reply("OK");
    uart_wait_tx_done(uart, pdMS_TO_TICKS(100));
    if (uart_set_baudrate(uart, (uint32_t) baud) == ESP_OK) {
        uart_flush_input(uart);
        s_rx_pos = s_rx_len = 0;
        TickType_t deadline = xTaskGetTickCount() + pdMS_TO_TICKS(SYNC_BAUD_MS);
        char line[16];
        for (;;) {
            TickType_t now = xTaskGetTickCount();
            if ((int32_t) (deadline - now) <= 0 || !read_line(line, sizeof(line), pdTICKS_TO_MS(deadline - now))) {
                break;
            }
            if (strcmp(line, "PING") == 0) {
                reply("PONG");
                return;
            }
        }
    }
    /* No PING at the new rate: back to the old one, where the host retries. */
    uart_set_baudrate(uart, old);
    uart_flush_input(uart);
    s_rx_pos = s_rx_len = 0;
#else
    (void) arg;
    reply("ERR not needed");
#endif
}

/* NULL after BYE, otherwise why the session ended. */
static const char *session(const char *mount)
{
    char rel[SYNC_PATH_MAX + 2] = "";
    int fixed = recover_dir(rel, 0);
    if (fixed > 0) {
        printf("phpbm: sync cleaned up %d file(s) of an interrupted session\n", fixed);
    }
    uint64_t total = 0, free_bytes = 0;
    esp_vfs_fat_info(mount, &total, &free_bytes);
    reply("PHPBM-SYNC READY %" PRIu64, free_bytes);
    static char line[SYNC_PATH_MAX + 96];
    while (read_line(line, sizeof(line), SYNC_IDLE_MS)) {
        /* Queued hellos are not commands; PING is answered again so a lost PONG does not desync the speeds. */
        if (strcmp(line, "PING") == 0) {
            reply("PONG");
            continue;
        }
        if (line[0] == '\0' || strncmp(line, "PHPBM-SYNC ", 11) == 0) {
            continue;
        }
        if (strcmp(line, "LIST") == 0) {
            rel[0] = '\0';
            list_dir(rel, 0);
            reply("END");
        } else if (strncmp(line, "PUT ", 4) == 0) {
            if (!cmd_put(line + 4)) {
                return "phpbm: sync aborted (the host stopped sending)";
            }
        } else if (strncmp(line, "DEL ", 4) == 0) {
            cmd_del(line + 4);
        } else if (strncmp(line, "BAUD ", 5) == 0) {
            cmd_baud(line + 5);
        } else if (strcmp(line, "BYE") == 0) {
            reply("BYE");
            return NULL;
        } else {
            reply("ERR unknown command");
        }
    }
    return "phpbm: sync timed out";
}

bool phpbm_sync(const char *dir, const char *mount)
{
    s_dir = dir;
    s_rx_pos = s_rx_len = 0;
    mkdir(dir, 0777);
    if (!con_open()) {
        return false;
    }
    char line[32];
    TickType_t deadline = xTaskGetTickCount() + pdMS_TO_TICKS(SYNC_WINDOW_MS);
    bool hello = false;
    while (!hello) {
        TickType_t now = xTaskGetTickCount();
        if ((int32_t) (deadline - now) <= 0 || !read_line(line, sizeof(line), pdTICKS_TO_MS(deadline - now))) {
            break;
        }
        hello = strcmp(line, SYNC_HELLO) == 0;
    }
    const char *ended = hello ? session(mount) : NULL;
    con_close(); /* back at the console's baud rate */
    if (ended != NULL) {
        printf("%s\n", ended);
    }
    return hello;
}
