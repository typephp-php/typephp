<?php

declare(strict_types=1);

namespace TypePHP\Internal\Docblock;

/**
 * Normalizes PHPDoc comment strings before AST parsing.
 * Converts class-specific shapes like stdClass{id: int} into intersection shapes (stdClass & object{id: int}).
 * Normalizes variadic tuple spread syntax (...Type[] and ...list<Type>) into PHPStan-compliant ...<Type> syntax.
 * Normalizes unqualified wildcard bitmasks (int-mask-of<E_*>) into quoted string literals.
 * Normalizes unparenthesized nested callable return types into right-associative parenthesized callables.
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

        if (stripos($doc, 'static') !== false && stripos($doc, 'closure') !== false) {
            $doc = preg_replace('/(?:\(\s*static\s+Closure\s*\)|static\s+Closure\b)/i', 'static-closure', $doc) ?? $doc;
        }

        if (str_contains($doc, '@self-out') && ! str_contains($doc, '@phpstan-self-out') && ! str_contains($doc, '@psalm-self-out')) {
            $doc = preg_replace('/@self-out\b/', '@phpstan-self-out', $doc) ?? $doc;
        }

        if (str_contains($doc, '@this-out') && ! str_contains($doc, '@phpstan-this-out') && ! str_contains($doc, '@psalm-this-out')) {
            $doc = preg_replace('/@this-out\b/', '@phpstan-this-out', $doc) ?? $doc;
        }

        if (stripos($doc, 'callable') !== false || stripos($doc, 'closure') !== false) {
            // 1. Auto-complete omitted return types to ': mixed'
            $doc = preg_replace('/(callable|Closure|static-closure)\s*(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))(?!\s*:)/i', '$1$2: mixed', $doc) ?? $doc;

            // 2. Auto-parenthesize unparenthesized nested return callables (Mago / Right-associative functional currying)
            if (str_contains($doc, ':')) {
                $doc = self::normalizeNestedCallables($doc);
            }
        }

        if (str_contains($doc, '::') && str_contains($doc, ':')) {
            $doc = preg_replace('/(\\\\?[a-zA-Z_\x80-\xff][\\\\a-zA-Z0-9_\x80-\xff]*::[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*(\??:)/', '"$1"$2', $doc) ?? $doc;
        }

        if (str_contains($doc, 'int-mask-of') && str_contains($doc, '*')) {
            $doc = preg_replace('/int-mask-of<\s*([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*\*)\s*>/', 'int-mask-of<"$1">', $doc) ?? $doc;
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
     * Normalizes unparenthesized nested callable return types into right-associative parenthesized callables:
     * callable(int): callable(string): bool  ==>  callable(int): (callable(string): bool)
     */
    private static function normalizeNestedCallables(string $doc): string
    {
        $len = \strlen($doc);
        $result = '';
        $i = 0;

        while ($i < $len) {
            if ($doc[$i] === ':') {
                $j = $i + 1;
                while ($j < $len && ($doc[$j] === ' ' || $doc[$j] === "\t")) {
                    $j++;
                }

                if ($j < $len && $doc[$j] === '(') {
                    $result .= $doc[$i];
                    $i++;

                    continue;
                }

                $remaining = substr($doc, $j);
                if (preg_match('/^(callable|Closure|static-closure)\s*\(/i', $remaining) === 1) {
                    $result .= ': (';

                    $k = $j;
                    $parenDepth = 0;
                    $braceDepth = 0;
                    $angleDepth = 0;
                    $bracketDepth = 0;

                    while ($k < $len) {
                        $char = $doc[$k];

                        if ($char === '(') {
                            $parenDepth++;
                        } elseif ($char === ')') {
                            if ($parenDepth === 0) {
                                break;
                            }
                            $parenDepth--;
                        } elseif ($char === '{') {
                            $braceDepth++;
                        } elseif ($char === '}') {
                            if ($braceDepth === 0) {
                                break;
                            }
                            $braceDepth--;
                        } elseif ($char === '<') {
                            $angleDepth++;
                        } elseif ($char === '>') {
                            if ($angleDepth === 0) {
                                break;
                            }
                            $angleDepth--;
                        } elseif ($char === '[') {
                            $bracketDepth++;
                        } elseif ($char === ']') {
                            if ($bracketDepth === 0) {
                                break;
                            }
                            $bracketDepth--;
                        } elseif ($char === ',' && $parenDepth === 0 && $braceDepth === 0 && $angleDepth === 0 && $bracketDepth === 0) {
                            break;
                        } elseif (($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") && $parenDepth === 0 && $braceDepth === 0 && $angleDepth === 0 && $bracketDepth === 0) {
                            $afterWs = substr($doc, $k);

                            if (preg_match('/^\s+(\$[a-zA-Z_\x80-\xff]|\*\/|\*|\r|\n)/', $afterWs) === 1) {
                                break;
                            }

                            if (preg_match('/^\s+([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\s*\(/', $afterWs, $nameMatch) === 1) {
                                $word = strtolower($nameMatch[1]);
                                if (! \in_array($word, ['callable', 'closure', 'static-closure', 'array', 'list', 'object', 'iterable'], true)) {
                                    break;
                                }
                            }
                        }

                        $k++;
                    }

                    $nestedCallableSubstring = substr($doc, $j, $k - $j);
                    $normalizedNested = self::normalizeNestedCallables($nestedCallableSubstring);

                    $result .= $normalizedNested . ')';
                    $i = $k;

                    continue;
                }
            }

            $result .= $doc[$i];
            $i++;
        }

        return $result;
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
