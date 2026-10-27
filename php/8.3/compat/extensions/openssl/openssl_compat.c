/*
 * openssl: a subset of ext/openssl on ESP-IDF's mbedTLS (PSA Crypto API, hardware AES/SHA where the chip has them).
 * AES-CBC/CTR/GCM openssl_encrypt/decrypt, openssl_digest, openssl_random_pseudo_bytes and the method lists.
 * No keys, certificates, signatures or TLS streams. Warnings and results follow ext/openssl.
 */
#include "php.h"
#include "ext/standard/base64.h"
#include "ext/standard/info.h"
#include "ext/standard/md5.h"
#include "zend_exceptions.h"

#include "mbedtls/build_info.h"
#include "mbedtls/platform_util.h"
#include "psa/crypto.h"

#include <string.h>

#define OC_RAW_DATA 1
#define OC_ZERO_PADDING 2
#define OC_DONT_ZERO_PAD_KEY 4

enum oc_mode { OC_CBC, OC_CTR, OC_GCM };

typedef struct {
    const char *name;
    enum oc_mode mode;
    uint16_t key_bits;
} oc_cipher;

/* Sorted by name, as openssl_get_cipher_methods() returns them. */
static const oc_cipher oc_ciphers[] = {
#if defined(PSA_WANT_ALG_CBC_PKCS7) && defined(PSA_WANT_ALG_CBC_NO_PADDING)
    {"aes-128-cbc", OC_CBC, 128},
#endif
#if defined(PSA_WANT_ALG_CTR)
    {"aes-128-ctr", OC_CTR, 128},
#endif
#if defined(PSA_WANT_ALG_GCM)
    {"aes-128-gcm", OC_GCM, 128},
#endif
#if defined(PSA_WANT_ALG_CBC_PKCS7) && defined(PSA_WANT_ALG_CBC_NO_PADDING)
    {"aes-192-cbc", OC_CBC, 192},
#endif
#if defined(PSA_WANT_ALG_CTR)
    {"aes-192-ctr", OC_CTR, 192},
#endif
#if defined(PSA_WANT_ALG_GCM)
    {"aes-192-gcm", OC_GCM, 192},
#endif
#if defined(PSA_WANT_ALG_CBC_PKCS7) && defined(PSA_WANT_ALG_CBC_NO_PADDING)
    {"aes-256-cbc", OC_CBC, 256},
#endif
#if defined(PSA_WANT_ALG_CTR)
    {"aes-256-ctr", OC_CTR, 256},
#endif
#if defined(PSA_WANT_ALG_GCM)
    {"aes-256-gcm", OC_GCM, 256},
#endif
};

typedef struct {
    const char *name;
    psa_algorithm_t alg;
} oc_digest;

static const oc_digest oc_digests[] = {
#if defined(PSA_WANT_ALG_MD5)
    {"md5", PSA_ALG_MD5},
#endif
#if defined(PSA_WANT_ALG_SHA_1)
    {"sha1", PSA_ALG_SHA_1},
#endif
#if defined(PSA_WANT_ALG_SHA_224)
    {"sha224", PSA_ALG_SHA_224},
#endif
#if defined(PSA_WANT_ALG_SHA_256)
    {"sha256", PSA_ALG_SHA_256},
#endif
#if defined(PSA_WANT_ALG_SHA3_224)
    {"sha3-224", PSA_ALG_SHA3_224},
#endif
#if defined(PSA_WANT_ALG_SHA3_256)
    {"sha3-256", PSA_ALG_SHA3_256},
#endif
#if defined(PSA_WANT_ALG_SHA3_384)
    {"sha3-384", PSA_ALG_SHA3_384},
#endif
#if defined(PSA_WANT_ALG_SHA3_512)
    {"sha3-512", PSA_ALG_SHA3_512},
#endif
#if defined(PSA_WANT_ALG_SHA_384)
    {"sha384", PSA_ALG_SHA_384},
#endif
#if defined(PSA_WANT_ALG_SHA_512)
    {"sha512", PSA_ALG_SHA_512},
#endif
};

