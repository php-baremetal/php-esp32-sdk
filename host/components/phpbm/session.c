/* Defaults of the session extension: with the microsd storage the files handler in
 * <volume holding the sources>/sessions (/sdcard/sessions), otherwise the "memory" handler (sessions in RAM until restart). */
#include <errno.h>
#include <stdio.h>
#include <string.h>
#include <sys/stat.h>
#include <time.h>

#include "esp_log.h"

#include "php.h"
#include "ext/session/php_session.h"
#include "phpbm.h"
#include "phpbm_options.h"

/* Bounds of the RAM store: when either is exceeded the least recently written session is dropped. */
#define PHPBM_SESSION_MAX_ENTRIES 64
#define PHPBM_SESSION_MAX_BYTES (64 * 1024)

typedef struct {
    zend_string *data;
    time_t mtime;
    uint32_t seq;
} phpbm_session_entry;

static HashTable s_sessions;
static bool s_sessions_ready;
static size_t s_sessions_bytes;
static uint32_t s_sessions_seq;
static bool s_sessions_evict_logged;

static void phpbm_session_entry_free(zval *zv)
{
    phpbm_session_entry *entry = Z_PTR_P(zv);
    s_sessions_bytes -= ZSTR_LEN(entry->data);
    zend_string_release_ex(entry->data, 1);
    pefree(entry, 1);
}

static phpbm_session_entry *phpbm_session_find(zend_string *key)
{
    return s_sessions_ready ? zend_hash_find_ptr(&s_sessions, key) : NULL;
}

static PS_OPEN_FUNC(memory)
{
    (void) save_path;
    (void) session_name;
    if (!s_sessions_ready) {
        zend_hash_init(&s_sessions, 8, NULL, phpbm_session_entry_free, 1);
        s_sessions_ready = true;
    }
    PS_SET_MOD_DATA(&s_sessions);
    return SUCCESS;
}

static PS_CLOSE_FUNC(memory)
{
    PS_SET_MOD_DATA(NULL);
    return SUCCESS;
}

static PS_READ_FUNC(memory)
{
    (void) mod_data;
    phpbm_session_entry *entry = phpbm_session_find(key);
    if (entry != NULL && (maxlifetime <= 0 || time(NULL) - entry->mtime <= maxlifetime)) {
        *val = zend_string_init(ZSTR_VAL(entry->data), ZSTR_LEN(entry->data), 0);
    } else {
        *val = ZSTR_EMPTY_ALLOC();
    }
    return SUCCESS;
}

static void phpbm_session_evict_oldest(void)
{
    zend_string *oldest_key = NULL;
    uint32_t oldest_seq = 0;
    zend_string *key;
    zval *zv;
    ZEND_HASH_FOREACH_STR_KEY_VAL(&s_sessions, key, zv) {
        const phpbm_session_entry *entry = Z_PTR_P(zv);
        if (oldest_key == NULL || (int32_t) (entry->seq - oldest_seq) < 0) {
            oldest_key = key;
            oldest_seq = entry->seq;
        }
    } ZEND_HASH_FOREACH_END();
    if (oldest_key != NULL) {
        zend_hash_del(&s_sessions, oldest_key);
        if (!s_sessions_evict_logged) {
            s_sessions_evict_logged = true;
            ESP_LOGW("phpbm", "session store full (%d entries, %d KB): dropping the oldest sessions",
                PHPBM_SESSION_MAX_ENTRIES, PHPBM_SESSION_MAX_BYTES / 1024);
        }
    }
}

static PS_WRITE_FUNC(memory)
{
    (void) mod_data;
    (void) maxlifetime;
    if (ZSTR_LEN(val) > PHPBM_SESSION_MAX_BYTES) {
        return FAILURE;
    }
    zend_hash_str_del(&s_sessions, ZSTR_VAL(key), ZSTR_LEN(key));
    while (zend_hash_num_elements(&s_sessions) >= PHPBM_SESSION_MAX_ENTRIES
           || s_sessions_bytes + ZSTR_LEN(val) > PHPBM_SESSION_MAX_BYTES) {
        phpbm_session_evict_oldest();
    }
    phpbm_session_entry *entry = pemalloc(sizeof(*entry), 1);
    entry->data = zend_string_init(ZSTR_VAL(val), ZSTR_LEN(val), 1);
    entry->mtime = time(NULL);
    entry->seq = ++s_sessions_seq;
    s_sessions_bytes += ZSTR_LEN(val);
    zend_hash_str_add_new_ptr(&s_sessions, ZSTR_VAL(key), ZSTR_LEN(key), entry);
    return SUCCESS;
}

static PS_DESTROY_FUNC(memory)
{
    (void) mod_data;
    if (s_sessions_ready) {
        zend_hash_str_del(&s_sessions, ZSTR_VAL(key), ZSTR_LEN(key));
    }
    return SUCCESS;
}

static int phpbm_session_expired(zval *zv, void *arg)
{
    const phpbm_session_entry *entry = Z_PTR_P(zv);
    return entry->mtime < *(const time_t *) arg ? ZEND_HASH_APPLY_REMOVE : ZEND_HASH_APPLY_KEEP;
}

static PS_GC_FUNC(memory)
{
    (void) mod_data;
    *nrdels = 0;
    if (s_sessions_ready && maxlifetime > 0) {
        uint32_t before = zend_hash_num_elements(&s_sessions);
        time_t cutoff = time(NULL) - (time_t) maxlifetime;
        zend_hash_apply_with_argument(&s_sessions, phpbm_session_expired, &cutoff);
        *nrdels = (zend_long) (before - zend_hash_num_elements(&s_sessions));
    }
    return *nrdels;
}

static PS_CREATE_SID_FUNC(memory)
{
    zend_string *sid;
    do {
        sid = php_session_create_id(mod_data);
        if (sid == NULL || phpbm_session_find(sid) == NULL) {
            return sid;
        }
        zend_string_release_ex(sid, 0);
    } while (true);
}

static PS_VALIDATE_SID_FUNC(memory)
{
    (void) mod_data;
    return phpbm_session_find(key) != NULL ? SUCCESS : FAILURE;
}

static PS_UPDATE_TIMESTAMP_FUNC(memory)
{
    (void) mod_data;
    (void) val;
    (void) maxlifetime;
    phpbm_session_entry *entry = phpbm_session_find(key);
    if (entry != NULL) {
        entry->mtime = time(NULL);
    }
    return SUCCESS;
}

static const ps_module phpbm_session_memory = {PS_MOD_UPDATE_TIMESTAMP(memory)};

void phpbm_session_setup(HashTable *ini)
{
    php_session_register_module(&phpbm_session_memory);
#if PHPBM_STORAGE_MICROSD
    static char dir[64];
    const char *root = phpbm_source_dir();
    snprintf(dir, sizeof(dir), "%.*s/sessions", (int) (strcspn(root + 1, "/") + 1), root);
    if (mkdir(dir, 0777) != 0 && errno != EEXIST) {
        ESP_LOGW("phpbm", "cannot create %s: %s", dir, strerror(errno));
    }
    phpbm_ini_set(ini, "session.save_handler", "files");
    phpbm_ini_set(ini, "session.save_path", dir);
#else
    phpbm_ini_set(ini, "session.save_handler", "memory");
#endif
}
