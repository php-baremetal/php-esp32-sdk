#include <string.h>

#include "diskio_impl.h"
#include "esp_err.h"
#include "esp_log.h"
#include "esp_partition.h"
#include "esp_vfs_fat.h"
#include "ff.h"
#include "phpbm.h"

#define MOUNT_POINT "/app"
#define PARTITION "storage"

/* The image is read through the flash cache (mmap), never with SPI flash operations:
 * those disable the cache, which a task with its stack in PSRAM must never do. */

static const char *TAG = "phpbm";
static const uint8_t *s_image;
static esp_partition_mmap_handle_t s_map;
static size_t s_sector_size;
static size_t s_sector_count;
static BYTE s_pdrv = 0xFF;

static DSTATUS image_status(BYTE pdrv)
{
    (void) pdrv;
    return STA_PROTECT;
}

static DRESULT image_read(BYTE pdrv, BYTE *buff, DWORD sector, UINT count)
{
    (void) pdrv;
    if ((size_t) sector + count > s_sector_count) {
        return RES_PARERR;
    }
    memcpy(buff, s_image + (size_t) sector * s_sector_size, (size_t) count * s_sector_size);
    return RES_OK;
}

static DRESULT image_write(BYTE pdrv, const BYTE *buff, DWORD sector, UINT count)
{
    (void) pdrv;
    (void) buff;
    (void) sector;
    (void) count;
    return RES_WRPRT;
}

static DRESULT image_ioctl(BYTE pdrv, BYTE cmd, void *buff)
{
    (void) pdrv;
    switch (cmd) {
    case CTRL_SYNC:
        return RES_OK;
    case GET_SECTOR_COUNT:
        *(DWORD *) buff = s_sector_count;
        return RES_OK;
    case GET_SECTOR_SIZE:
        *(WORD *) buff = s_sector_size;
        return RES_OK;
    default:
        return RES_ERROR;
    }
}

static esp_err_t image_map(const esp_partition_t *part)
{
    const void *image;
    esp_err_t err = esp_partition_mmap(part, 0, part->size, ESP_PARTITION_MMAP_DATA, &image, &s_map);
    if (err != ESP_OK) {
        return err;
    }
    s_image = image;

    uint16_t sector_size, count16;
    uint32_t count32;
    memcpy(&sector_size, s_image + 11, sizeof(sector_size));
    memcpy(&count16, s_image + 19, sizeof(count16));
    memcpy(&count32, s_image + 32, sizeof(count32));
    s_sector_size = sector_size;
    s_sector_count = count16 != 0 ? count16 : count32;
    if (s_sector_size == 0 || s_sector_size > FF_MAX_SS || s_sector_size * s_sector_count > part->size) {
        return ESP_ERR_INVALID_SIZE;
    }
    return ESP_OK;
}

static esp_err_t image_register(void)
{
    static const ff_diskio_impl_t impl = {
        .init = image_status,
        .status = image_status,
        .read = image_read,
        .write = image_write,
        .ioctl = image_ioctl,
    };
    esp_err_t err = ff_diskio_get_drive(&s_pdrv);
    if (err != ESP_OK) {
        return err;
    }
    ff_diskio_register(s_pdrv, &impl);

    char drive[3] = {(char) ('0' + s_pdrv), ':', 0};
    esp_vfs_fat_conf_t conf = { .base_path = MOUNT_POINT, .fat_drive = drive, .max_files = 5 };
    FATFS *fs = NULL;
    err = esp_vfs_fat_register(&conf, &fs);
    if (err != ESP_OK) {
        return err;
    }
    return f_mount(fs, drive, 1) == FR_OK ? ESP_OK : ESP_FAIL;
}

const char *phpbm_storage_mount(void)
{
    const esp_partition_t *part = esp_partition_find_first(ESP_PARTITION_TYPE_DATA, ESP_PARTITION_SUBTYPE_DATA_FAT, PARTITION);
    if (part == NULL) {
        ESP_LOGE(TAG, "no '%s' partition: the firmware was built without the project sources", PARTITION);
        return NULL;
    }
    esp_err_t err = image_map(part);
    if (err == ESP_OK) {
        err = image_register();
    }
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "mounting the project sources failed: %s", esp_err_to_name(err));
        phpbm_storage_unmount();
        return NULL;
    }
    return MOUNT_POINT;
}

void phpbm_storage_unmount(void)
{
    if (s_pdrv != 0xFF) {
        char drive[3] = {(char) ('0' + s_pdrv), ':', 0};
        f_mount(NULL, drive, 0);
        esp_vfs_fat_unregister_path(MOUNT_POINT);
        ff_diskio_unregister(s_pdrv);
        s_pdrv = 0xFF;
    }
    if (s_image != NULL) {
        esp_partition_munmap(s_map);
        s_image = NULL;
    }
}
