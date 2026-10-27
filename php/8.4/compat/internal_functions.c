/* Statically linked extensions. */
#include "php.h"
#include "php_main.h"
#include "zend_modules.h"
#include "zend_compile.h"
#include <stdarg.h>
#include <stdlib.h>
#include <stdio.h>

#include "ext/pcre/php_pcre.h"
#include "ext/hash/php_hash.h"
#include "ext/json/php_json.h"
#include "ext/random/php_random.h"
#include "ext/reflection/php_reflection.h"
#include "ext/spl/php_spl.h"
#include "ext/standard/php_standard.h"

static zend_module_entry * const php_builtin_extensions[] = {
	phpext_pcre_ptr,
	phpext_hash_ptr,
	phpext_json_ptr,
	phpext_random_ptr,
	phpext_reflection_ptr,
	phpext_standard_ptr,
	phpext_spl_ptr,
};

#define EXTCOUNT (sizeof(php_builtin_extensions)/sizeof(zend_module_entry *))

extern zend_module_entry *const phpbm_package_modules[];
extern const int phpbm_package_module_count;

#ifdef PHPBM_PHP_EXTENSIONS
/* Generated php_extensions.c: the optional extensions of the bm-php-ext packages, required ones first. */
extern zend_module_entry *const phpbm_php_extensions[];
extern const int phpbm_php_extension_count;
#endif

PHPAPI int php_register_internal_extensions(void)
{
	if (php_register_extensions(php_builtin_extensions, EXTCOUNT) == FAILURE) {
		return FAILURE;
	}
#ifdef PHPBM_PHP_EXTENSIONS
	if (php_register_extensions(phpbm_php_extensions, phpbm_php_extension_count) == FAILURE) {
		return FAILURE;
	}
#endif
	return php_register_extensions(phpbm_package_modules, phpbm_package_module_count);
}
