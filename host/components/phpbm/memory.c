#include <stdbool.h>
#include <stddef.h>
#include <stdint.h>

#include "esp_heap_caps.h"
#include "esp_log.h"
#include "sdkconfig.h"
#include "zend.h"
#include "zend_alloc.h"
#include "phpbm.h"
#include "phpbm_options.h"

#if !CONFIG_SPIRAM
#error "phpbm needs PSRAM"
#endif
#define PHPBM_HEAP_CAPS (MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT)
/* PSRAM left to the rest of the firmware once PHP has started (at most a quarter of the free PSRAM). */
#define PHPBM_FIRMWARE_RESERVE ((size_t) PHPBM_OPTION_FIRMWARE_RESERVE * 1024)
/* PSRAM PHP malloc()s in php_embed_init (persistent data): the engine's own, per PHP minor (sdk.json
 * php.<minor>.startup-kb: the drop in free PSRAM across php_embed_init with blink on the S3-ETH, plus 14 KB,
 * rounded up to 8 KB; 8.3 760532 B, 8.4 820508 B, 8.5 871492 B), plus the optional extensions' (their
 * descriptors' startup-kb, measured at script start against a build without them, rounded up). */
#define PHPBM_PHP_STARTUP ((size_t) (PHPBM_PHP_STARTUP_KB + PHPBM_PHP_EXT_STARTUP_KB) * 1024)
/* The startup estimate with a margin for the classes and functions packages add. */
#define PHPBM_PHP_STARTUP_ALLOWANCE (PHPBM_PHP_STARTUP + 208 * 1024)
/* Below this much PSRAM (2 MB boards) PHP gets a smaller stack, the measured startup and no firmware reserve:
 * the firmware lives on internal RAM and on what the chunk rounding leaves. */
#define PHPBM_SMALL_PSRAM (4 * 1024 * 1024)
#if PHPBM_PHP_EXT_OPCACHE
/* OPcache's bytecode cache (one PSRAM block outside the Zend MM, allocated in php_embed_init); what OPcache
 * malloc()s besides it is in its startup-kb. Not on small-PSRAM boards. */
#define PHPBM_OPCACHE_MEMORY ((size_t) PHPBM_OPTION_OPCACHE_MEMORY * 1024)
#endif
#define PHPBM_POOL_MAX_CHUNKS 256
#define PHPBM_POOL_MIN_CHUNKS 2
/* The pool a 2 MB board is calibrated for (1 MB); fewer chunks are logged. */
#define PHPBM_POOL_SMALL_CHUNKS 4

/* Chunks must be aligned to their size. heap_caps_aligned_alloc() puts a block header
 * before each one, so separate chunks would waste a chunk-sized gap each: PHP gets
 * one aligned pool instead and its chunks are carved out of it.
 * No locks: the build is NTS and only the PHP task uses the Zend MM (native code must
 * not call emalloc from other tasks). */
static char *s_pool;
static size_t s_pool_chunks;
static size_t s_reserve;
static uint32_t s_used[PHPBM_POOL_MAX_CHUNKS / 32];
static size_t s_opcache;

static bool phpbm_slot_used(size_t i)
{
    return s_used[i / 32] & (1u << (i % 32));
}

static void phpbm_slot_set(size_t i, bool used)
{
    if (used) {
        s_used[i / 32] |= 1u << (i % 32);
    } else {
        s_used[i / 32] &= ~(1u << (i % 32));
    }
}

static void *phpbm_chunk_alloc(zend_mm_storage *storage, size_t size, size_t alignment)
{
    (void) storage;
    if (s_pool == NULL || alignment > ZEND_MM_CHUNK_SIZE) {
        return NULL;
    }
    size_t count = (size + ZEND_MM_CHUNK_SIZE - 1) / ZEND_MM_CHUNK_SIZE;
    for (size_t first = 0; first + count <= s_pool_chunks; first++) {
        size_t n = 0;
        while (n < count && !phpbm_slot_used(first + n)) {
            n++;
        }
        if (n == count) {
            for (size_t i = 0; i < count; i++) {
                phpbm_slot_set(first + i, true);
            }
            return s_pool + first * ZEND_MM_CHUNK_SIZE;
        }
        first += n;
    }
    return NULL;
}

