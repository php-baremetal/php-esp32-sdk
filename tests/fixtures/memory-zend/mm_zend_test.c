/*
 * memory.c's chunk allocator under the real Zend memory manager (the SDK's patched zend_alloc.c, built for the PC):
 * the fake PSRAM is filled with random bytes as after a power-on, then 200 000 emalloc()/efree() of mixed sizes
 * (small, large, huge) run with every live block checked against the others. A chunk whose header is not zeroed
 * leaves stale bits in the page bitmap past its first word, and the MM then hands out pages that are in use: two
 * live blocks overlap. Built with ZEND_MM_CHUNK_SIZE 512 KB on a 64-bit PC (128 pages: two bitmap words, as 256 KB
 * on the 32-bit board). Prints "OK" when no block overlaps another.
 */
#include <stdint.h>
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "zend.h"
#include "zend_alloc.h"
#include "esp_heap_caps.h"

void phpbm_memory_init(void);
size_t phpbm_memory_limit(void);

#define FAKE_PSRAM (24u * 1024 * 1024)
static char *s_psram;
static size_t s_psram_used;
static uint64_t s_rng;

static uint32_t rnd(void)
{
    s_rng ^= s_rng << 13;
    s_rng ^= s_rng >> 7;
    s_rng ^= s_rng << 17;
    return (uint32_t) s_rng;
}

size_t heap_caps_get_free_size(uint32_t caps)
{
    (void) caps;
    return FAKE_PSRAM - s_psram_used;
}

size_t heap_caps_get_total_size(uint32_t caps)
{
    (void) caps;
    return 32u * 1024 * 1024;
}

void *heap_caps_aligned_alloc(size_t alignment, size_t size, uint32_t caps)
{
    (void) caps;
    uintptr_t base = ((uintptr_t) s_psram + s_psram_used + alignment - 1) & ~(uintptr_t) (alignment - 1);
    if (base + size > (uintptr_t) s_psram + FAKE_PSRAM) {
        return NULL;
    }
    s_psram_used = base + size - (uintptr_t) s_psram;
    return (void *) base;
}

typedef struct { char *p; size_t n; } live_t;
#define LIVE 4096
static live_t s_live[LIVE];

static int overlaps(const char *p, size_t n)
{
    for (int i = 0; i < LIVE; i++) {
        if (s_live[i].p && p < s_live[i].p + s_live[i].n && s_live[i].p < p + n) {
            return 1;
        }
    }
    return 0;
}

int main(int argc, char **argv)
{
    unsigned seed = argc > 1 ? (unsigned) atoi(argv[1]) : 1;
    s_rng = 0x9E3779B97F4A7C15ull ^ seed;
    s_psram = aligned_alloc(4096, FAKE_PSRAM);
    for (size_t i = 0; i < FAKE_PSRAM / 4; i++) {
        ((uint32_t *) s_psram)[i] = rnd();   /* the PSRAM after power-on */
    }
    phpbm_memory_init();
    start_memory_manager();
    for (int step = 0; step < 200000; step++) {
        int slot = (int) (rnd() % LIVE);
        if (s_live[slot].p) {
            efree(s_live[slot].p);
            s_live[slot].p = NULL;
            continue;
        }
        uint32_t r = rnd() % 100;
        size_t n = r < 85 ? 8 + rnd() % 3000 : r < 99 ? 3072 + rnd() % (60 * 1024) : 64 * 1024 + rnd() % (200 * 1024);
        char *p = emalloc(n);
        if (overlaps(p, n)) {
            printf("seed %u step %d: emalloc(%zu) = %p overlaps a live block\n", seed, step, n, (void *) p);
            return 1;
        }
        memset(p, 0x5A, n);
        s_live[slot].p = p;
        s_live[slot].n = n;
    }
    printf("OK\n");
    return 0;
}