#define OC_COUNT(a) (sizeof(a) / sizeof((a)[0]))

typedef struct {
    const char *alias;
    const char *name;
    bool        listed;   /* false: a provider-only name OpenSSL accepts but does not list */
} oc_alias;

/* OpenSSL's aliases for the same algorithms (case-insensitive like the names); the listed ones are what
 * $aliases = true adds, spelled as PHP lists them. */
static const oc_alias oc_cipher_aliases[] = {
    {"aes128", "aes-128-cbc", true},
    {"aes192", "aes-192-cbc", true},
    {"aes256", "aes-256-cbc", true},
    {"id-aes128-GCM", "aes-128-gcm", true},
    {"id-aes192-GCM", "aes-192-gcm", true},
    {"id-aes256-GCM", "aes-256-gcm", true},
};

static const oc_alias oc_digest_aliases[] = {
    {"sha-1", "sha1", false},
    {"sha-224", "sha224", false},
    {"sha-256", "sha256", false},
    {"sha-384", "sha384", false},
    {"sha-512", "sha512", false},
    {"sha2-224", "sha224", false},
    {"sha2-256", "sha256", false},
    {"sha2-384", "sha384", false},
    {"sha2-512", "sha512", false},
    {"ssl3-md5", "md5", true},
    {"ssl3-sha1", "sha1", true},
};

static bool oc_name_is(const char *name, size_t len, const char *candidate)
{
    return strlen(candidate) == len && zend_binary_strcasecmp(name, len, candidate, len) == 0;
}

/* An alias resolves to its canonical name, kept only if that algorithm is in the table. */
static const char *oc_unalias(const oc_alias *aliases, size_t count, const char *name, size_t *len)
{
    for (size_t i = 0; i < count; i++) {
        if (oc_name_is(name, *len, aliases[i].alias)) {
            *len = strlen(aliases[i].name);
            return aliases[i].name;
        }
    }
    return name;
}

static const oc_cipher *oc_find_cipher(const char *name, size_t len)
{
    name = oc_unalias(oc_cipher_aliases, OC_COUNT(oc_cipher_aliases), name, &len);
    for (size_t i = 0; i < OC_COUNT(oc_ciphers); i++) {
        if (oc_name_is(name, len, oc_ciphers[i].name)) {
            return &oc_ciphers[i];
        }
    }
    return NULL;
}

static const oc_digest *oc_find_digest(const char *name, size_t len)
{
    name = oc_unalias(oc_digest_aliases, OC_COUNT(oc_digest_aliases), name, &len);
    for (size_t i = 0; i < OC_COUNT(oc_digests); i++) {
        if (oc_name_is(name, len, oc_digests[i].name)) {
            return &oc_digests[i];
        }
    }
    return NULL;
}

static int oc_compare_names(Bucket *a, Bucket *b)
{
    return zend_binary_strcmp(Z_STRVAL(a->val), Z_STRLEN(a->val), Z_STRVAL(b->val), Z_STRLEN(b->val));
}

/* Adds the aliases whose algorithm is in the list, then sorts it (openssl_get_*_methods(true)). */
static void oc_add_aliases(zval *list, const oc_alias *aliases, size_t count, bool (*present)(const char *name))
{
    for (size_t i = 0; i < count; i++) {
        if (aliases[i].listed && present(aliases[i].name)) {
            add_next_index_string(list, aliases[i].alias);
        }
    }
    zend_hash_sort(Z_ARRVAL_P(list), oc_compare_names, true);
}

static bool oc_cipher_present(const char *name)
{
    return oc_find_cipher(name, strlen(name)) != NULL;
}

static bool oc_digest_present(const char *name)
{
    return oc_find_digest(name, strlen(name)) != NULL;
}

static size_t oc_iv_length(const oc_cipher *c)
{
    return c->mode == OC_GCM ? 12 : 16;
}

