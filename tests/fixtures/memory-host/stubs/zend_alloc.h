/* Host stub of the parts of Zend/zend_alloc.h (and zend_alloc_sizes.h) that memory.c uses, with the board's chunk
 * size (php_config.h); the handler types match PHP's. */
#pragma once
#include <stdbool.h>
#include <stddef.h>

#define ZEND_MM_CHUNK_SIZE ((size_t) (256 * 1024))
#define ZEND_MM_PAGE_SIZE (4 * 1024)
#define ZEND_MM_FIRST_PAGE (1)

typedef struct _zend_mm_storage zend_mm_storage;
typedef void *(*zend_mm_chunk_alloc_t)(zend_mm_storage *storage, size_t size, size_t alignment);
typedef void (*zend_mm_chunk_free_t)(zend_mm_storage *storage, void *chunk, size_t size);
typedef bool (*zend_mm_chunk_truncate_t)(zend_mm_storage *storage, void *chunk, size_t old_size, size_t new_size);
typedef bool (*zend_mm_chunk_extend_t)(zend_mm_storage *storage, void *chunk, size_t old_size, size_t new_size);

typedef struct _zend_mm_handlers {
    zend_mm_chunk_alloc_t chunk_alloc;
    zend_mm_chunk_free_t chunk_free;
    zend_mm_chunk_truncate_t chunk_truncate;
    zend_mm_chunk_extend_t chunk_extend;
} zend_mm_handlers;

extern const zend_mm_handlers *zend_mm_platform_handlers;
#define ZEND_MM_PLATFORM_HARD_LIMIT 1
extern size_t zend_mm_platform_hard_limit;
