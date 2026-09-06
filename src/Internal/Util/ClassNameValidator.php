<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

/**
 * @internal
 */
final class ClassNameValidator
{
    /**
     * @var array<string, bool>
     */
    private static array $validSyntaxCache = [];

    /**
     * @var array<string, bool>
     */
    private static array $validClassStringCache = [];

    public static function reset(): void
    {
        self::$validSyntaxCache = [];
        self::$validClassStringCache = [];
    }

    /**
     * Validates whether a given value is a syntactically valid PHP class, interface, trait, or enum identifier,
     * or a valid anonymous class name registered in memory.
     */
    public static function isValid(mixed $name): bool
    {
        if (! \is_string($name) || $name === '') {
            return false;
        }

        if (isset(self::$validSyntaxCache[$name])) {
            return self::$validSyntaxCache[$name];
        }

        if (str_contains($name, '@anonymous')) {
            return self::$validSyntaxCache[$name] = class_exists($name, false);
        }

        $trimmed = ltrim($name, '\\');
        if ($trimmed === '') {
            return self::$validSyntaxCache[$name] = false;
        }

        $valid = preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $trimmed) === 1;

        return self::$validSyntaxCache[$name] = $valid;
    }

    /**
     * Validates a class-string value:
     * - Unqualified names (e.g. 'Hello', 'stdClass') MUST physically exist in runtime.
     * - Qualified names (e.g. 'App\Models\User') pass if they exist or match valid qualified class syntax.
     */
    public static function isValidClassString(mixed $name): bool
    {
        if (! \is_string($name) || $name === '') {
            return false;
        }

        if (isset(self::$validClassStringCache[$name])) {
            return self::$validClassStringCache[$name];
        }

        if (! self::isValid($name)) {
            return self::$validClassStringCache[$name] = false;
        }

        if (class_exists($name, false) || interface_exists($name, false) || trait_exists($name, false) || enum_exists($name, false)) {
            return self::$validClassStringCache[$name] = true;
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return self::$validClassStringCache[$name] = true;
        }

        $trimmed = ltrim($name, '\\');

        return self::$validClassStringCache[$name] = str_contains($trimmed, '\\');
    }
}