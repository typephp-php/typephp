<?php

declare(strict_types=1);

namespace TypePHP\Internal;

/**
 * @internal
 *
 * Normalizes PHPDoc comment strings before AST parsing.
 * Converts class-specific shapes like stdClass{id: int} into intersection shapes (stdClass & object{id: int}).
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
     */
    public static function normalize(string $doc): string
    {
        $doc = preg_replace('/(@(?:phpstan|psalm)-type\s+[a-zA-Z0-9_\x80-\xff]+)\s*=\s*/', '$1 ', $doc) ?? $doc;
        $doc = preg_replace('/(callable|Closure)\s*\(([^)]*)\)(?!\s*:)/', '$1($2): mixed', $doc) ?? $doc;
        $doc = preg_replace('/(\\\\?[a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff]*::[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*(\??:)/', '"$1"$2', $doc) ?? $doc;

        if (! str_contains($doc, '{')) {
            return $doc;
        }

        return preg_replace_callback(
            '/(\\\\?[a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff]*)\s*\{([^}]+)\}/s',
            function (array $matches): string {
                $className = $matches[1];
                $shapeBody = $matches[2];

                $lower = strtolower(ltrim($className, '\\'));
                if (\in_array($lower, self::BUILTIN_SHAPE_KEYWORDS, strict: true)) {
                    return $matches[0];
                }

                return '(' . $className . '&object{' . $shapeBody . '})';
            },
            $doc
        ) ?? $doc;
    }
}
