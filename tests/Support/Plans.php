<?php

declare(strict_types=1);

namespace PhpBaremetal\Esp32\Tests\Support;

use PhpBaremetal\Esp32\Files;
use PhpBaremetal\Esp32\Tests\SdkDescriptorsTest;
use PhpBaremetal\Spec\Manifest\Manifest;
use PhpBaremetal\Spec\Manifest\Package;
use PhpBaremetal\Spec\Manifest\Project;
use PhpBaremetal\Spec\Sdk\BuildPlan;
use PhpBaremetal\Spec\Sdk\Descriptor\Board;

final class Plans
{
    /** @var list<string> */
    private static array $created = [];

    public static function tempDir(): string
    {
        $dir = sys_get_temp_dir() . '/php-esp32-sdk-' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        if (self::$created === []) {
            register_shutdown_function([self::class, 'cleanup']);
        }
        return self::$created[] = (string) realpath($dir);
    }

    public static function cleanup(): void
    {
        $temp = (string) realpath(sys_get_temp_dir());
        foreach (self::$created as $dir) {
            if (str_starts_with(basename($dir), 'php-esp32-sdk-') && realpath(dirname($dir)) === $temp) {
                Files::deleteTree($dir);
            }
        }
        self::$created = [];
    }

    /** @param array{board?: string|Board, runtime?: string, storage?: string, env?: array<string,string>, sources?: array<string,string>, clean?: bool, options?: array<string,string|int|bool>, packages?: list<Package>, sdk?: \PhpBaremetal\Spec\Sdk\Sdk, php?: string, phpExtensions?: list<\PhpBaremetal\Spec\Sdk\Descriptor\PhpExtension>} $with */
    public static function plan(array $with = []): BuildPlan
    {
        $sdk = $with['sdk'] ?? SdkDescriptorsTest::sdk();
        $board = $with['board'] ?? 'waveshare-esp32-s3-eth';
        $board = $board instanceof Board ? $board : $sdk->board($board);
        $project = Project::fromComposer('acme/blink', [
            'sdk' => 'esp32@^0.1',
            'board' => $board->id,
            'runtime' => $with['runtime'] ?? 'init-loop',
            'storage' => $with['storage'] ?? 'embedded',
            ...(isset($with['envFile']) ? ['env' => $with['envFile']] : []),
        ], '8.4.26');
        $dir = self::tempDir();
        $runtime = $sdk->runtime($project->runtime);
        $storage = $sdk->storage($project->storage);
        $options = [];
        $declared = $sdk->descriptor->options + $runtime->options + $storage->options;
        foreach ($with['phpExtensions'] ?? [] as $extension) {
            $declared += $extension->options;
        }
        foreach ($declared as $name => $option) {
            $options[$name] = $option->default;
        }
        $options = [...$options, ...($with['options'] ?? [])];
        ksort($options);

        return new BuildPlan(
            $dir . '/project',
            new Manifest($project, $with['packages'] ?? []),
            $sdk,
            $board,
            $sdk->chip($board->chip),
            $runtime,
            $storage,
            $sdk->descriptor->release('8.4'),
            $with['php'] ?? '/cache/trees/php-8.4.26-abc',
            $with['sources'] ?? [],
            $with['env'] ?? [],
            $dir . '/project/.phpbm/build',
            $with['clean'] ?? false,
            $options,
            $with['phpExtensions'] ?? [],
        );
    }
}
