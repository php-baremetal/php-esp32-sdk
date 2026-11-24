/*
 * memory.c's chunk allocator (zend_mm_platform_handlers) on the PC, over a fake PSRAM filled with random bytes as
 * after a power-on. The Zend MM takes every chunk it gets to be zero-filled like mmap()'s (it initialises only the
 * first word of the page bitmap and the first page's entry): the chunk header, its first page, must read as zeros
 * however the PSRAM was left, for a fresh chunk, a chunk freed and taken again, and a huge block.
 * Prints "OK" when every check passes.
 */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "esp_heap_caps.h"
#include "zend_alloc.h"

void phpbm_memory_init(void);
size_t phpbm_memory_limit(void);

/* Defined by the engine (the zend-mm-platform-storage patch of php/<minor>/patches); memory.c sets it. */
const zend_mm_handlers *zend_mm_platform_handlers;
size_t zend_mm_platform_hard_limit;

#define FAKE_PSRAM (8u * 1024 * 1024)
static unsigned char *s_psram;
static size_t s_used;
static uint32_t s_rng = 0x2545F491u;

static uint32_t rnd(void)
{
    s_rng ^= s_rng << 13;
    s_rng ^= s_rng >> 17;
    s_rng ^= s_rng << 5;
    return s_rng;
}

size_t heap_caps_get_free_size(uint32_t caps)
{
    (void) caps;
    return FAKE_PSRAM - s_used;
}

size_t heap_caps_get_total_size(uint32_t caps)
{
    (void) caps;
    return FAKE_PSRAM;
}

void *heap_caps_aligned_alloc(size_t alignment, size_t size, uint32_t caps)
{
    (void) caps;
    uintptr_t base = ((uintptr_t) s_psram + s_used + alignment - 1) & ~(uintptr_t) (alignment - 1);
    if (base + size > (uintptr_t) s_psram + FAKE_PSRAM) {
        return NULL;
    }
    s_used = base + size - (uintptr_t) s_psram;
    return (void *) base;
}

static void scribble(void *p, size_t n)
{
    for (size_t i = 0; i < n; i++) {
        ((unsigned char *) p)[i] = (unsigned char) rnd();
    }
}

static int fails;

static void expect_zero_header(const char *what, const unsigned char *chunk)
{
    if (chunk == NULL) {
        printf("FAIL %s: no chunk\n", what);
        fails++;
        return;
    }
    for (size_t i = 0; i < (size_t) ZEND_MM_FIRST_PAGE * ZEND_MM_PAGE_SIZE; i++) {
        if (chunk[i] != 0) {
            printf("FAIL %s: header byte %zu is 0x%02x, not 0\n", what, i, chunk[i]);
            fails++;
            return;
        }
    }
}

int main(void)
{
    /* malloc and align by hand: MinGW has no aligned_alloc */
    unsigned char *raw = malloc(FAKE_PSRAM + ZEND_MM_CHUNK_SIZE);
    s_psram = (unsigned char *) (((uintptr_t) raw + ZEND_MM_CHUNK_SIZE - 1) & ~(uintptr_t) (ZEND_MM_CHUNK_SIZE - 1));
    scribble(s_psram, FAKE_PSRAM);
    phpbm_memory_init();
    const zend_mm_handlers *h = zend_mm_platform_handlers;
    if (h == NULL || phpbm_memory_limit() < 4 * ZEND_MM_CHUNK_SIZE) {
        printf("FAIL no pool (limit %zu)\n", phpbm_memory_limit());
        return 1;
    }
    /* memory_limit leaves 2 chunks of the pool (8 MB of PSRAM is not a small board) as headroom; the engine's hard
     * limit is the whole pool. */
    if (zend_mm_platform_hard_limit != phpbm_memory_limit() + 2 * ZEND_MM_CHUNK_SIZE) {
        printf("FAIL hard limit %zu, memory_limit %zu: not 2 chunks of headroom\n", zend_mm_platform_hard_limit, phpbm_memory_limit());
        fails++;
    }

    unsigned char *a = h->chunk_alloc(NULL, ZEND_MM_CHUNK_SIZE, ZEND_MM_CHUNK_SIZE);
    unsigned char *b = h->chunk_alloc(NULL, ZEND_MM_CHUNK_SIZE, ZEND_MM_CHUNK_SIZE);
    expect_zero_header("first chunk", a);
    expect_zero_header("second chunk", b);

    /* A chunk the engine used and gave back comes back with its header zeroed again. */
    scribble(a, ZEND_MM_CHUNK_SIZE);
    h->chunk_free(NULL, a, ZEND_MM_CHUNK_SIZE);
    unsigned char *c = h->chunk_alloc(NULL, ZEND_MM_CHUNK_SIZE, ZEND_MM_CHUNK_SIZE);
    if (c != a) {
        printf("FAIL the freed chunk was not reused\n");
        fails++;
    }
    expect_zero_header("reused chunk", c);

    /* A huge block (more than a chunk) starts on a chunk too. */
    unsigned char *huge = h->chunk_alloc(NULL, ZEND_MM_CHUNK_SIZE + 3 * ZEND_MM_PAGE_SIZE, ZEND_MM_CHUNK_SIZE);
    expect_zero_header("huge block", huge);

    if (fails == 0) {
        printf("OK\n");
    }
    return fails == 0 ? 0 : 1;
}