/* GCM tag lengths PSA accepts (4, 8, 12..16). */
static bool oc_valid_tag_length(zend_long len)
{
    return len == 4 || len == 8 || (len >= 12 && len <= 16);
}

/* The key: zero-padded or truncated to the cipher's length, as ext/openssl does. */
static bool oc_import_key(const oc_cipher *c, const char *password, size_t password_len, zend_long options,
                          psa_algorithm_t alg, psa_key_id_t *key)
{
    size_t key_len = c->key_bits / 8;
    unsigned char buf[32] = {0};
    if (password_len < key_len && (options & OC_DONT_ZERO_PAD_KEY)) {
        php_error_docref(NULL, E_WARNING, "Key length cannot be set for the cipher algorithm");
        return false;
    }
    /* The key copy is wiped on every path below (mbedtls_platform_zeroize is not optimized away). */
    memcpy(buf, password, password_len < key_len ? password_len : key_len);

    psa_key_attributes_t attributes = PSA_KEY_ATTRIBUTES_INIT;
    psa_set_key_type(&attributes, PSA_KEY_TYPE_AES);
    psa_set_key_bits(&attributes, c->key_bits);
    psa_set_key_usage_flags(&attributes, PSA_KEY_USAGE_ENCRYPT | PSA_KEY_USAGE_DECRYPT);
    psa_set_key_algorithm(&attributes, alg);
    psa_status_t status = psa_import_key(&attributes, buf, key_len, key);
    psa_reset_key_attributes(&attributes);
    mbedtls_platform_zeroize(buf, sizeof buf);
    return status == PSA_SUCCESS;
}

/* ext/openssl's IV rules for the non-AEAD modes: empty is zeros, short is padded, long is truncated. */
static void oc_fix_iv(unsigned char out[16], const char *iv, size_t iv_len, bool encrypt)
{
    memset(out, 0, 16);
    if (iv_len == 0) {
        if (encrypt) {
            php_error_docref(NULL, E_WARNING, "Using an empty Initialization Vector (iv) is potentially insecure and not recommended");
        }
        return;
    }
    if (iv_len < 16) {
        php_error_docref(NULL, E_WARNING, "IV passed is only %zd bytes long, cipher expects an IV of precisely %zd bytes, padding with \\0", iv_len, (size_t) 16);
    } else if (iv_len > 16) {
        php_error_docref(NULL, E_WARNING, "IV passed is %zd bytes long which is longer than the %zd expected by selected cipher, truncating", iv_len, (size_t) 16);
    }
    memcpy(out, iv, iv_len < 16 ? iv_len : 16);
}

static psa_algorithm_t oc_block_alg(const oc_cipher *c, zend_long options)
{
    if (c->mode == OC_CTR) {
        return PSA_ALG_CTR;
    }
    return (options & OC_ZERO_PADDING) ? PSA_ALG_CBC_NO_PADDING : PSA_ALG_CBC_PKCS7;
}

/* CBC/CTR with an explicit IV: out holds len + 16 bytes. */
static bool oc_block_crypt(const oc_cipher *c, zend_long options, const char *password, size_t password_len,
                           const unsigned char iv[16], const unsigned char *in, size_t len,
                           unsigned char *out, size_t *out_len, bool encrypt)
{
    psa_algorithm_t alg = oc_block_alg(c, options);
    psa_key_id_t key;
    if (!oc_import_key(c, password, password_len, options, alg, &key)) {
        return false;
    }
    psa_cipher_operation_t op = PSA_CIPHER_OPERATION_INIT;
    size_t done = 0, last = 0;
    psa_status_t status = encrypt ? psa_cipher_encrypt_setup(&op, key, alg) : psa_cipher_decrypt_setup(&op, key, alg);
    if (status == PSA_SUCCESS) {
        status = psa_cipher_set_iv(&op, iv, 16);
    }
    if (status == PSA_SUCCESS) {
        status = psa_cipher_update(&op, in, len, out, len + 16, &done);
    }
    if (status == PSA_SUCCESS) {
        status = psa_cipher_finish(&op, out + done, len + 16 - done, &last);
    }
    psa_cipher_abort(&op);
    psa_destroy_key(key);
    *out_len = done + last;
    return status == PSA_SUCCESS;
}

