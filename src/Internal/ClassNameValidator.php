<?php

declare(strict_types=1);

namespace TypePHP\Internal;

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

        return preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff\\\\]*$/', $trimmed) === 1;
    }
}