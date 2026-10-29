<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

/**
 * Statically evaluates whether an AST array expression (literal array, list, or shape)
 * deterministically satisfies an expected array, list, or shape TypeNode contract.
 *
 * @internal
 */
final class ArrayLiteralEvaluator
{
    /**
     * O(1) hash map of identifier types satisfied by an empty array ([]).
     *
     * @var array<string, true>
     */
    private const EMPTY_ARRAY_ALLOWED_IDENTIFIERS = [
        'array' => true,
        'list' => true,
        'iterable' => true,
        'mixed' => true,
    ];

    /**
     * O(1) hash map of generic container base names satisfied by an empty array ([]).
     *
     * @var array<string, true>
     */
    private const EMPTY_ARRAY_ALLOWED_GENERICS = [
        'array' => true,
        'list' => true,
        'iterable' => true,
    ];

    /**
     * Determines whether an AST array expression satisfies a target type contract.
     *
     * Flow of logic:
     * 1. Empty arrays ([]) are checked against types that permit empty collections.
     * 2. Bare array/iterable identifiers (array, iterable, list) are evaluated.
     * 3. Sequential lists (list<T>, non-empty-list<T>, T[]) verify keys are 0..n-1 and all values satisfy T.
     * 4. Typed key-value maps (array<K, V>) verify all keys satisfy K and all values satisfy V.
     * 5. Array shapes (array{id: int, ...}) verify required keys, unexpected keys, and field types.
     */
    public static function satisfies(Array_ $arrayExpr, TypeNode $typeNode, string $currentFile = ''): bool
    {
        $count = \count($arrayExpr->items);

        if ($count === 0) {
            return self::satisfiesEmptyArray($typeNode);
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return self::satisfiesIdentifierArray($arrayExpr, $typeNode);
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return self::satisfiesList($arrayExpr, $typeNode->type, false, $currentFile);
        }

        if ($typeNode instanceof GenericTypeNode) {
            return self::satisfiesGenericArray($arrayExpr, $typeNode, $currentFile);
        }

        if ($typeNode instanceof ArrayShapeNode) {
            return self::satisfiesArrayShape($arrayExpr, $typeNode, $currentFile);
        }

        return false;
    }

