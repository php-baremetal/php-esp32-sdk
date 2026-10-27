<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32;

use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension;
use PhpBaremetal\Spec\SpecException;

final readonly class Packages
{
    public const COMPONENT = 'phpbm_packages';
    public const STAMPS = 'stamps';
    public const HEADERS_STAMP = 'headers';

    /**
     * @param list<PackageSpec>                  $specs                    sorted by name
     * @param array<string,array<string,string>> $phpExtensionDependencies php extension => registry component => constraint;
     *                                                                     declared in this component's manifest, the php component requires them
     * @param array<string,string>               $optionalExtensions       PHP module name => optional PHP extension the sdk provides for the project's minor
     * @param list<string>                       $extensionEntries         the zend_module_entry symbols of the active optional extensions
     * @param array<string,string>|null          $builtinEntries           built-in module => its real zend_module_entry symbol (default: every minor's)
     */
    public function __construct(
        public array $specs,
        public array $phpExtensionDependencies = [],
        array $optionalExtensions = [],
        array $extensionEntries = [],
        ?array $builtinEntries = null,
    ) {
        $builtinEntries ??= PhpExtension::builtinEntries();
        $modules = [];
        $entries = [];
        $partitions = [];
        $dependencies = [];
        $registries = [];
        foreach ($specs as $spec) {
            foreach (array_keys($spec->registries) as $registry) {
                if (isset($registries[$registry])) {
                    throw new SpecException("packages {$registries[$registry]} and {$spec->name} both declare the registry '{$registry}'");
                }
                $registries[$registry] = $spec->name;
            }
        }
        // registries.c defines <name>[] and <name>_count for each registry.
        $generated = [];
        foreach ($registries as $registry => $owner) {
            $generated["{$registry}_count"] = $registry;
        }
        foreach ($registries as $registry => $owner) {
            if (isset($generated[$registry])) {
                throw new SpecException("package {$owner}: registry '{$registry}' collides with '{$registry}' generated for the registry '{$generated[$registry]}'");
            }
            $generated[$registry] = $registry;
        }
        $registered = [];
        foreach ($specs as $spec) {
            foreach ($spec->register as $registry => $symbols) {
                if (!isset($registries[$registry])) {
                    throw new SpecException("package {$spec->name}: registers into '{$registry}', which no installed package declares");
                }
                foreach ($symbols as $symbol) {
                    if (isset($generated[$symbol])) {
                        throw new SpecException("package {$spec->name}: symbol '{$symbol}' collides with the table generated for the registry '{$generated[$symbol]}'");
                    }
                    if (isset($registered[$registry][$symbol])) {
                        throw new SpecException("registry '{$registry}': symbol '{$symbol}' is registered twice (by {$registered[$registry][$symbol]} and {$spec->name})");
                    }
                    $registered[$registry][$symbol] = $spec->name;
                }
            }
        }
        foreach ($specs as $spec) {
            if ($spec->module !== null) {
                // The module name is PHP's extension name (extension_loaded, phpinfo); its symbol is 'entry' or "<module>_module_entry".
                if (isset($optionalExtensions[$spec->module])) {
                    $extension = $optionalExtensions[$spec->module];
                    throw new SpecException("package {$spec->name}: module '{$spec->module}' is the name of PHP's optional extension {$extension} (php-baremetal/php-{$extension}): rename it");
                }
                if (isset($modules[$spec->module])) {
                    throw new SpecException("packages {$modules[$spec->module]} and {$spec->name} both define the PHP module '{$spec->module}'");
                }
                $modules[$spec->module] = $spec->name;
                $entry = (string) $spec->entrySymbol();
                if (in_array($entry, $extensionEntries, true)) {
                    throw new SpecException("package {$spec->name}: module '{$spec->module}' uses the symbol {$entry}, which an active PHP extension already defines: rename it or set 'entry'");
                }
                if (($builtin = array_search($entry, $builtinEntries, true)) !== false) {
                    throw new SpecException("package {$spec->name}: module '{$spec->module}' uses the symbol {$entry}, which the built-in extension {$builtin} already defines: rename it or set 'entry'");
                }
                if (isset($entries[$entry])) {
                    throw new SpecException("packages {$entries[$entry]} and {$spec->name} both use the symbol {$entry} for their PHP module: set 'entry'");
                }
                $entries[$entry] = $spec->name;
            }
            foreach ($spec->partitions as $partition) {
                $name = $partition['name'];
                if (isset($partitions[$name])) {
                    throw new SpecException($partitions[$name] === $spec->name
                        ? "package {$spec->name}: partition '{$name}' is declared twice"
                        : "packages {$partitions[$name]} and {$spec->name} both declare the partition '{$name}'");
                }
                $partitions[$name] = $spec->name;
            }
            foreach ($spec->idfDependencies as $component => $constraint) {
                if (isset($dependencies[$component]) && $dependencies[$component][1] !== $constraint) {
                    [$other, $otherConstraint] = $dependencies[$component];
                    throw new SpecException("packages {$other} and {$spec->name} require the ESP-IDF component {$component} with different constraints ('{$otherConstraint}' and '{$constraint}')");
                }
                $dependencies[$component] ??= [$spec->name, $constraint];
            }
        }
        foreach ($phpExtensionDependencies as $extension => $components) {
            foreach ($components as $component => $constraint) {
                if (isset($dependencies[$component]) && $dependencies[$component][1] !== $constraint) {
                    [$other, $otherConstraint] = $dependencies[$component];
                    $other = isset($dependencies[$component][2]) ? "the php extension {$other}" : "package {$other}";
                    throw new SpecException("{$other} and the php extension {$extension} require the ESP-IDF component {$component} with different constraints ('{$otherConstraint}' and '{$constraint}')");
                }
                $dependencies[$component] ??= [$extension, $constraint, true];
            }
        }
    }

    public static function fromPlan(BuildPlan $plan): self
    {
        $specs = [];
        foreach ($plan->manifest->packages as $package) {
            $spec = PackageSpec::fromPackage($package, $plan->projectDir, $plan->php->minor, $plan->sdk->descriptor->name);
            if ($spec->phpExtension === null) {
                $specs[] = $spec;
            }
        }
        usort($specs, static fn (PackageSpec $a, PackageSpec $b): int => strcmp($a->name, $b->name));
        $extensionDependencies = [];
        $extensionEntries = [];
        foreach ($plan->phpExtensions as $extension) {
            if ($extension->idfDependencies !== []) {
                $extensionDependencies[$extension->name] = $extension->idfDependencies;
            }
            if ($extension->entry !== null) {
                $extensionEntries[] = $extension->entry;
            }
        }
        $optional = [];
        foreach ($plan->sdk->phpExtensions[$plan->php->minor] ?? [] as $extension) {
            $optional[$extension->module] = $extension->name;
        }
        return new self($specs, $extensionDependencies, $optional, $extensionEntries, PhpExtension::builtinEntries($plan->php->minor));
    }

    /** @return list<string> the PHP module names, in registration order */
    public function modules(): array
    {
        return array_map(static fn (PackageSpec $s): string => (string) $s->module, $this->moduleSpecs());
    }

    /** @return list<string> the zend_module_entry symbols, in registration order */
    public function entries(): array
    {
        return array_map(static fn (PackageSpec $s): string => (string) $s->entrySymbol(), $this->moduleSpecs());
    }

    /** @return list<PackageSpec> */
    private function moduleSpecs(): array
    {
        $specs = array_values(array_filter($this->specs, static fn (PackageSpec $s): bool => $s->module !== null));
        usort($specs, static fn (PackageSpec $a, PackageSpec $b): int => [$a->priority, $a->module] <=> [$b->priority, $b->module]);
        return $specs;
    }

    /** @return list<array{name:string,subtype:string,kb:int}> */
    public function partitions(): array
    {
        $partitions = [];
        foreach ($this->specs as $spec) {
            $partitions = [...$partitions, ...$spec->partitions];
        }
        return $partitions;
    }

    /** @return list<string> */
    public function sdkconfigFragments(): array
    {
        return array_values($this->sdkconfigFragmentsByPackage());
    }

    /** @return array<string,string> package name => fragment path */
    public function sdkconfigFragmentsByPackage(): array
    {
        $fragments = [];
        foreach ($this->specs as $spec) {
            if ($spec->sdkconfig !== null) {
                $fragments[$spec->name] = BuildInputs::cmakePath($spec->sdkconfig);
            }
        }
        return $fragments;
    }

    /** @return array<string,string> */
    public function prefixMaps(): array
    {
        $maps = [];
        foreach ($this->specs as $spec) {
            $maps[BuildInputs::cmakePath($spec->dir)] = "package/{$spec->name}";
        }
        return $maps;
    }

    /** @return list<string> */
    public function idfRequires(): array
    {
        $requires = ['php'];
        foreach ($this->specs as $spec) {
            $requires = [...$requires, ...$spec->idfRequires];
        }
        $requires = array_values(array_unique($requires));
        sort($requires, SORT_STRING);
        return $requires;
    }

    /** @return array<string,string> */
    public function idfDependencies(): array
    {
        $dependencies = [];
        foreach ($this->specs as $spec) {
            $dependencies += $spec->idfDependencies;
        }
        foreach ($this->phpExtensionDependencies as $components) {
            $dependencies += $components;
        }
        ksort($dependencies, SORT_STRING);
        return $dependencies;
    }

    public function writeComponent(string $dir): void
    {
        BuildInputs::ensureDir($dir);
        $this->writeStamps("{$dir}/" . self::STAMPS);
        BuildInputs::put("{$dir}/CMakeLists.txt", $this->cmakeLists());
        BuildInputs::put("{$dir}/modules.c", $this->modulesSource());
        BuildInputs::put("{$dir}/registries.c", $this->registriesSource());
        $yaml = $this->componentManifest();
        if ($yaml === null) {
            if (is_file("{$dir}/idf_component.yml") && !@unlink("{$dir}/idf_component.yml")) {
                throw new SpecException("cannot delete {$dir}/idf_component.yml");
            }
        } else {
            BuildInputs::put("{$dir}/idf_component.yml", $yaml);
        }
    }

    /**
     * One stamp per package with sources, plus one over every package's include dirs. Package copies keep
     * their files' mtimes (composer reinstall, archives), so ninja alone can keep objects of an older copy:
     * the sources depend on these stamps, which are rewritten (new mtime) only when the content changes.
     */
    public function writeStamps(string $dir): void
    {
        BuildInputs::ensureDir($dir);
        $stamps = [self::HEADERS_STAMP . '.stamp' => $this->headersHash() . "\n"];
        foreach ($this->specs as $spec) {
            if ($spec->sources !== []) {
                $stamps[self::stampName($spec) . '.stamp'] = self::packageHash($spec) . "\n";
            }
        }
        foreach ($stamps as $file => $content) {
            BuildInputs::put("{$dir}/{$file}", $content);
        }
        foreach (glob("{$dir}/*.stamp") ?: [] as $stale) {
            if (!isset($stamps[basename($stale)]) && !@unlink($stale)) {
                throw new SpecException("cannot delete {$stale}");
            }
        }
    }

    public static function stampName(PackageSpec $spec): string
    {
        return str_replace('/', '__', $spec->name);
    }

    public static function packageHash(PackageSpec $spec): string
    {
        $files = $spec->sources;
        foreach ([...$spec->includeDirs, ...array_map('dirname', $spec->sources)] as $tree) {
            $files = [...$files, ...self::treeFiles($tree)];
        }
        $hash = hash_init('sha256');
        hash_update($hash, "{$spec->name}\0{$spec->version}\0");
        self::hashFiles($hash, $spec->dir, $files);
        return hash_final($hash);
    }

    public function headersHash(): string
    {
        $hash = hash_init('sha256');
        foreach ($this->specs as $spec) {
            $files = [];
            foreach ($spec->includeDirs as $tree) {
                $files = [...$files, ...self::treeFiles($tree)];
            }
            hash_update($hash, "{$spec->name}\0");
            self::hashFiles($hash, $spec->dir, $files);
        }
        return hash_final($hash);
    }

    /** @return list<string> */
    public static function treeFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $entries = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            static fn (\SplFileInfo $entry): bool => !str_starts_with($entry->getFilename(), '.'),
        ));
        foreach ($entries as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        return $files;
    }

    /** @param list<string> $files */
    public static function hashFiles(\HashContext $hash, string $root, array $files): void
    {
        $prefix = rtrim(BuildInputs::cmakePath($root), '/') . '/';
        $entries = [];
        foreach ($files as $file) {
            $path = BuildInputs::cmakePath($file);
            $entries[str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path] = $file;
        }
        ksort($entries, SORT_STRING);
        foreach ($entries as $relative => $file) {
            $content = @file_get_contents($file);
            hash_update($hash, $content === false
                ? "{$relative}\0missing\0"
                : "{$relative}\0" . strlen($content) . "\0" . hash('sha256', $content) . "\0");
        }
    }

    public function cmakeLists(): string
    {
        $q = static fn (string $path): string => '"' . BuildInputs::cmakeValue(BuildInputs::cmakePath($path)) . '"';
        $qv = static fn (string $value): string => '"' . BuildInputs::cmakeValue($value) . '"';
        $sources = [];
        $includes = [];
        $properties = '';
        $stamp = static fn (string $name): string => '${CMAKE_CURRENT_LIST_DIR}/' . self::STAMPS . "/{$name}.stamp";
        $headers = $stamp(self::HEADERS_STAMP);
        foreach ($this->specs as $spec) {
            $sources = [...$sources, ...$spec->sources];
            $includes = [...$includes, ...$spec->includeDirs];
            if ($spec->sources === []) {
                continue;
            }
            $properties .= "set_source_files_properties(\n";
            foreach ($spec->sources as $source) {
                $properties .= "    {$q($source)}\n";
            }
            $properties .= '    PROPERTIES';
            if ($spec->defines !== []) {
                $definitions = [];
                foreach ($spec->defines as $name => $value) {
                    $definitions[] = "{$name}={$value}";
                }
                $properties .= " COMPILE_DEFINITIONS {$qv(implode(';', $definitions))}\n       ";
            }
            $properties .= " OBJECT_DEPENDS \"{$stamp(self::stampName($spec))};{$headers}\")\n";
        }
        $cmake = "idf_component_register(\n    SRCS\n";
        foreach ([...$sources, 'modules.c', 'registries.c'] as $source) {
            $cmake .= "        {$q($source)}\n";
        }
        if ($includes !== []) {
            $cmake .= "    INCLUDE_DIRS\n";
            foreach (array_unique($includes) as $include) {
                $cmake .= "        {$q($include)}\n";
            }
        }
        $cmake .= '    REQUIRES ' . implode(' ', $this->idfRequires()) . "\n    WHOLE_ARCHIVE)\n";
        $properties .= "set_source_files_properties(\"modules.c\" \"registries.c\" PROPERTIES OBJECT_DEPENDS \"{$headers}\")\n";
        return $cmake . $properties;
    }

    public function modulesSource(): string
    {
        $modules = $this->entries();
        $source = "/* Generated by phpbm from the project's packages. */\n#include <stddef.h>\n#include \"zend_modules.h\"\n\n";
        foreach ($modules as $module) {
            $source .= "extern zend_module_entry {$module};\n";
        }
        if ($modules !== []) {
            $source .= "\n";
        }
        $source .= "zend_module_entry *const phpbm_package_modules[] = {\n";
        foreach ($modules as $module) {
            $source .= "    &{$module},\n";
        }
        if ($modules === []) {
            $source .= "    NULL,\n";
        }
        return $source . "};\nconst int phpbm_package_module_count = " . count($modules) . ";\n";
    }

    public function registriesSource(): string
    {
        $declared = [];
        foreach ($this->specs as $spec) {
            foreach ($spec->registries as $registry => $entry) {
                $declared[$registry] = $entry;
            }
        }
        ksort($declared, SORT_STRING);
        $entries = [];
        $specs = $this->specs;
        usort($specs, static fn (PackageSpec $a, PackageSpec $b): int => strcmp($a->name, $b->name));
        foreach ($specs as $spec) {
            foreach ($spec->register as $registry => $symbols) {
                $symbols = array_values(array_unique($symbols));
                sort($symbols, SORT_STRING);
                $entries[$registry] = [...($entries[$registry] ?? []), ...$symbols];
            }
        }
        $source = "/* Generated by phpbm from the project's packages. */\n#include <stddef.h>\n";
        foreach ($declared as $registry => ['type' => $type, 'header' => $header]) {
            $symbols = $entries[$registry] ?? [];
            $source .= "\n#include \"{$header}\"\n";
            foreach ($symbols as $symbol) {
                $source .= "extern const {$type} {$symbol};\n";
            }
            $source .= "const {$type} *const {$registry}[] = {\n";
            foreach ($symbols as $symbol) {
                $source .= "    &{$symbol},\n";
            }
            $source .= "    NULL,\n};\nconst size_t {$registry}_count = " . count($symbols) . ";\n";
        }
        return $source;
    }

    public function componentManifest(): ?string
    {
        $dependencies = $this->idfDependencies();
        if ($dependencies === []) {
            return null;
        }
        $yaml = "dependencies:\n";
        foreach ($dependencies as $component => $constraint) {
            $yaml .= "  {$component}: \"{$constraint}\"\n";
        }
        return $yaml;
    }
}
