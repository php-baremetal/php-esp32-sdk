#include <stdio.h>
#include <unistd.h>

#include "esp_err.h"
#include "esp_log.h"
#include "esp_system.h"
#include "phpbm.h"

#define SOURCE_DIR PHPBM_MICROSD_MOUNT "/app" /* managed by phpbm sync; the rest of the card is the scripts' */

static const char *TAG = "phpbm";

const char *phpbm_storage_mount(void)
{
    esp_err_t err = phpbm_microsd_mount();
    if (err != ESP_OK) {
        ESP_LOGE(TAG, "microSD: %s", esp_err_to_name(err));
        printf("--- stopped: no microSD card ---\n");
        return NULL;
    }
    if (phpbm_sync(PHPBM_MICROSD_MOUNT)) {
        printf("phpbm: sync session over, restarting\n");
        fflush(stdout);
        phpbm_storage_unmount();
        esp_restart();
    }
    if (access(SOURCE_DIR "/" PHPBM_APP_ENTRY, R_OK) != 0) {
        printf("--- stopped: no %s on the microSD card (run phpbm sync) ---\n", PHPBM_APP_ENTRY);
        phpbm_storage_unmount();
        return NULL;
    }
    return SOURCE_DIR;
}

void phpbm_storage_unmount(void)
{
    phpbm_microsd_unmount();
}
