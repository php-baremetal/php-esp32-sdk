/* The engine functions that zend_alloc.c calls to report an out-of-memory error (PHP 8.3.37, 8.4.28, 8.5.13, 8.6 and
   later: the delayed and the hard memory limit errors). The test never reaches them. No Zend header is included:
   some of these changed their return type between minors (zend_get_compiled_lineno: int, then uint32_t), and a
   pointer or an integer of the register's width links the same against either. */
#include <stdbool.h>
#include <stdint.h>
#include <stdlib.h>

void *zend_known_strings = NULL;
bool zend_is_executing(void) { return false; }
bool zend_is_compiling(void) { return false; }
void *zend_get_executed_filename_ex(void) { abort(); }
uint32_t zend_get_executed_lineno(void) { abort(); }
void *zend_get_compiled_filename(void) { abort(); }
uint32_t zend_get_compiled_lineno(void) { abort(); }
void zend_error_zstr_at(int type, void *filename, uint32_t lineno, void *message)
{
    (void) type; (void) filename; (void) lineno; (void) message;
    abort();
}
void zend_throw_unwind_exit(void) { abort(); }