PHP_FUNCTION(openssl_encrypt)
{
    zend_long options = 0, tag_len = 16;
    char *data, *method, *password, *iv = "", *aad = "";
    size_t data_len, method_len, password_len, iv_len = 0, aad_len = 0;
    zval *tag = NULL;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sss|lszsl", &data, &data_len, &method, &method_len,
            &password, &password_len, &options, &iv, &iv_len, &tag, &aad, &aad_len, &tag_len) == FAILURE) {
        RETURN_THROWS();
    }
    const oc_cipher *c = oc_find_cipher(method, method_len);
    if (c == NULL) {
        php_error_docref(NULL, E_WARNING, "Unknown cipher algorithm");
        RETURN_FALSE;
    }

    zend_string *out;
    size_t out_len;
    if (c->mode == OC_GCM) {
        if (iv_len == 0) {
            php_error_docref(NULL, E_WARNING, "Setting of IV length for AEAD mode failed");
            RETURN_FALSE;
        }
        if (tag == NULL) {
            php_error_docref(NULL, E_WARNING, "A tag should be provided when using AEAD mode");
            RETURN_FALSE;
        }
        if (!oc_valid_tag_length(tag_len)) {
            php_error_docref(NULL, E_WARNING, "Retrieving verification tag failed");
            RETURN_FALSE;
        }
        psa_algorithm_t alg = PSA_ALG_AEAD_WITH_SHORTENED_TAG(PSA_ALG_GCM, (size_t) tag_len);
        psa_key_id_t key;
        if (!oc_import_key(c, password, password_len, options, alg, &key)) {
            RETURN_FALSE;
        }
        out = zend_string_alloc(data_len + (size_t) tag_len, 0);
        psa_status_t status = psa_aead_encrypt(key, alg, (const uint8_t *) iv, iv_len, (const uint8_t *) aad, aad_len,
            (const uint8_t *) data, data_len, (uint8_t *) ZSTR_VAL(out), data_len + (size_t) tag_len, &out_len);
        psa_destroy_key(key);
        if (status != PSA_SUCCESS || out_len != data_len + (size_t) tag_len) {
            zend_string_efree(out);
            RETURN_FALSE;
        }
        ZEND_TRY_ASSIGN_REF_STRINGL(tag, ZSTR_VAL(out) + data_len, (size_t) tag_len);
        out_len = data_len;
    } else {
        unsigned char ivbuf[16];
        oc_fix_iv(ivbuf, iv, iv_len, true);
        out = zend_string_alloc(data_len + 16, 0);
        if (!oc_block_crypt(c, options, password, password_len, ivbuf, (const unsigned char *) data, data_len,
                (unsigned char *) ZSTR_VAL(out), &out_len, true)) {
            zend_string_efree(out);
            RETURN_FALSE;
        }
        if (tag) {
            ZEND_TRY_ASSIGN_REF_NULL(tag);
        }
    }

    if (options & OC_RAW_DATA) {
        ZSTR_VAL(out)[out_len] = '\0';
        ZSTR_LEN(out) = out_len;
        RETURN_NEW_STR(out);
    }
    zend_string *b64 = php_base64_encode((const unsigned char *) ZSTR_VAL(out), out_len);
    zend_string_efree(out);
    RETURN_STR(b64);
}

