<?php

declare(strict_types=1);

namespace TypePHP\Internal\Docblock;

/**
 * Normalizes PHPDoc comment strings before AST parsing.
 * Converts class-specific shapes like stdClass{id: int} into intersection shapes (stdClass & object{id: int}).
 * Normalizes variadic tuple spread syntax (...Type[] and ...list<Type>) into PHPStan-compliant ...<Type> syntax.
 *
 * @internal
 */
final class DocblockNormalizer
{
    /**
     * Built-in PHPStan shape keywords that should remain un-wrapped.
     */
    private const BUILTIN_SHAPE_KEYWORDS = [
        'array',
        'list',
        'non-empty-array',
        'non-empty-list',
        'object',
    ];

    /**
     * Normalizes custom class shapes inside docblock text into PHPStan-compatible intersection shapes.
     * Uses fast string guards to bypass regex execution when special patterns are absent.
     */
    public static function normalize(string $doc): string
    {
        if (! str_contains($doc, '@')) {
            return $doc;
        }

        if (str_contains($doc, '-type') && str_contains($doc, '=')) {
            $doc = preg_replace('/(@(?:phpstan|psalm)-type\s+[a-zA-Z0-9_\x80-\xff]+)\s*=\s*/', '$1 ', $doc) ?? $doc;
        }

        if (str_contains($doc, '@self-out') && ! str_contains($doc, '@phpstan-self-out') && ! str_contains($doc, '@psalm-self-out')) {
            $doc = preg_replace('/@self-out\b/', '@phpstan-self-out', $doc) ?? $doc;
        }

        if (str_contains($doc, '@this-out') && ! str_contains($doc, '@phpstan-this-out') && ! str_contains($doc, '@psalm-this-out')) {
            $doc = preg_replace('/@this-out\b/', '@phpstan-this-out', $doc) ?? $doc;
        }

        if (str_contains($doc, 'callable') || str_contains($doc, 'Closure')) {
            $doc = preg_replace('/(callable|Closure)\s*\(([^)]*)\)(?!\s*:)/', '$1($2): mixed', $doc) ?? $doc;
        }

        if (str_contains($doc, '::') && str_contains($doc, ':')) {
            $doc = preg_replace('/(\\\\?[a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff]*::[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*(\??:)/', '"$1"$2', $doc) ?? $doc;
        }

        if (str_contains($doc, '...')) {
            $doc = preg_replace('/(\.\.\.\s*)([a-zA-Z0-9_\x80-\xff\\\\]+)\[\]/', '$1<$2>', $doc) ?? $doc;
            $doc = preg_replace('/(\.\.\.\s*)(?:list|array)<([^>]+)>/', '$1<$2>', $doc) ?? $doc;
        }

        if (! str_contains($doc, '{')) {
            return $doc;
        }

        return self::normalizeShapes($doc);
    }

    /**
     * Normalizes custom class shapes supporting arbitrary nested balanced braces via PCRE recursion.
     */
    private static function normalizeShapes(string $doc): string
    {
        $pattern = '/(\\\\?[a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff]*)\s*\{((?:[^{}]+|\{(?2)\})*)\}/s';

        return preg_replace_callback(
            $pattern,
            function (array $matches): string {
                $className = $matches[1];
                $shapeBody = self::normalizeShapes($matches[2]);

                $lower = strtolower(ltrim($className, '\\'));
                if (\in_array($lower, self::BUILTIN_SHAPE_KEYWORDS, strict: true)) {
                    return $className . '{' . $shapeBody . '}';
                }

                return '(' . $className . '&object{' . $shapeBody . '})';
            },
            $doc
        ) ?? $doc;
    }
}
