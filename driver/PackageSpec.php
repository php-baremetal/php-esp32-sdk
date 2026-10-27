<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension;
use PhpBaremetal\Spec\SpecException;

final readonly class PackageSpec
{
    public const TYPES = ['bm-ext', 'bm-hal', 'bm-lib', 'bm-php-ext'];
    public const KEYS = ['defines', 'entry', 'idf-dependencies', 'idf-requires', 'include-dirs', 'module', 'partitions', 'platform', 'priority', 'register', 'registries', 'sdkconfig', 'sources'];
    public const PARTITION_SUBTYPES = ['nvs', 'nvs_keys', 'fat', 'spiffs', 'littlefs', 'coredump', 'efuse', 'undefined', 'phy'];
    public const RESERVED_PARTITIONS = ['factory', 'nvs', 'phy_init', 'storage'];

    /**
     * @param list<string> $sources
     * @param list<string> $includeDirs
     * @param array<string,string|int> $defines
     * @param list<string> $idfRequires
     * @param array<string,string> $idfDependencies
     * @param list<array{name:string,subtype:string,kb:int}> $partitions
     * @param array<string,array{type:string,header:string}> $registries
     * @param array<string,list<string>> $register
     */
    public function __construct(
        public string $name,
        public string $dir,
        public ?string $module,
        public int $priority,
        public array $sources,
        public array $includeDirs,
        public array $defines,
        public array $idfRequires,
        public array $idfDependencies,
        public ?string $sdkconfig,
        public array $partitions,
        public array $registries = [],
        public array $register = [],
        public ?string $phpExtension = null,
        public string $version = '',
        public ?string $entry = null,
    ) {
    }

    /** The zend_module_entry symbol of a bm-ext ('entry', default "<module>_module_entry"), null for other types. */
    public function entrySymbol(): ?string
    {
        return $this->module === null ? null : ($this->entry ?? "{$this->module}_module_entry");
    }

    public static function fromPackage(Package $package, string $projectDir, ?string $phpMinor = null, ?string $sdkName = null): self
    {
        $name = $package->name;
        $fail = static fn (string $message): SpecException => new SpecException("package {$name}: {$message}");
        $meta = $package->meta;
        $dir = rtrim(BuildInputs::packageDir($projectDir, $package->path), '/');

        if (!in_array($package->type, self::TYPES, true)) {
            throw $fail("unknown type '{$package->type}' (allowed: " . implode(', ', self::TYPES) . ')');
        }
        if ($meta !== [] && array_is_list($meta)) {
            throw $fail('extra.baremetal must be an object');
        }
        if ($package->type === 'bm-php-ext') {
            foreach (array_keys($meta) as $key) {
                if ($key !== 'php-ext') {
                    throw $fail("type bm-php-ext allows only 'php-ext' in extra.baremetal, got '{$key}'");
                }
            }
            $extension = $meta['php-ext'] ?? null;
            if (!is_string($extension) || !preg_match(PhpExtension::NAME, $extension)) {
                throw $fail("type bm-php-ext needs 'php-ext' naming a PHP extension ([a-z][a-z0-9_]*)");
            }
            return new self($name, $dir, null, 100, [], [], [], [], [], null, [], phpExtension: $extension);
        }
        foreach (array_keys($meta) as $key) {
            if (!in_array($key, self::KEYS, true)) {
                throw $fail("unknown key '{$key}' in extra.baremetal (allowed: " . implode(', ', self::KEYS) . ')');
            }
        }
        if (!is_dir($dir)) {
            throw $fail("directory {$dir} not found");
        }

        $module = $meta['module'] ?? null;
        $moduleEntry = $meta['entry'] ?? null;
        if ($package->type === 'bm-ext') {
            if ($module === null) {
                throw $fail("type bm-ext needs 'module' in extra.baremetal");
            }
            if (!is_string($module) || !preg_match(PhpExtension::MODULE, $module)) {
                throw $fail("'module' must match [a-z_][a-z0-9_]*");
            }
            if (in_array($module, PhpExtension::builtin($phpMinor), true)) {
                throw $fail("module '{$module}' is a built-in PHP extension");
            }
            // A package module is always registered from the generated table: no null (self-registering) entry.
            if (array_key_exists('entry', $meta) && (!is_string($moduleEntry) || !preg_match(PhpExtension::ENTRY, $moduleEntry))) {
                throw $fail("'entry' must be the C symbol of the zend_module_entry (omit it for {$module}_module_entry)");
            }
        } elseif ($module !== null) {
            throw $fail("'module' is only allowed for type bm-ext, not {$package->type}");
        } elseif (array_key_exists('entry', $meta)) {
            throw $fail("'entry' is only allowed for type bm-ext, not {$package->type}");
        }

        $platform = $meta['platform'] ?? null;
        if ($package->type === 'bm-hal') {
            if ($platform === null) {
                throw $fail("type bm-hal needs 'platform' in extra.baremetal");
            }
            if (!is_string($platform) || !preg_match('/^[a-z][a-z0-9]*$/D', $platform)) {
                throw $fail("'platform' must be the platform name ([a-z][a-z0-9]*)");
            }
            if ($sdkName !== null && $platform !== $sdkName) {
                throw $fail("'platform' is '{$platform}' but the project's sdk is '{$sdkName}'");
            }
            if (!preg_match('#^[^/]+/' . preg_quote($platform, '#') . '-#', $name)) {
                throw $fail("the name must start with '<vendor>/{$platform}-' (the 'platform' prefix)");
            }
        } elseif (array_key_exists('platform', $meta)) {
            throw $fail("'platform' is only allowed for type bm-hal, not {$package->type}");
        }

        $priority = $meta['priority'] ?? 100;
        if (!is_int($priority)) {
            throw $fail("'priority' must be an integer");
        }

        if (array_key_exists('sources', $meta)) {
            $sources = [];
            foreach (self::stringList($meta['sources'], 'sources', $fail) as $pattern) {
                $matches = self::glob($dir, $pattern, $fail);
                if ($matches === []) {
                    throw $fail("'sources' pattern '{$pattern}' matches no file");
                }
                $sources = [...$sources, ...$matches];
            }
            $sources = array_values(array_unique($sources));
        } else {
            $sources = is_dir("{$dir}/src") ? self::glob($dir, 'src/*.c', $fail) : [];
        }

        if (array_key_exists('include-dirs', $meta)) {
            $includeDirs = [];
            foreach (self::stringList($meta['include-dirs'], 'include-dirs', $fail) as $path) {
                $path = self::relative($path, 'include-dirs', $fail);
                if (!is_dir("{$dir}/{$path}")) {
                    throw $fail("include dir '{$path}' not found");
                }
                $includeDirs[] = "{$dir}/{$path}";
            }
            $includeDirs = array_values(array_unique($includeDirs));
        } else {
            $includeDirs = array_values(array_filter(["{$dir}/include", "{$dir}/src"], 'is_dir'));
        }

        $defines = self::map($meta['defines'] ?? [], 'defines', $fail);
        foreach ($defines as $define => $value) {
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', (string) $define)) {
                throw $fail("invalid define name '{$define}'");
            }
            if (!is_int($value) && !(is_string($value) && !preg_match('/[;\x00-\x1F]/', $value))) {
                throw $fail("define '{$define}' must be an integer or a string without ';' or control characters");
            }
        }

        $idfRequires = self::stringList($meta['idf-requires'] ?? [], 'idf-requires', $fail);
        foreach ($idfRequires as $component) {
            if (!preg_match('/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/D', $component)) {
                throw $fail("invalid ESP-IDF component name '{$component}' in 'idf-requires'");
            }
        }
        $idfRequires = array_values(array_unique($idfRequires));

        $idfDependencies = [];
        foreach (self::map($meta['idf-dependencies'] ?? [], 'idf-dependencies', $fail) as $component => $constraint) {
            if (!preg_match('#^[A-Za-z0-9_][A-Za-z0-9_.-]*(/[A-Za-z0-9_][A-Za-z0-9_.-]*)?$#D', (string) $component)) {
                throw $fail("invalid ESP-IDF component name '{$component}' in 'idf-dependencies'");
            }
            if (!is_string($constraint) || $constraint === '' || preg_match('/["\\\\\x00-\x1F]/', $constraint)) {
                throw $fail("invalid version constraint for '{$component}' in 'idf-dependencies'");
            }
            // The component manager reads a name without namespace as espressif/<name>, case-insensitively.
            $component = strtolower((string) $component);
            if (!str_contains($component, '/')) {
                $component = "espressif/{$component}";
            }
            if (isset($idfDependencies[$component])) {
                throw $fail("'idf-dependencies' names {$component} twice");
            }
            $idfDependencies[$component] = $constraint;
        }

        $sdkconfig = null;
        if (array_key_exists('sdkconfig', $meta)) {
            if (!is_string($meta['sdkconfig']) || $meta['sdkconfig'] === '') {
                throw $fail("'sdkconfig' must be a file path");
            }
            $path = self::relative($meta['sdkconfig'], 'sdkconfig', $fail);
            if (!is_file("{$dir}/{$path}")) {
                throw $fail("sdkconfig fragment '{$path}' not found");
            }
            $sdkconfig = "{$dir}/{$path}";
        }

        $partitions = [];
        $list = $meta['partitions'] ?? [];
        if (!is_array($list) || !array_is_list($list)) {
            throw $fail("'partitions' must be a list");
        }
        foreach ($list as $i => $partition) {
            if (!is_array($partition) || array_keys($partition) === [] || array_is_list($partition)) {
                throw $fail("partitions[{$i}] must be an object with name, subtype and size");
            }
            $keys = array_keys($partition);
            sort($keys);
            if ($keys !== ['name', 'size', 'subtype']) {
                throw $fail("partitions[{$i}] must have exactly the keys name, subtype and size");
            }
            ['name' => $partName, 'subtype' => $subtype, 'size' => $size] = $partition;
            if (!is_string($partName) || !preg_match('/^[a-z_][a-z0-9_]{0,15}$/D', $partName)) {
                throw $fail("partitions[{$i}]: invalid name (use [a-z_][a-z0-9_]*, at most 16 characters)");
            }
            if (in_array($partName, self::RESERVED_PARTITIONS, true)) {
                throw $fail("partition name '{$partName}' is reserved (" . implode(', ', self::RESERVED_PARTITIONS) . ')');
            }
            if (!is_string($subtype)) {
                throw $fail("partition '{$partName}': 'subtype' must be a string");
            }
            if (!in_array($subtype, self::PARTITION_SUBTYPES, true)) {
                throw $fail("partition '{$partName}': subtype '{$subtype}' is not allowed (allowed: " . implode(', ', self::PARTITION_SUBTYPES) . ')');
            }
            if (!is_string($size) || !preg_match('/^([1-9][0-9]*)K$/D', $size, $m) || (int) $m[1] % 4 !== 0) {
                throw $fail("partition '{$partName}': size must be <n>K with n a multiple of 4");
            }
            $partitions[] = ['name' => $partName, 'subtype' => $subtype, 'kb' => (int) $m[1]];
        }

        $registries = [];
        if (array_key_exists('registries', $meta)) {
            if ($package->type !== 'bm-ext') {
                throw $fail("'registries' is only allowed for type bm-ext, not {$package->type}");
            }
            foreach (self::map($meta['registries'], 'registries', $fail) as $registry => $entry) {
                if (!preg_match('/^[a-z_][a-z0-9_]*$/D', (string) $registry)) {
                    throw $fail("invalid registry name '{$registry}' (use [a-z_][a-z0-9_]*)");
                }
                if (!is_array($entry) || array_is_list($entry) || array_diff(array_keys($entry), ['type', 'header']) !== [] || count($entry) !== 2) {
                    throw $fail("registry '{$registry}' must have exactly the keys type and header");
                }
                $type = $entry['type'];
                // The generated table adds const itself; struct/union/enum need their tag.
                $words = is_string($type) ? explode(' ', $type) : [];
                if (!is_string($type) || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*( [A-Za-z_][A-Za-z0-9_]*)*$/D', $type)
                    || array_intersect($words, ['const', 'volatile']) !== []
                    || in_array(end($words), ['struct', 'union', 'enum'], true)) {
                    throw $fail("registry '{$registry}': invalid C type '" . (is_string($type) ? $type : get_debug_type($type)) . "' (a type name without const, struct/union/enum with their tag)");
                }
                if (!is_string($entry['header']) || $entry['header'] === '') {
                    throw $fail("registry '{$registry}': 'header' must be a file path");
                }
                $header = self::relative($entry['header'], 'registries', $fail);
                if (preg_match('/["\\\\<>\x00-\x1F]/', $header)) {
                    throw $fail("registry '{$registry}': invalid header path '{$header}'");
                }
                $found = false;
                foreach ($includeDirs as $includeDir) {
                    if (is_file("{$includeDir}/{$header}")) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    throw $fail("registry '{$registry}': header '{$header}' not found in the include dirs");
                }
                $registries[(string) $registry] = ['type' => $type, 'header' => $header];
            }
        }

        $register = [];
        if (array_key_exists('register', $meta)) {
            foreach (self::map($meta['register'], 'register', $fail) as $registry => $symbols) {
                if (!preg_match('/^[a-z_][a-z0-9_]*$/D', (string) $registry)) {
                    throw $fail("invalid registry name '{$registry}' in 'register' (use [a-z_][a-z0-9_]*)");
                }
                $symbols = self::stringList($symbols, "register.{$registry}", $fail);
                foreach ($symbols as $symbol) {
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $symbol)) {
                        throw $fail("invalid C symbol '{$symbol}' in 'register' for '{$registry}'");
                    }
                }
                $register[(string) $registry] = $symbols;
            }
        }

        return new self($name, $dir, $module, $priority, $sources, $includeDirs, $defines, $idfRequires, $idfDependencies, $sdkconfig, $partitions, $registries, $register, version: $package->version, entry: is_string($moduleEntry) ? $moduleEntry : null);
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $key, \Closure $fail): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw $fail("'{$key}' must be a list of strings");
        }
        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                throw $fail("'{$key}' must be a list of strings");
            }
        }
        return $value;
    }

    /** @return array<string,mixed> */
    private static function map(mixed $value, string $key, \Closure $fail): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw $fail("'{$key}' must be an object");
        }
        return $value;
    }

    private static function relative(string $path, string $key, \Closure $fail): string
    {
        $path = BuildInputs::cmakePath($path);
        // ';' splits CMake lists, '=' the -ffile-prefix-map pairs, quotes the generated CMake strings.
        if (strpbrk($path, ';="') !== false) {
            throw $fail("'{$key}' must not contain ';', '=' or '\"': '{$path}'");
        }
        if (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw $fail("'{$key}' must be relative to the package: '{$path}'");
        }
        $segments = array_values(array_filter(explode('/', $path), static fn (string $s): bool => $s !== '' && $s !== '.'));
        if (in_array('..', $segments, true)) {
            throw $fail("'{$key}' must not contain '..': '{$path}'");
        }
        if ($segments === []) {
            throw $fail("'{$key}' must name a path inside the package: '{$path}'");
        }
        return implode('/', $segments);
    }

    /** @return list<string> */
    private static function glob(string $dir, string $pattern, \Closure $fail): array
    {
        $pattern = self::relative($pattern, 'sources', $fail);
        if (preg_match('/[\[\]{}]/', $pattern)) {
            throw $fail("'sources' pattern '{$pattern}' may only use '*' and '?' wildcards");
        }
        $parent = dirname($pattern);
        $last = basename($pattern);
        if ($parent !== '.' && strpbrk($parent, '*?') !== false) {
            throw $fail("'sources' pattern '{$pattern}' may use wildcards only in its last segment");
        }
        $base = $parent === '.' ? $dir : "{$dir}/{$parent}";
        if (strpbrk($last, '*?') === false) {
            return is_file("{$base}/{$last}") ? ["{$base}/{$last}"] : [];
        }
        $regex = '/^' . strtr(preg_quote($last, '/'), ['\*' => '.*', '\?' => '.']) . '$/sD';
        $matches = [];
        foreach (is_dir($base) ? (scandir($base) ?: []) : [] as $entry) {
            if ($entry[0] === '.' && $last[0] !== '.') {
                continue;
            }
            if (preg_match($regex, $entry) && is_file("{$base}/{$entry}")) {
                $matches[] = "{$base}/{$entry}";
            }
        }
        sort($matches, SORT_STRING);
        return $matches;
    }
}
