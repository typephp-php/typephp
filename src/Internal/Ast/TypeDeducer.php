<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast;

use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockNormalizer;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;

/**
 * Deduces element, key, and indexed member types from container type strings.
 *
 * @internal
 */
final class TypeDeducer
{
    /**
     * In-memory cache for parsed container TypeNodes: [$typeString] => TypeNode
     *
     * @var array<string, TypeNode>
     */
    private static array $parsedTypeCache = [];

    /**
     * Deduces the element/value type of a collection (list<T>, T[], array<K, V>, array{...}).
     */
    public static function deduceElementType(string $typeString): ?string
    {
        $typeNode = self::parseTypeString($typeString);
        if ($typeNode === null) {
            return null;
        }

        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return (string) $typeNode->type;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower((string) $typeNode->type);
            $count = \count($typeNode->genericTypes);

            if (\in_array($base, ['list', 'non-empty-list'], true) && $count >= 1) {
                return (string) $typeNode->genericTypes[0];
            }

            if (\in_array($base, ['array', 'non-empty-array', 'iterable'], true)) {
                return $count >= 2
                    ? (string) $typeNode->genericTypes[1]
                    : (string) ($typeNode->genericTypes[0] ?? 'mixed');
            }
        }

        if ($typeNode instanceof ArrayShapeNode) {
            $valueTypes = [];
            foreach ($typeNode->items as $item) {
                $valueTypes[] = (string) $item->valueType;
            }

            $unique = array_values(array_unique($valueTypes));

            if ($unique === []) {
                return null;
            }

            return \count($unique) === 1 ? $unique[0] : '(' . implode('|', $unique) . ')';
        }

