#pragma once
#include <stdio.h>
#define ESP_LOGI(tag, ...) do { (void) (tag); } while (0)
#define ESP_LOGW(tag, ...) do { (void) (tag); } while (0)
#define ESP_LOGE(tag, ...) do { fprintf(stderr, __VA_ARGS__); fputc('\n', stderr); } while (0)
