<?php

declare(strict_types=1);

namespace TypePHP\Extension;

/**
 * Loads explicitly registered TypePHP extensions from user configuration.
 */
final class ExtensionManager
{
    /**
     * Safely loads and merges whitelist include paths from explicitly configured extensions.
     * Extensions are structurally restricted to 'include' and 'stubs' only.
     * Exclude authority belongs strictly to the end-user's local typephp.php file.
     *
     * @param array<int, class-string<ExtensionInterface>> $configuredExtensions
     *
     * @return array<int, string>
     */
    public static function loadExtensionIncludes(array $configuredExtensions = []): array
    {
        $extensionIncludes = [];
        $uniqueExtensions = array_unique($configuredExtensions);

        foreach ($uniqueExtensions as $extensionClass) {
            if (\is_string($extensionClass) && class_exists($extensionClass) && is_a($extensionClass, ExtensionInterface::class, allow_string: true)) {
                /** @var ExtensionInterface $instance */
                $instance = new $extensionClass();
                $config = $instance->getConfig();

                // Extensions can ONLY append include paths (whitelisting)
                if (isset($config['include']) && \is_array($config['include'])) {
                    foreach ($config['include'] as $inc) {
                        if (\is_string($inc) && $inc !== '') {
                            $extensionIncludes[] = $inc;
                        }
                    }
                }
            }
        }

        return array_unique($extensionIncludes);
    }

    /**
     * Safely loads and merges stub file paths from explicitly configured extensions.
     *
     * @param array<int, class-string<ExtensionInterface>> $configuredExtensions
     *
     * @return array<int, string>
     */
    public static function loadExtensionStubs(array $configuredExtensions = []): array
    {
        $extensionStubs = [];
        $uniqueExtensions = array_unique($configuredExtensions);

        foreach ($uniqueExtensions as $extensionClass) {
            if (\is_string($extensionClass) && class_exists($extensionClass) && is_a($extensionClass, ExtensionInterface::class, allow_string: true)) {
                /** @var ExtensionInterface $instance */
                $instance = new $extensionClass();
                $config = $instance->getConfig();

                if (isset($config['stubs']) && \is_array($config['stubs'])) {
                    foreach ($config['stubs'] as $stub) {
                        if (\is_string($stub) && $stub !== '') {
                            $extensionStubs[] = $stub;
                        }
                    }
                }
            }
        }

        return array_unique($extensionStubs);
    }
}