        return null;
    }

    /**
     * Deduces the key type of a collection.
     */
    public static function deduceKeyType(string $typeString): string
    {
        $typeNode = self::parseTypeString($typeString);
        if ($typeNode === null) {
            return 'array-key';
        }

        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower((string) $typeNode->type);
            $count = \count($typeNode->genericTypes);

            if (\in_array($base, ['list', 'non-empty-list'], true)) {
                return 'int';
            }

            if (\in_array($base, ['array', 'non-empty-array', 'iterable'], true) && $count >= 2) {
                return (string) $typeNode->genericTypes[0];
            }
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return 'int';
        }

        return 'array-key';
    }

    /**
     * Deduces the specific member type of an array dimension access ($arr[$dimExpr]).
     */
    public static function deduceDimType(string $containerTypeString, ?Expr $dimExpr): ?string
    {
        $typeNode = self::parseTypeString($containerTypeString);
        if ($typeNode === null) {
            return null;
        }

        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof ArrayShapeNode && $dimExpr !== null) {
            $key = null;
            if ($dimExpr instanceof String_) {
                $key = $dimExpr->value;
            } elseif ($dimExpr instanceof LNumber) {
                $key = $dimExpr->value;
            }

            if ($key !== null) {
                $autoIdx = 0;
                foreach ($typeNode->items as $item) {
                    $itemKey = match (true) {
                        $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                        $item->keyName instanceof ConstExprIntegerNode => (int) $item->keyName->value,
                        $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                        $item->keyName !== null => (string) $item->keyName,
                        default => $autoIdx,
                    };

                    if (\is_int($itemKey)) {
                        $autoIdx = max($autoIdx, $itemKey + 1);
                    }

                    if ($itemKey === $key) {
                        return (string) $item->valueType;
                    }
                }
            }

            if ($typeNode->unsealedType !== null) {
                return (string) $typeNode->unsealedType->valueType;
            }
        }

        return self::deduceElementType($containerTypeString);
    }

    /**
     * Deduces the property type of an object shape or class property access ($obj->prop).
     *
     * @param array<string, array<string, string>> $localPropertyTypes
     * @param array<string, list<string>> $localClassTemplates
     */
    public static function deducePropertyType(
        string $containerTypeString,
        string $propName,
        array $localPropertyTypes = [],
        array $localClassTemplates = [],
        string $currentFile = ''
    ): ?string {
        $typeNode = self::parseTypeString($containerTypeString);
        if ($typeNode === null) {
            return null;
        }

        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($currentFile !== '') {
            $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $currentFile);
        }

        if ($typeNode instanceof ObjectShapeNode) {
            foreach ($typeNode->items as $item) {
                $name = $item->keyName instanceof IdentifierTypeNode
                    ? $item->keyName->name
                    : (string) $item->keyName;

                if ($name === $propName) {
                    return (string) $item->valueType;
                }
            }
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            $className = $typeNode->name;
            $shortName = ($pos = strrpos($className, '\\')) !== false ? substr($className, $pos + 1) : $className;

            if (isset($localPropertyTypes[$className][$propName])) {
                return $localPropertyTypes[$className][$propName];
            }
            if (isset($localPropertyTypes[$shortName][$propName])) {
                return $localPropertyTypes[$shortName][$propName];
            }

            if (
                class_exists($className, false) || class_exists($className)
                || interface_exists($className, false) || interface_exists($className)
                || trait_exists($className, false) || trait_exists($className)
            ) {
                $propType = DocblockParser::parseProperty($className, $propName);
                if ($propType !== null) {
                    return (string) $propType;
                }
            }
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseType = $typeNode->type;
            $className = $baseType instanceof IdentifierTypeNode ? $baseType->name : (string) $baseType;
            $shortName = ($pos = strrpos($className, '\\')) !== false ? substr($className, $pos + 1) : $className;

            $rawPropType = null;
            $templateNames = null;

            if (isset($localPropertyTypes[$className][$propName])) {
                $rawPropType = $localPropertyTypes[$className][$propName];
                $templateNames = $localClassTemplates[$className] ?? ($localClassTemplates[$shortName] ?? null);
            } elseif (isset($localPropertyTypes[$shortName][$propName])) {
                $rawPropType = $localPropertyTypes[$shortName][$propName];
                $templateNames = $localClassTemplates[$shortName] ?? ($localClassTemplates[$className] ?? null);
            }

            if ($rawPropType === null) {
                if (
                    class_exists($className, false) || class_exists($className)
                    || interface_exists($className, false) || interface_exists($className)
                    || trait_exists($className, false) || trait_exists($className)
                ) {
                    $parsedProp = DocblockParser::parseProperty($className, $propName);
                    if ($parsedProp !== null) {
                        $rawPropType = $parsedProp;
                    }
                    $templates = DocblockParser::parseClassTemplates($className);
                    if ($templates !== []) {
                        $templateNames = array_keys($templates);
                    }
                }
            }

            if ($rawPropType !== null) {
                $propTypeNode = \is_string($rawPropType) ? self::parseTypeString($rawPropType) : $rawPropType;
                if ($propTypeNode === null) {
                    return \is_string($rawPropType) ? $rawPropType : (string) $rawPropType;
                }

                if ($templateNames !== null && $templateNames !== []) {
                    $bindings = [];
                    if (\count($templateNames) === 2 && \count($typeNode->genericTypes) === 1) {
                        $firstLower = strtolower($templateNames[0]);
                        if ($firstLower === 'k' || $firstLower === 'tkey' || $firstLower === 'key') {
                            $bindings[$templateNames[0]] = new IdentifierTypeNode('array-key');
                            $bindings[$templateNames[1]] = $typeNode->genericTypes[0];
                        }
                    } else {
                        foreach ($templateNames as $idx => $tName) {
                            if (isset($typeNode->genericTypes[$idx])) {
                                $bindings[$tName] = $typeNode->genericTypes[$idx];
                            }
                        }
                    }

                    if ($bindings !== []) {
                        $propTypeNode = TemplateSubstitutor::substitute($propTypeNode, $bindings);
                    }
                }

                return (string) $propTypeNode;
            }
        }

        return null;
    }

    private static function parseTypeString(string $typeString): ?TypeNode
    {
        if (isset(self::$parsedTypeCache[$typeString])) {
            return self::$parsedTypeCache[$typeString];
        }

        try {
            $normalized = DocblockNormalizer::normalize($typeString);
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize($normalized));

            return self::$parsedTypeCache[$typeString] = $typeParser->parse($tokens);
        } catch (\Throwable) {
            return null;
        }
    }
}
