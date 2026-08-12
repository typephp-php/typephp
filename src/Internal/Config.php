<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use TypePHP\Contract\ContractParser;
use TypePHP\Extension\ExtensionInterface;
use TypePHP\Extension\ExtensionManager;

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

        $cwd = getcwd();
        $configFile = $cwd !== false ? $cwd . '/typephp.php' : '';
        $userConfig = [];

        if ($configFile !== '' && file_exists($configFile)) {
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

        ContractParser::reset();
    }
}
