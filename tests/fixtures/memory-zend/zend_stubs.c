/* What zend_alloc.c links against besides itself, for mm_zend_test.c: the executor globals (the heap pointer of a
   build without ZTS) and the few engine functions it references on paths the test never takes. */
#include <stdio.h>
#include <stdlib.h>
#include <string.h>

#include "zend.h"
#include "zend_globals.h"

zend_executor_globals executor_globals;
ZEND_API ZEND_COLD ZEND_NORETURN void zend_error_noreturn(int type, const char *format, ...)
{
    (void) type;
    fprintf(stderr, "zend_error_noreturn: %s\n", format);
    exit(3);
}
ZEND_API ZEND_COLD ZEND_NORETURN void _zend_bailout(const char *filename, uint32_t lineno)
{
    fprintf(stderr, "bailout at %s:%u\n", filename, lineno);
    exit(3);
}
ZEND_API void ZEND_FASTCALL zend_hash_clean(HashTable *ht) { (void) ht; abort(); }
ZEND_API void ZEND_FASTCALL zend_hash_del_bucket(HashTable *ht, Bucket *p) { (void) ht; (void) p; abort(); }
ZEND_API void ZEND_FASTCALL zend_hash_destroy(HashTable *ht) { (void) ht; abort(); }
ZEND_API zval *ZEND_FASTCALL zend_hash_index_add_new(HashTable *ht, zend_ulong h, zval *pData)
{
    (void) ht; (void) h; (void) pData;
    abort();
}
ZEND_API zval *ZEND_FASTCALL zend_hash_index_find(const HashTable *ht, zend_ulong h) { (void) ht; (void) h; abort(); }
ZEND_API void ZEND_FASTCALL _zend_hash_init(HashTable *ht, uint32_t nSize, dtor_func_t pDestructor, bool persistent)
{
    (void) ht; (void) nSize; (void) pDestructor; (void) persistent;
    abort();
}
static void insecure_bytes(zend_random_bytes_insecure_state *state, void *bytes, size_t size)
{
    (void) state;
    memset(bytes, 0x3c, size);
}
ZEND_API void (*zend_random_bytes_insecure)(zend_random_bytes_insecure_state *state, void *bytes, size_t size) =
    insecure_bytes;
