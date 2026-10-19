<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\Evaluator\ArrayLiteralEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ConstantAndEnumEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ObjectEvaluator;
use TypePHP\Internal\Ast\Elimination\Evaluator\ScalarEvaluator;
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
     * Flow of logic:
     * 1. Returns false if `eliminate_statically_proven_checks` is disabled in configuration.
     * 2. Rejects IntersectionTypeNode unconditionally (deferred to runtime to avoid autoloader deadlocks).
     * 3. Rejects generic object templates to ensure runtime WeakMap registrations occur.
     *    Permits scalar ranges (int<min, max>), bitmasks (int-mask, int-mask-of), value-of, and array containers.
     * 4. Handles NullableTypeNode: satisfies immediately if expression is literal null,
     *    otherwise checks whether expression satisfies the inner type.
     * 5. Handles UnionTypeNode: satisfies if ANY member in the union is statically satisfied.
     * 6. Delegates array expressions to ArrayLiteralEvaluator.
     * 7. Delegates constants, enums, and bitmasks to ConstantAndEnumEvaluator.
     * 8. Delegates object instantiations and identity returns to ObjectEvaluator.
     * 9. Delegates nominal scalars and literals to ScalarEvaluator.
     */
    public static function satisfies(Expr $expr, TypeNode $typeNode, string $currentFile = ''): bool
    {
        if (! Config::isEliminateStaticallyProvenChecksEnabled()) {
            return false;
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return false;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseName = strtolower($typeNode->type->name);
            if (! isset(self::PERMISSIBLE_GENERIC_CONTAINERS[$baseName])) {
                return false;
            }
        }

        if ($typeNode instanceof NullableTypeNode) {
            if (ScalarEvaluator::isNullLiteral($expr)) {
                return true;
            }

            return self::satisfies($expr, $typeNode->type, $currentFile);
        }

        if ($typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $member) {
                if (self::satisfies($expr, $member, $currentFile)) {
                    return true;
                }
            }

            return false;
        }

        if ($expr instanceof Array_) {
            return ArrayLiteralEvaluator::satisfies($expr, $typeNode, $currentFile);
        }

        return ConstantAndEnumEvaluator::satisfies($expr, $typeNode, $currentFile)
            || ObjectEvaluator::satisfies($expr, $typeNode, $currentFile)
            || ScalarEvaluator::satisfies($expr, $typeNode);
    }
}
