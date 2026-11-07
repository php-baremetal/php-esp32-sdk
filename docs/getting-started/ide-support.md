---
eyebrow: 'SDK · Overview'
lede:    'Code completion, signatures and documentation for the SDK classes and functions in PhpStorm and VS Code, with nothing to configure.'
see_also:
  - { href: './first-firmware.md', meta: 'Guide' }
  - { href: '../modules/php-extensions.md', meta: 'Modules' }
  - { href: 'https://github.com/php-baremetal/phpbm/blob/master/docs/guide/project.md', meta: 'phpbm', label: 'The project' }
prev: { label: 'Your first firmware', href: './first-firmware.md' }
next: { label: 'Supported boards', href: '../boards/supported-boards.md' }
---

# IDE support

The classes and functions of the SDK (`Baremetal\Wifi`, `gpio_write()`, `store_set()`, the display and sensor
drivers) live in the firmware, not in PHP files. Without help an IDE would mark them as undefined. Every
php-baremetal package that has a PHP API therefore ships its declarations, and the conductor puts them in your
project at every `composer install` and `composer update`.

## Nothing to configure

Open the project folder in PhpStorm or in VS Code with Intelephense. The IDE indexes `vendor/`, finds the
declarations and gives you completion, parameter hints, types and the documentation of each function and class.
You do not add a plugin, a library path or a setting.

## Where the symbols come from

After an install the project has:

<!-- @code-block language="Text" label="vendor/php-baremetal/ide-stubs/" -->
```text
vendor/php-baremetal/ide-stubs/
  README.md
  ext-display.php
  ext-events.php
  ext-wifi.php
  imu-qmi8658.php
  …
```
<!-- @endcode-block -->

There is one `<package>.php` per installed package that declares a stub, plus a `README.md`. Each file holds only
declarations with empty bodies and the documentation of the package, with the exact signatures of the extension.
The conductor rebuilds the whole folder each time, so a package you remove or update never leaves an old file
behind. A package that declares a stub which is missing is reported as a warning by `composer install`.

The folder is generated: do not edit it, it is overwritten. If a function or class is wrong in the IDE, the stub
of the package that owns it is the place to fix, and the next install brings the fix.

If the folder is missing after the first `composer install` of a project, an older conductor installed globally ran
instead of the one in the project (Composer runs the global plugin of the same name). Run
`composer global update php-baremetal/conductor`, or simply `composer install` a second time.

## Excluding `.phpbm`

The `.phpbm/` folder of a project holds the manifest and the build files that phpbm generates. It is safe to
exclude it from the IDE index (in PhpStorm, mark the folder as excluded); the declarations are in `vendor/`, not
there.

## The stubs are not part of the firmware

The stubs are for the IDE only. They are not listed in the autoloader and phpbm does not put them in the firmware:
do not `require` them from your code, because every class would be declared a second time on the board. The
declarations are never executed.
