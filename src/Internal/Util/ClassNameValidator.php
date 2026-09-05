<?php

declare(strict_types=1);

namespace TypePHP\Internal\Util;

/**
 * @internal
 */
final class ClassNameValidator
{
    /**
     * Validates whether a given value is a syntactically valid PHP class, interface, trait, or enum identifier,
     * or a valid anonymous class name registered in memory.
     * Handles fully-qualified names with leading backslashes.
     * Returns false for non-strings, empty strings, complex PHPDoc strings like "Producer<Dog>", "array{id: int}", or unions.
     */
    public static function isValid(mixed $name): bool
    {
        if (! \is_string($name) || $name === '') {
            return false;
        }

        if (str_contains($name, '@anonymous')) {
            return class_exists($name, false);
        }

        $trimmed = ltrim($name, '\\');
        if ($trimmed === '') {
            return false;
        }

        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*(?:\\\\[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)*$/', $trimmed) === 1;
    }

    /**
     * Validates a class-string value:
     * - Unqualified names (e.g. 'Hello', 'stdClass') MUST physically exist in runtime.
     * - Qualified names (e.g. 'App\Models\User') pass if they exist or match valid qualified class syntax.
     */
    public static function isValidClassString(mixed $name): bool
    {
        if (! \is_string($name) || ! self::isValid($name)) {
            return false;
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return true;
        }

        $trimmed = ltrim($name, '\\');

        return str_contains($trimmed, '\\');
    }
}
