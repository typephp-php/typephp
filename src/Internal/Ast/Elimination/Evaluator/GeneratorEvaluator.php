<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Util\Config;

/**
 * Statically evaluates generator yield, yield from, TSend, and TReturn expressions
 * at compile time to eliminate redundant runtime generator checks.
 *
 * @internal
 */
final class GeneratorEvaluator
{
    /**
     * O(1) hash map of valid generator and iterable return type identifiers.
     *
     * @var array<string, true>
     */
    private const GENERATOR_ROOT_TYPES = [
        'generator' => true,
        'iterable' => true,
        'traversable' => true,
        'iterator' => true,
    ];

    /**
     * Extracts the [key, value, send, return] TypeNode contract from a generator's return TypeNode.
     * Returns null if the return type is an unexpanded type alias or non-generator type.
     *
     * @return array{key: ?TypeNode, value: ?TypeNode, send: ?TypeNode, return: ?TypeNode}|null
     */
    public static function extractContract(TypeNode $returnTypeNode): ?array
    {
        if ($returnTypeNode instanceof IdentifierTypeNode) {
            $base = strtolower(ltrim($returnTypeNode->name, '\\'));
            if (! isset(self::GENERATOR_ROOT_TYPES[$base])) {
                return null;
            }

            return [
                'key' => null,
                'value' => null,
                'send' => null,
                'return' => null,
            ];
        }

        if ($returnTypeNode instanceof ArrayTypeNode) {
            return [
                'key' => null,
                'value' => $returnTypeNode->type,
                'send' => null,
                'return' => null,
            ];
        }

        if ($returnTypeNode instanceof GenericTypeNode) {
            $base = strtolower(ltrim($returnTypeNode->type->name, '\\'));
            if (! isset(self::GENERATOR_ROOT_TYPES[$base])) {
                return null;
            }

            $count = \count($returnTypeNode->genericTypes);

            if ($count === 1) {
                return [
                    'key' => null,
                    'value' => $returnTypeNode->genericTypes[0],
                    'send' => null,
                    'return' => null,
                ];
            }

            if ($count === 2) {
                return [
                    'key' => $returnTypeNode->genericTypes[0],
                    'value' => $returnTypeNode->genericTypes[1],
                    'send' => null,
                    'return' => null,
                ];
            }

            if ($count === 3) {
                return [
                    'key' => $returnTypeNode->genericTypes[0],
                    'value' => $returnTypeNode->genericTypes[1],
                    'send' => $returnTypeNode->genericTypes[2],
                    'return' => null,
                ];
            }

            if ($count >= 4) {
                return [
                    'key' => $returnTypeNode->genericTypes[0],
                    'value' => $returnTypeNode->genericTypes[1],
                    'send' => $returnTypeNode->genericTypes[2],
                    'return' => $returnTypeNode->genericTypes[3],
                ];
            }
        }

        return null;
    }

    /**
     * Determines whether `RuntimeTypeChecker::checkYield()` can be statically eliminated for a `yield` expression.
     */
    public static function canEliminateYield(
        Yield_ $yieldNode,
        ?TypeNode $keyType,
        ?TypeNode $valueType,
        string $currentFile = '',
        ?Expr $precedingValueExpr = null
    ): bool {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($keyType !== null && ! self::isMixedType($keyType)) {
            $keyExpr = $yieldNode->key ?? new LNumber(0);
            if (! StaticTypeEvaluator::satisfies($keyExpr, $keyType, $currentFile)) {
                return false;
            }
        }

        if ($valueType !== null && ! self::isMixedType($valueType)) {
            $valExpr = $precedingValueExpr ?? $yieldNode->value ?? new ConstFetch(new Name('null'));
            if (! StaticTypeEvaluator::satisfies($valExpr, $valueType, $currentFile)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determines whether `RuntimeTypeChecker::checkSend()` can be statically eliminated.
     * True when `TSend` is omitted (< 3 template arguments) or explicitly `mixed`.
     */
    public static function canEliminateSend(?TypeNode $sendType): bool
    {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        return $sendType === null || self::isMixedType($sendType);
    }

    /**
     * Determines whether `RuntimeTypeChecker::wrapIterable()` can be statically eliminated on `yield from`.
     */
    public static function canEliminateYieldFrom(
        YieldFrom $yieldFromNode,
        ?TypeNode $keyType,
        ?TypeNode $valueType,
        string $currentFile = ''
    ): bool {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if (! ($yieldFromNode->expr instanceof Array_)) {
            return false;
        }

        if (($keyType === null || self::isMixedType($keyType)) && ($valueType === null || self::isMixedType($valueType))) {
            return true;
        }

        if ($keyType === null || self::isMixedType($keyType)) {
            /** @var TypeNode $valueType */
            $targetType = new GenericTypeNode(new IdentifierTypeNode('iterable'), [$valueType]);

            return ArrayLiteralEvaluator::satisfies($yieldFromNode->expr, $targetType, $currentFile);
        }

        $effectiveValueType = $valueType ?? new IdentifierTypeNode('mixed');
        $targetType = new GenericTypeNode(new IdentifierTypeNode('iterable'), [$keyType, $effectiveValueType]);

        return ArrayLiteralEvaluator::satisfies($yieldFromNode->expr, $targetType, $currentFile);
    }

    /**
     * Determines whether `RuntimeTypeChecker::checkGeneratorReturn()` can be statically eliminated.
     */
    public static function canEliminateGeneratorReturn(
        ?Expr $returnExpr,
        ?TypeNode $returnType,
        string $currentFile = ''
    ): bool {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($returnType === null || self::isMixedType($returnType)) {
            return true;
        }

        $effectiveExpr = $returnExpr ?? new ConstFetch(new Name('null'));

        return StaticTypeEvaluator::satisfies($effectiveExpr, $returnType, $currentFile);
    }

    /**
     * Checks if a TypeNode is an unconstrained `mixed` identifier.
     */
    private static function isMixedType(TypeNode $typeNode): bool
    {
        return $typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'mixed';
    }
}
