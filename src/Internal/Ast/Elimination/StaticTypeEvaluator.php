<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\Evaluator\ArrayLiteralEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ConstantAndEnumEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ObjectEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ScalarEvaluator;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Util\Config;

/**
 * Central compile-time evaluator orchestrating static satisfaction checks across AST nodes.
 *
 * @internal
 */
final class StaticTypeEvaluator
{
    /**
     * O(1) hash map of generic containers that carry no object state or WeakMap template bindings.
     *
     * @var array<string, true>
     */
    private const PERMISSIBLE_GENERIC_CONTAINERS = [
        'int' => true,
        'integer' => true,
        'int-mask' => true,
        'int-mask-of' => true,
        'value-of' => true,
        'list' => true,
        'non-empty-list' => true,
        'array' => true,
        'non-empty-array' => true,
        'iterable' => true,
    ];

    /**
     * Determines whether an AST expression deterministically satisfies a TypeNode contract.
     *
     * @param array<string, TypeNode> $trustedVariableTypes
     */
    public static function satisfies(
        Expr $expr,
        TypeNode $typeNode,
        string $currentFile = '',
        array $trustedVariableTypes = []
    ): bool {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return false;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseName = strtolower((string) $typeNode->type);
            if (! isset(self::PERMISSIBLE_GENERIC_CONTAINERS[$baseName])) {
                return false;
            }
        }

        if ($expr instanceof Variable && \is_string($expr->name) && isset($trustedVariableTypes[$expr->name])) {
            return TemplateManager::checkVariance(
                $trustedVariableTypes[$expr->name],
                $typeNode,
                GenericTypeNode::VARIANCE_COVARIANT
            );
        }

        if ($expr instanceof Ternary && $expr->if !== null) {
            return self::satisfies($expr->if, $typeNode, $currentFile, $trustedVariableTypes)
                && self::satisfies($expr->else, $typeNode, $currentFile, $trustedVariableTypes);
        }

        if ($typeNode instanceof NullableTypeNode) {
            if (ScalarEvaluator::isNullLiteral($expr)) {
                return true;
            }

            return self::satisfies($expr, $typeNode->type, $currentFile, $trustedVariableTypes);
        }

        if ($typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $member) {
                if (self::satisfies($expr, $member, $currentFile, $trustedVariableTypes)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof Array_) {
            return ArrayLiteralEvaluator::satisfies($expr, $typeNode, $currentFile, $trustedVariableTypes);
        }

        return ConstantAndEnumEvaluator::satisfies($expr, $typeNode, $currentFile)
            || ObjectEvaluator::satisfies($expr, $typeNode, $currentFile)
            || ScalarEvaluator::satisfies($expr, $typeNode);
    }

    /**
     * Determines whether unsetting a member at the given accessor chain can never
     * violate the target TypeNode contract (e.g., unset($map['k']) on array<K, V> or T[]).
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function isUnsetSafe(TypeNode $typeNode, array $chain): bool
    {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($chain === []) {
            return true;
        }

        $firstKind = $chain[0][0];
        if ($firstKind !== 'dim') {
            return false;
        }

        if ($typeNode instanceof NullableTypeNode) {
            return self::isUnsetSafe($typeNode->type, $chain);
        }

        if ($typeNode instanceof UnionTypeNode) {
            $hasArrayMember = false;
            foreach ($typeNode->types as $member) {
                if ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    continue;
                }

                $hasArrayMember = true;
                if (! self::isUnsetSafe($member, $chain)) {
                    return false;
                }
            }

            return $hasArrayMember;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            $lower = strtolower($typeNode->name);

            return $lower === 'array' || $lower === 'iterable' || $lower === 'mixed';
        }

        if ($typeNode instanceof ArrayTypeNode) {
            if (\count($chain) === 1) {
                return true;
            }

            return self::isUnsetSafe($typeNode->type, \array_slice($chain, 1));
        }

        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower((string) $typeNode->type);
            if ($base !== 'array' && $base !== 'iterable') {
                return false;
            }

            if (\count($chain) === 1) {
                return true;
            }

            $genericCount = \count($typeNode->genericTypes);
            if ($genericCount === 0) {
                return true;
            }

            $valueType = $genericCount >= 2 ? $typeNode->genericTypes[1] : $typeNode->genericTypes[0];

            return self::isUnsetSafe($valueType, \array_slice($chain, 1));
        }

        return false;
    }
}