PHP_FUNCTION(openssl_decrypt)
{
    zend_long options = 0;
    char *data, *method, *password, *iv = "", *tag = NULL, *aad = "";
    size_t data_len, method_len, password_len, iv_len = 0, tag_len = 0, aad_len = 0;

    if (zend_parse_parameters(ZEND_NUM_ARGS(), "sss|lss!s", &data, &data_len, &method, &method_len,
            &password, &password_len, &options, &iv, &iv_len, &tag, &tag_len, &aad, &aad_len) == FAILURE) {
        RETURN_THROWS();
    }
    if (!method_len) {
        zend_argument_value_error(2, "cannot be empty");
        RETURN_THROWS();
    }
    const oc_cipher *c = oc_find_cipher(method, method_len);
    if (c == NULL) {
        php_error_docref(NULL, E_WARNING, "Unknown cipher algorithm");
        RETURN_FALSE;
    }

    zend_string *decoded = NULL;
    const unsigned char *in = (const unsigned char *) data;
    size_t in_len = data_len;
    if (!(options & OC_RAW_DATA)) {
        decoded = php_base64_decode((const unsigned char *) data, data_len);
        if (decoded == NULL) {
            php_error_docref(NULL, E_WARNING, "Failed to base64 decode the input");
            RETURN_FALSE;
        }
        in = (const unsigned char *) ZSTR_VAL(decoded);
        in_len = ZSTR_LEN(decoded);
    }

    zend_string *out = NULL;
    size_t out_len = 0;
    bool ok = false;
    if (c->mode == OC_GCM) {
        if (iv_len == 0) {
            php_error_docref(NULL, E_WARNING, "Setting of IV length for AEAD mode failed");
        } else if (tag != NULL && tag_len > 0 && !oc_valid_tag_length((zend_long) tag_len)) {
            php_error_docref(NULL, E_WARNING, "Setting tag for AEAD cipher decryption failed");
        } else if (tag != NULL && tag_len > 0) {
            psa_algorithm_t alg = PSA_ALG_AEAD_WITH_SHORTENED_TAG(PSA_ALG_GCM, tag_len);
            psa_key_id_t key;
            if (oc_import_key(c, password, password_len, options, alg, &key)) {
                /* PSA takes the ciphertext followed by its tag. */
                unsigned char *joined = emalloc(in_len + tag_len);
                memcpy(joined, in, in_len);
                memcpy(joined + in_len, tag, tag_len);
                out = zend_string_alloc(in_len, 0);
                ok = psa_aead_decrypt(key, alg, (const uint8_t *) iv, iv_len, (const uint8_t *) aad, aad_len,
                    joined, in_len + tag_len, (uint8_t *) ZSTR_VAL(out), in_len, &out_len) == PSA_SUCCESS;
                efree(joined);
                psa_destroy_key(key);
            }
        }
    } else {
        if (tag != NULL && tag_len > 0) {
            php_error_docref(NULL, E_WARNING, "The tag cannot be used because the cipher algorithm does not support AEAD");
        }
        unsigned char ivbuf[16];
        oc_fix_iv(ivbuf, iv, iv_len, false);
        out = zend_string_alloc(in_len + 16, 0);
        ok = oc_block_crypt(c, options, password, password_len, ivbuf, in, in_len, (unsigned char *) ZSTR_VAL(out), &out_len, false);
    }
    if (decoded) {
        zend_string_free(decoded);
    }
    if (!ok) {
        if (out) {
            zend_string_efree(out);
        }
        RETURN_FALSE;
    }
    ZSTR_VAL(out)[out_len] = '\0';
    ZSTR_LEN(out) = out_len;
    RETURN_NEW_STR(out);
}

static void oc_cipher_length(INTERNAL_FUNCTION_PARAMETERS, bool iv)
{
    char *method;
    size_t method_len;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "s", &method, &method_len) == FAILURE) {
        RETURN_THROWS();
    }
    if (!method_len) {
        zend_argument_value_error(1, "cannot be empty");
        RETURN_THROWS();
    }
    const oc_cipher *c = oc_find_cipher(method, method_len);
    if (c == NULL) {
        php_error_docref(NULL, E_WARNING, "Unknown cipher algorithm");
        RETURN_FALSE;
    }
    RETURN_LONG(iv ? (zend_long) oc_iv_length(c) : (zend_long) (c->key_bits / 8));
}

PHP_FUNCTION(openssl_cipher_iv_length)
{
    oc_cipher_length(INTERNAL_FUNCTION_PARAM_PASSTHRU, true);
}

