/* OPcache "shared memory" as one PSRAM block (USE_MALLOC_SHM, registered by patch 0007): a single
 * process keeps the engine across requests, so nothing is shared, the cache only has to persist. */
#include <stdlib.h>
#include <string.h>

#include "esp_heap_caps.h"
#include "zend_shared_alloc.h"

#define PHPBM_SHM_ALIGN 64
#define PHPBM_SHM_CAPS (MALLOC_CAP_SPIRAM | MALLOC_CAP_8BIT)

static int create_segments(size_t requested_size, zend_shared_segment ***shared_segments_p, int *shared_segments_count, const char **error_in)
{
    size_t size = (requested_size + PHPBM_SHM_ALIGN - 1) & ~((size_t) PHPBM_SHM_ALIGN - 1);
    void *p = heap_caps_aligned_alloc(PHPBM_SHM_ALIGN, size, PHPBM_SHM_CAPS);
    if (p == NULL) {
        *error_in = "heap_caps_aligned_alloc";
        return ALLOC_FAILURE;
    }
    memset(p, 0, size);
    /* One block, the pointer table then the segment: opcache free()s it. */
    zend_shared_segment **table = calloc(1, sizeof(zend_shared_segment *) + sizeof(zend_shared_segment));
    if (table == NULL) {
        heap_caps_free(p);
        *error_in = "calloc";
        return ALLOC_FAILURE;
    }
    zend_shared_segment *segment = (zend_shared_segment *) (table + 1);
    segment->p = p;
    segment->pos = 0;
    segment->size = requested_size;
    table[0] = segment;
    *shared_segments_p = table;
    *shared_segments_count = 1;
    return ALLOC_SUCCESS;
}

static int detach_segment(zend_shared_segment *shared_segment)
{
    heap_caps_free(shared_segment->p);
    return 0;
}

static size_t segment_type_size(void)
{
    return sizeof(zend_shared_segment);
}

const zend_shared_memory_handlers zend_alloc_malloc_handlers = {
    create_segments,
    detach_segment,
    segment_type_size,
};
