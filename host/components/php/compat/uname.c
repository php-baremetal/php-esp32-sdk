#include <string.h>
#include "esp_idf_version.h"
#include "sdkconfig.h"
#include <sys/utsname.h>

int uname(struct utsname *name)
{
    memset(name, 0, sizeof(*name));
    strcpy(name->sysname, "ESP-IDF");
    strcpy(name->nodename, "phpbm");
    strncpy(name->release, esp_get_idf_version(), sizeof(name->release) - 1);
    strcpy(name->version, "phpbm");
    strncpy(name->machine, CONFIG_IDF_TARGET, sizeof(name->machine) - 1);
    return 0;
}