PHP_FUNCTION(openssl_cipher_key_length)
{
    oc_cipher_length(INTERNAL_FUNCTION_PARAM_PASSTHRU, false);
}

PHP_FUNCTION(openssl_get_cipher_methods)
{
    bool aliases = false;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|b", &aliases) == FAILURE) {
        RETURN_THROWS();
    }
    array_init(return_value);
    for (size_t i = 0; i < OC_COUNT(oc_ciphers); i++) {
        add_next_index_string(return_value, oc_ciphers[i].name);
    }
    if (aliases) {
        oc_add_aliases(return_value, oc_cipher_aliases, OC_COUNT(oc_cipher_aliases), oc_cipher_present);
    }
}

PHP_FUNCTION(openssl_get_md_methods)
{
    bool aliases = false;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "|b", &aliases) == FAILURE) {
        RETURN_THROWS();
    }
    array_init(return_value);
    for (size_t i = 0; i < OC_COUNT(oc_digests); i++) {
        add_next_index_string(return_value, oc_digests[i].name);
    }
    if (aliases) {
        oc_add_aliases(return_value, oc_digest_aliases, OC_COUNT(oc_digest_aliases), oc_digest_present);
    }
}

PHP_FUNCTION(openssl_digest)
{
    char *data, *method;
    size_t data_len, method_len;
    bool raw = false;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "ss|b", &data, &data_len, &method, &method_len, &raw) == FAILURE) {
        RETURN_THROWS();
    }
    const oc_digest *d = oc_find_digest(method, method_len);
    if (d == NULL) {
        php_error_docref(NULL, E_WARNING, "Unknown digest algorithm");
        RETURN_FALSE;
    }
    unsigned char hash[PSA_HASH_MAX_SIZE];
    size_t hash_len = 0;
    if (psa_hash_compute(d->alg, (const uint8_t *) data, data_len, hash, sizeof hash, &hash_len) != PSA_SUCCESS) {
        RETURN_FALSE;
    }
    if (raw) {
        RETURN_STRINGL((const char *) hash, hash_len);
    }
    zend_string *hex = zend_string_alloc(hash_len * 2, 0);
    make_digest_ex(ZSTR_VAL(hex), hash, (int) hash_len);
    ZSTR_VAL(hex)[hash_len * 2] = '\0';
    RETURN_NEW_STR(hex);
}

PHP_FUNCTION(openssl_random_pseudo_bytes)
{
    zend_long length;
    zval *strong = NULL;
    if (zend_parse_parameters(ZEND_NUM_ARGS(), "l|z", &length, &strong) == FAILURE) {
        RETURN_THROWS();
    }
    if (length <= 0) {
        zend_argument_value_error(1, "must be greater than 0");
        RETURN_THROWS();
    }
    zend_string *buffer = zend_string_alloc((size_t) length, 0);
    if (psa_generate_random((uint8_t *) ZSTR_VAL(buffer), (size_t) length) != PSA_SUCCESS) {
        zend_string_efree(buffer);
        zend_throw_exception(zend_ce_exception, "Error reading from source device", 0);
        if (strong) {
            ZEND_TRY_ASSIGN_REF_FALSE(strong);
        }
        RETURN_THROWS();
    }
    ZSTR_VAL(buffer)[length] = '\0';
    if (strong) {
        ZEND_TRY_ASSIGN_REF_TRUE(strong);
    }
    RETURN_NEW_STR(buffer);
}

/* No OpenSSL error queue: failures are reported through warnings and return values. */
PHP_FUNCTION(openssl_error_string)
{
    ZEND_PARSE_PARAMETERS_NONE();
    RETURN_FALSE;
}

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_openssl_encrypt, 0, 3, MAY_BE_STRING|MAY_BE_FALSE)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, cipher_algo, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, passphrase, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, iv, IS_STRING, 0, "\"\"")
    ZEND_ARG_INFO_WITH_DEFAULT_VALUE(1, tag, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, aad, IS_STRING, 0, "\"\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, tag_length, IS_LONG, 0, "16")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_openssl_decrypt, 0, 3, MAY_BE_STRING|MAY_BE_FALSE)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, cipher_algo, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, passphrase, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, options, IS_LONG, 0, "0")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, iv, IS_STRING, 0, "\"\"")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, tag, IS_STRING, 1, "null")
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, aad, IS_STRING, 0, "\"\"")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_openssl_cipher_iv_length, 0, 1, MAY_BE_LONG|MAY_BE_FALSE)
    ZEND_ARG_TYPE_INFO(0, cipher_algo, IS_STRING, 0)
