<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use TypePHP\Contract\ContractParser;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Extension\ExtensionInterface;
use TypePHP\Extension\ExtensionManager;
use TypePHP\Resolver\TemplateManager;

/**
 * Global configuration manager for loading and dynamically overriding settings.
 *
 * @internal
 */
final class Config
{
    /**
     * @var array<string, mixed>|null
     */
    private static ?array $cachedConfig = null;

    /**
     * Cached absolute project root path.
     */
    private static ?string $projectRoot = null;

    /**
     * Locates the project root directory by searching upwards for vendor/autoload.php or composer.json.
     * Caches the result in memory so the search happens exactly once.
     */
    public static function getProjectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        // Search upwards from this file (vendor/typephp/typephp/src/Internal -> project root)
        $dir = __DIR__;
        for ($i = 0; $i < 10; $i++) {
            if (file_exists($dir . '/vendor/autoload.php')) {
                return self::$projectRoot = rtrim(str_replace('\\', '/', $dir), '/');
            }

            $parent = \dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        // Fallback: Search upwards from getcwd() (for monorepos or test runners)
        $cwd = getcwd();
        if ($cwd !== false) {
            $dir = $cwd;
            for ($i = 0; $i < 10; $i++) {
                if (file_exists($dir . '/vendor/autoload.php') || file_exists($dir . '/composer.json') || file_exists($dir . '/typephp.php')) {
                    return self::$projectRoot = rtrim(str_replace('\\', '/', $dir), '/');
                }

                $parent = \dirname($dir);
                if ($parent === $dir) {
                    break;
                }
                $dir = $parent;
            }
        }

        return self::$projectRoot = rtrim(str_replace('\\', '/', $cwd !== false ? $cwd : '.'), '/');
    }

    /**
     * Loads and caches global configuration from 'typephp.php', explicitly registered extensions, and base defaults.
     *
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        if (self::$cachedConfig !== null) {
            return self::$cachedConfig;
        }

        $defaultConfig = [
            'enabled' => true,
            'params' => true,
            'returns' => true,
            'magic_properties' => true,
            'magic_methods' => true,
            'respect_ignore_tags' => true,
            'cache' => true,
            'cache_dir' => null,
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
            'include' => ['src/**', 'app/**', 'internals/**', 'tests/**'],
            'exclude' => ['vendor/**', 'storage/**', 'var/**', 'cache/**'],
            'extensions' => [],
        ];

        $projectRoot = self::getProjectRoot();
        $configFile = $projectRoot . '/typephp.php';
        $userConfig = [];

        if (file_exists($configFile)) {
            $loadedConfig = require $configFile;
            if (\is_array($loadedConfig)) {
                /** @var array<string, mixed> $userConfig */
                $userConfig = $loadedConfig;
            }
        }

        /** @var array<int, class-string<ExtensionInterface>> $configuredExtensions */
        $configuredExtensions = \is_array($userConfig['extensions'] ?? null) ? $userConfig['extensions'] : [];

        $extensionIncludes = ExtensionManager::loadExtensionIncludes($configuredExtensions);
        $defaultConfig['include'] = array_unique(array_merge($defaultConfig['include'], $extensionIncludes));
        /** @var array<string, mixed> $mergedConfig */
        $mergedConfig = array_replace_recursive($defaultConfig, $userConfig);

        return self::$cachedConfig = $mergedConfig;
    }

    /**
     * Overrides the current configuration at runtime.
     *
     * @param array<string, mixed> $config
     */
    public static function set(array $config): void
    {
        /** @var array<string, mixed> $mergedConfig */
        $mergedConfig = array_replace_recursive(self::get(), $config);

        self::$cachedConfig = $mergedConfig;

        ContractParser::reset();
    }

    /**
     * Resets the configuration cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$cachedConfig = null;
        self::$projectRoot = null;

        ContractParser::reset();
        TemplateManager::reset();
        HierarchyResolver::reset();
    }
}