    /**
     * Checks if a type contract permits an empty array ([]).
     * Returns true for unbounded array/list/iterable, T[], and shapes with only optional keys.
     * Returns false for non-empty-array, non-empty-list, and shapes with required keys.
     */
    public static function satisfiesEmptyArray(TypeNode $typeNode): bool
    {
        if ($typeNode instanceof IdentifierTypeNode) {
            return isset(self::EMPTY_ARRAY_ALLOWED_IDENTIFIERS[strtolower($typeNode->name)]);
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return true;
        }

        if ($typeNode instanceof GenericTypeNode) {
            return isset(self::EMPTY_ARRAY_ALLOWED_GENERICS[strtolower((string) $typeNode->type)]);
        }

        if ($typeNode instanceof ArrayShapeNode) {
            foreach ($typeNode->items as $item) {
                if (! $item->optional) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Evaluates an array expression against bare identifier types (array, list, non-empty-list).
     */
    private static function satisfiesIdentifierArray(Array_ $arrayExpr, IdentifierTypeNode $node): bool
    {
        $name = strtolower($node->name);

        return match ($name) {
            'array', 'iterable', 'mixed' => true,
            'list' => self::isSequentialList($arrayExpr),
            'non-empty-array' => \count($arrayExpr->items) > 0,
            'non-empty-list' => \count($arrayExpr->items) > 0 && self::isSequentialList($arrayExpr),
            default => false,
        };
    }

    /**
     * Evaluates an array expression against generic array containers:
     * - Single template argument: list<T>, non-empty-list<T>, array<T>
     * - Double template argument: array<K, V>, non-empty-array<K, V>
     */
    private static function satisfiesGenericArray(Array_ $arrayExpr, GenericTypeNode $node, string $currentFile): bool
    {
        $base = strtolower((string) $node->type);
        $genericCount = \count($node->genericTypes);

        if ($base === 'list' && $genericCount >= 1) {
            return self::satisfiesList($arrayExpr, $node->genericTypes[0], false, $currentFile);
        }

        if ($base === 'non-empty-list' && $genericCount >= 1) {
            return self::satisfiesList($arrayExpr, $node->genericTypes[0], true, $currentFile);
        }

        if (($base === 'array' || $base === 'iterable') && $genericCount === 1) {
            return self::satisfiesList($arrayExpr, $node->genericTypes[0], false, $currentFile);
        }

        if (($base === 'array' || $base === 'iterable') && $genericCount >= 2) {
            return self::satisfiesKeyValueArray($arrayExpr, $node->genericTypes[0], $node->genericTypes[1], false, $currentFile);
        }

        if ($base === 'non-empty-array' && $genericCount === 1) {
            return self::satisfiesList($arrayExpr, $node->genericTypes[0], true, $currentFile);
        }

        if ($base === 'non-empty-array' && $genericCount >= 2) {
            return self::satisfiesKeyValueArray($arrayExpr, $node->genericTypes[0], $node->genericTypes[1], true, $currentFile);
        }

        return false;
    }

    /**
     * Verifies that all array items have sequential integer keys (0..n-1)
     * and every item value statically satisfies the element contract T.
     */
    private static function satisfiesList(Array_ $arrayExpr, TypeNode $itemType, bool $requireNonEmpty, string $currentFile): bool
    {
        $count = \count($arrayExpr->items);
        if ($requireNonEmpty && $count === 0) {
            return false;
        }

        $expectedIdx = 0;
        foreach ($arrayExpr->items as $item) {
            if ($item === null) {
                return false;
            }

            if ($item->key !== null) {
                if (! ($item->key instanceof LNumber) || $item->key->value !== $expectedIdx) {
                    return false;
                }
            }
            $expectedIdx++;

            if (! StaticTypeEvaluator::satisfies($item->value, $itemType, $currentFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifies that every key in the array expression satisfies key contract K
     * and every corresponding value satisfies value contract V.
     */
    private static function satisfiesKeyValueArray(Array_ $arrayExpr, TypeNode $keyType, TypeNode $valueType, bool $requireNonEmpty, string $currentFile): bool
    {
        $count = \count($arrayExpr->items);
        if ($requireNonEmpty && $count === 0) {
            return false;
        }

        $currentIdx = 0;
        foreach ($arrayExpr->items as $item) {
            if ($item === null) {
                return false;
            }

            if ($item->key === null) {
                $keyExpr = new LNumber($currentIdx);
                $currentIdx++;
            } elseif ($item->key instanceof LNumber) {
                $keyExpr = $item->key;
                $currentIdx = max($currentIdx, $item->key->value + 1);
            } elseif ($item->key instanceof String_ || $item->key instanceof Concat || $item->key instanceof Encapsed) {
                $keyExpr = $item->key;
            } else {
                return false;
            }

            if (! StaticTypeEvaluator::satisfies($keyExpr, $keyType, $currentFile)) {
                return false;
            }

            if (! StaticTypeEvaluator::satisfies($item->value, $valueType, $currentFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Evaluates an array expression against a sealed or unsealed ArrayShapeNode.
     * Guarantees all required keys exist, no unsealed keys intrude in sealed shapes,
     * and all value expressions satisfy their corresponding shape property contracts.
     */
    private static function satisfiesArrayShape(Array_ $arrayExpr, ArrayShapeNode $shapeNode, string $currentFile): bool
    {
        if ($shapeNode->kind === ArrayShapeNode::KIND_LIST && ! self::isSequentialList($arrayExpr)) {
            return false;
        }

        $shapeMap = [];
        $autoIdx = 0;
        foreach ($shapeNode->items as $shapeItem) {
            $key = match (true) {
                $shapeItem->keyName instanceof ConstExprStringNode => $shapeItem->keyName->value,
                $shapeItem->keyName instanceof ConstExprIntegerNode => (int) $shapeItem->keyName->value,
                $shapeItem->keyName instanceof IdentifierTypeNode => $shapeItem->keyName->name,
                $shapeItem->keyName !== null => (string) $shapeItem->keyName,
                default => $autoIdx,
            };

            if (\is_int($key)) {
                $autoIdx = max($autoIdx, $key + 1);
            }

            $shapeMap[$key] = $shapeItem;
        }

        $actualItems = [];
        $currentIdx = 0;
        foreach ($arrayExpr->items as $item) {
            if ($item === null) {
                return false;
            }

            if ($item->key === null) {
                $key = $currentIdx;
                $currentIdx++;
            } elseif ($item->key instanceof LNumber) {
                $key = $item->key->value;
                $currentIdx = max($currentIdx, $key + 1);
            } elseif ($item->key instanceof String_) {
                $key = $item->key->value;
            } else {
                return false;
            }

            $actualItems[$key] = $item->value;
        }

        foreach ($shapeMap as $expectedKey => $shapeItem) {
            if (! \array_key_exists($expectedKey, $actualItems)) {
                if (! $shapeItem->optional) {
                    return false;
                }

                continue;
            }

            if (! StaticTypeEvaluator::satisfies($actualItems[$expectedKey], $shapeItem->valueType, $currentFile)) {
                return false;
            }
        }

        foreach ($actualItems as $actualKey => $actualValueExpr) {
            if (\array_key_exists($actualKey, $shapeMap)) {
                continue;
            }

            if ($shapeNode->sealed) {
                return false;
            }

            if ($shapeNode->unsealedType !== null) {
                if ($shapeNode->unsealedType->keyType !== null) {
                    $keyExpr = \is_int($actualKey) ? new LNumber($actualKey) : new String_($actualKey);
                    if (! StaticTypeEvaluator::satisfies($keyExpr, $shapeNode->unsealedType->keyType, $currentFile)) {
                        return false;
                    }
                }

                if (! StaticTypeEvaluator::satisfies($actualValueExpr, $shapeNode->unsealedType->valueType, $currentFile)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Checks whether an array expression has sequential 0..n-1 integer keys.
     */
    private static function isSequentialList(Array_ $arrayExpr): bool
    {
        $expectedIdx = 0;
        foreach ($arrayExpr->items as $item) {
            if ($item === null) {
                return false;
            }

            if ($item->key !== null) {
                if (! ($item->key instanceof LNumber) || $item->key->value !== $expectedIdx) {
                    return false;
                }
            }
            $expectedIdx++;
        }

        return true;
    }
}
