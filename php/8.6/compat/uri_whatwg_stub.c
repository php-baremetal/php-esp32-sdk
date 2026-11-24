/*
 * Stand-in for PHP 8.6's WHATWG URL parser (ext/uri/uri_parser_whatwg.c), which needs lexbor: its static
 * tables do not fit in internal RAM, so lexbor is not built. The RFC 3986 (uriparser) and parse_url() parsers
 * stay; Uri\WhatWg\Url registers but throws when constructed, as do Uri\WhatWg\UrlBuilder::build() and
 * Uri\WhatWg\url_percent_encode().
 *
 * php_uri.c uses the parser struct, its RINIT and post-deactivate hooks, and since PHP 8.6 the builder's
 * validators, build_from_zval(), the percent-encoders and two readers of a parsed URL. uri_parser_whatwg.h is not
 * included: it pulls in lexbor/url/url.h, so the lxb_url_t pointers are void * here. No Uri\WhatWg\Url object
 * can exist, so is_special() and host_type_read() are never reached.
 */
#include "php.h"
#include "php_uri_common.h"

static void whatwg_unavailable(void)
{
	zend_throw_error(NULL,
		"The WHATWG URL parser is not available in this firmware (lexbor is not built); "
		"use Uri\\Rfc3986\\Uri or parse_url() instead");
}

static void *whatwg_parse(const char *uri_str, size_t uri_str_len, const void *base_url, zval *errors, bool silent)
{
	(void) uri_str; (void) uri_str_len; (void) base_url; (void) errors;
	if (!silent) {
		whatwg_unavailable();
	}
	return NULL;
}
static void *whatwg_clone(void *uri) { (void) uri; return NULL; }
static zend_string *whatwg_to_string(void *uri, php_uri_recomposition_mode mode, bool exclude_fragment)
{
	(void) uri; (void) mode; (void) exclude_fragment; return NULL;
}
static void whatwg_destroy(void *uri) { (void) uri; }
static zend_result whatwg_read(void *uri, php_uri_component_read_mode read_mode, zval *retval)
{
	(void) uri; (void) read_mode; (void) retval; return FAILURE;
}
static zend_result whatwg_write(void *uri, const zval *value, zval *errors)
{
	(void) uri; (void) value; (void) errors; return FAILURE;
}

PHPAPI const php_uri_parser php_uri_parser_whatwg = {
	.name = PHP_URI_PARSER_WHATWG,
	.parse = whatwg_parse,
	.clone = whatwg_clone,
	.to_string = whatwg_to_string,
	.destroy = whatwg_destroy,
	{
		.scheme   = { .read = whatwg_read, .write = whatwg_write },
		.username = { .read = whatwg_read, .write = whatwg_write },
		.password = { .read = whatwg_read, .write = whatwg_write },
		.host     = { .read = whatwg_read, .write = whatwg_write },
		.port     = { .read = whatwg_read, .write = whatwg_write },
		.path     = { .read = whatwg_read, .write = whatwg_write },
		.query    = { .read = whatwg_read, .write = whatwg_write },
		.fragment = { .read = whatwg_read, .write = whatwg_write },
	}
};

PHP_RINIT_FUNCTION(uri_parser_whatwg) { return SUCCESS; }
ZEND_MODULE_POST_ZEND_DEACTIVATE_D(uri_parser_whatwg) { return SUCCESS; }

/* Uri\WhatWg\UrlBuilder: the setters accept every value; build() throws. */
zend_result php_uri_parser_whatwg_none_validate(const zend_string *component) { (void) component; return SUCCESS; }
zend_result php_uri_parser_whatwg_scheme_validate(const zend_string *scheme) { (void) scheme; return SUCCESS; }
zend_result php_uri_parser_whatwg_host_validate(const zend_string *host) { (void) host; return SUCCESS; }
zend_result php_uri_parser_whatwg_port_validate(zend_long port) { (void) port; return SUCCESS; }

void *php_uri_parser_whatwg_build_from_zval(
	void *base_url, const zval *scheme, const zval *username, const zval *password,
	const zval *host, const zval *port, const zval *path, const zval *query, const zval *fragment,
	zval *soft_errors_zv)
{
	(void) base_url; (void) scheme; (void) username; (void) password; (void) host; (void) port; (void) path;
	(void) query; (void) fragment; (void) soft_errors_zv;
	whatwg_unavailable();
	return NULL;
}

/* Uri\WhatWg\url_percent_encode(): the encode sets live in lexbor. */
#define WHATWG_PERCENT_ENCODE_STUB(name) \
	zend_string *php_uri_parser_whatwg_##name##_percent_encode(const char *str, size_t str_length) \
	{ \
		(void) str; (void) str_length; \
		whatwg_unavailable(); \
		return NULL; \
	}
WHATWG_PERCENT_ENCODE_STUB(userinfo)
WHATWG_PERCENT_ENCODE_STUB(opaque_host)
WHATWG_PERCENT_ENCODE_STUB(path)
WHATWG_PERCENT_ENCODE_STUB(opaque_path)
WHATWG_PERCENT_ENCODE_STUB(path_segment)
WHATWG_PERCENT_ENCODE_STUB(query)
WHATWG_PERCENT_ENCODE_STUB(special_query)
WHATWG_PERCENT_ENCODE_STUB(form_query)
WHATWG_PERCENT_ENCODE_STUB(fragment)

/* Readers of a parsed Uri\WhatWg\Url, which cannot be constructed here. */
bool php_uri_parser_whatwg_is_special(const void *lexbor_uri) { (void) lexbor_uri; return false; }
void php_uri_parser_whatwg_host_type_read(const void *lexbor_uri, zval *retval) { (void) lexbor_uri; ZVAL_NULL(retval); }