static void phpbm_chunk_free(zend_mm_storage *storage, void *chunk, size_t size)
{
    (void) storage;
    char *p = chunk;
    if (s_pool == NULL || p < s_pool || p >= s_pool + s_pool_chunks * ZEND_MM_CHUNK_SIZE) {
        return;
    }
    size_t first = (size_t) (p - s_pool) / ZEND_MM_CHUNK_SIZE;
    size_t count = (size + ZEND_MM_CHUNK_SIZE - 1) / ZEND_MM_CHUNK_SIZE;
    for (size_t i = 0; i < count && first + i < s_pool_chunks; i++) {
        phpbm_slot_set(first + i, false);
    }
}

static const zend_mm_handlers phpbm_mm_handlers = {
    .chunk_alloc = phpbm_chunk_alloc,
    .chunk_free = phpbm_chunk_free,
};

static bool phpbm_small_psram(void)
{
    return heap_caps_get_total_size(PHPBM_HEAP_CAPS) < PHPBM_SMALL_PSRAM;
}

size_t phpbm_php_stack_size(void)
{
    return phpbm_small_psram() ? PHPBM_PHP_STACK_SMALL : PHPBM_PHP_STACK;
}

void phpbm_memory_init(void)
{
    size_t available = heap_caps_get_free_size(PHPBM_HEAP_CAPS);
    bool small = phpbm_small_psram();
    size_t startup;
    if (small) {
        startup = PHPBM_PHP_STARTUP;
        s_reserve = 0;
        ESP_LOGI("phpbm", "firmware-reserve (%d KB) not applied below %d MB of PSRAM",
                 PHPBM_OPTION_FIRMWARE_RESERVE, PHPBM_SMALL_PSRAM / (1024 * 1024));
    } else {
        startup = PHPBM_PHP_STARTUP_ALLOWANCE;
        s_reserve = PHPBM_FIRMWARE_RESERVE < available / 4 ? PHPBM_FIRMWARE_RESERVE : available / 4;
    }
#if PHPBM_PHP_EXT_OPCACHE
    if (small) {
        ESP_LOGW("phpbm", "opcache disabled: needs >= 4 MB PSRAM");
    } else {
        s_opcache = PHPBM_OPCACHE_MEMORY;
    }
#endif
    size_t keep = s_reserve + startup + s_opcache;
    size_t chunks = available > keep ? (available - keep) / ZEND_MM_CHUNK_SIZE : 0;
    if (chunks > PHPBM_POOL_MAX_CHUNKS) {
        chunks = PHPBM_POOL_MAX_CHUNKS;
    }
    if (chunks < PHPBM_POOL_MIN_CHUNKS) {
        ESP_LOGE("phpbm", "PSRAM too small for PHP: %zu KB total, %zu KB free, firmware-reserve %zu KB, PHP startup %zu KB, opcache %zu KB leave %zu KB (min %zu KB)",
                 heap_caps_get_total_size(PHPBM_HEAP_CAPS) / 1024, available / 1024, s_reserve / 1024, startup / 1024, s_opcache / 1024,
                 chunks * (ZEND_MM_CHUNK_SIZE / 1024),
                 (size_t) PHPBM_POOL_MIN_CHUNKS * (ZEND_MM_CHUNK_SIZE / 1024));
    }
    for (; chunks >= PHPBM_POOL_MIN_CHUNKS; chunks--) {
        s_pool = heap_caps_aligned_alloc(ZEND_MM_CHUNK_SIZE, chunks * ZEND_MM_CHUNK_SIZE, PHPBM_HEAP_CAPS);
        if (s_pool != NULL) {
            break;
        }
    }
    s_pool_chunks = s_pool != NULL ? chunks : 0;
    if (small && s_pool_chunks > 0 && s_pool_chunks < PHPBM_POOL_SMALL_CHUNKS) {
        ESP_LOGW("phpbm", "PHP memory pool %zu KB, below the %zu KB expected with %zu KB of PSRAM",
                 s_pool_chunks * (ZEND_MM_CHUNK_SIZE / 1024), (size_t) PHPBM_POOL_SMALL_CHUNKS * (ZEND_MM_CHUNK_SIZE / 1024),
                 heap_caps_get_total_size(PHPBM_HEAP_CAPS) / 1024);
    }
    zend_mm_platform_handlers = &phpbm_mm_handlers;
}

size_t phpbm_memory_opcache(void)
{
    return s_opcache;
}

size_t phpbm_memory_reserve(void)
{
    return s_reserve;
}

/* 0 when no pool could be reserved. */
size_t phpbm_memory_limit(void)
{
    return s_pool_chunks * ZEND_MM_CHUNK_SIZE;
}