ZEND_END_ARG_INFO()

#define arginfo_openssl_cipher_key_length arginfo_openssl_cipher_iv_length

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_openssl_get_md_methods, 0, 0, IS_ARRAY, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, aliases, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

#define arginfo_openssl_get_cipher_methods arginfo_openssl_get_md_methods

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_openssl_digest, 0, 2, MAY_BE_STRING|MAY_BE_FALSE)
    ZEND_ARG_TYPE_INFO(0, data, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, digest_algo, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO_WITH_DEFAULT_VALUE(0, binary, _IS_BOOL, 0, "false")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_INFO_EX(arginfo_openssl_random_pseudo_bytes, 0, 1, IS_STRING, 0)
    ZEND_ARG_TYPE_INFO(0, length, IS_LONG, 0)
    ZEND_ARG_INFO_WITH_DEFAULT_VALUE(1, strong_result, "null")
ZEND_END_ARG_INFO()

ZEND_BEGIN_ARG_WITH_RETURN_TYPE_MASK_EX(arginfo_openssl_error_string, 0, 0, MAY_BE_STRING|MAY_BE_FALSE)
ZEND_END_ARG_INFO()

static const zend_function_entry openssl_functions[] = {
    ZEND_FE(openssl_encrypt, arginfo_openssl_encrypt)
    ZEND_FE(openssl_decrypt, arginfo_openssl_decrypt)
    ZEND_FE(openssl_cipher_iv_length, arginfo_openssl_cipher_iv_length)
    ZEND_FE(openssl_cipher_key_length, arginfo_openssl_cipher_key_length)
    ZEND_FE(openssl_get_cipher_methods, arginfo_openssl_get_cipher_methods)
    ZEND_FE(openssl_get_md_methods, arginfo_openssl_get_md_methods)
    ZEND_FE(openssl_digest, arginfo_openssl_digest)
    ZEND_FE(openssl_random_pseudo_bytes, arginfo_openssl_random_pseudo_bytes)
    ZEND_FE(openssl_error_string, arginfo_openssl_error_string)
    ZEND_FE_END
};

static PHP_MINIT_FUNCTION(openssl)
{
    (void) type;
    if (psa_crypto_init() != PSA_SUCCESS) {
        return FAILURE;
    }
    REGISTER_LONG_CONSTANT("OPENSSL_RAW_DATA", OC_RAW_DATA, CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OPENSSL_ZERO_PADDING", OC_ZERO_PADDING, CONST_PERSISTENT);
    REGISTER_LONG_CONSTANT("OPENSSL_DONT_ZERO_PAD_KEY", OC_DONT_ZERO_PAD_KEY, CONST_PERSISTENT);
    return SUCCESS;
}

static PHP_MINFO_FUNCTION(openssl)
{
    (void) zend_module;
    php_info_print_table_start();
    php_info_print_table_row(2, "OpenSSL support", "subset: AES-CBC/CTR/GCM, digests, random bytes");
    php_info_print_table_row(2, "Library", "mbedTLS " MBEDTLS_VERSION_STRING " (PSA Crypto)");
    php_info_print_table_end();
}

zend_module_entry openssl_module_entry = {
    STANDARD_MODULE_HEADER,
    "openssl",
    openssl_functions,
    PHP_MINIT(openssl),
    NULL,
    NULL,
    NULL,
    PHP_MINFO(openssl),
    PHP_VERSION,
    STANDARD_MODULE_PROPERTIES
};
