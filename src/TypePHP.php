<?php

declare(strict_types=1);

namespace TypePHP;

use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;
use TypePHP\Internal\Generics\TemplateManager;

final class TypePHP
{
    /**
     * Boots TypePHP and registers the custom StreamWrapper protocol.
     */
    public static function boot(): void
    {
        StreamWrapper::register(Config::get());
    }

    /**
     * Returns the bound generic type for a template parameter on an object instance (Reified Generics).
     * If no template name is specified on a single-template class, returns the bound type automatically.
     */
    public static function getGenericType(object $instance, ?string $templateName = null): ?string
    {
        $types = self::getGenericTypes($instance);
        if (\count($types) === 0) {
            return null;
        }

        if ($templateName !== null && isset($types[$templateName])) {
            return $types[$templateName];
        }

        if (\count($types) === 1) {
            return reset($types);
        }

        return $types['T'] ?? $types['TValue'] ?? $types['TElement'] ?? $types['V'] ?? null;
    }

    /**
     * Returns all bound generic template parameters for an object instance as a key-value array.
     *
     * @return array<string, string>
     */
    public static function getGenericTypes(object $instance): array
    {
        $boundNodes = TemplateManager::getBoundTemplatesForInstance($instance);
        $types = [];

        foreach ($boundNodes as $name => $node) {
            $types[$name] = (string) $node;
        }

        return $types;
    }

    /**
     * Returns the declared variance ('covariant', 'contravariant', or 'invariant') for a template parameter on an object instance.
     */
    public static function getGenericVariance(object $instance, ?string $templateName = null): string
    {
        $variances = self::getGenericVariances($instance);
        if (\count($variances) === 0) {
            return 'invariant';
        }

        if ($templateName !== null && isset($variances[$templateName])) {
            return $variances[$templateName];
        }

        if (\count($variances) === 1) {
            return reset($variances);
        }

        return $variances['T'] ?? $variances['TValue'] ?? $variances['TElement'] ?? $variances['V'] ?? 'invariant';
    }

    /**
     * Returns all declared template variances ('covariant', 'contravariant', or 'invariant') for an object instance.
     *
     * @return array<string, string>
     */
    public static function getGenericVariances(object $instance): array
    {
        return TemplateManager::getTemplateVariances($instance);
    }

    /**
     * Returns the current resolved global configuration settings.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return Config::get();
    }

    /**
     * Dynamically overrides configuration settings at runtime.
     * Useful for test environments and custom setup scripts.
     *
     * @param array<string, mixed> $config
     */
    public static function setConfig(array $config): void
    {
        Config::set($config);
    }

    /**
     * Resets the configuration cache back to typephp.php defaults.
     * Useful for test isolation between test runs.
     */
    public static function resetConfig(): void
    {
        Config::reset();
    }
}
