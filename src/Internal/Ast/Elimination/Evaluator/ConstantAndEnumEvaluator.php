<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Resolver\SpecialTypeResolver;

/**
 * Statically evaluates whether constant fetches, enum cases, bitmasks,
 * and wildcard patterns satisfy expected TypeNode contracts.
 *
 * @internal
 */
final class ConstantAndEnumEvaluator
{
    /**
     * Determines whether an expression satisfies constant or enum contracts.
     *
     * Flow of logic:
     * 1. Evaluates ClassConstFetch nodes against exact constants, wildcards, and base enum types.
     * 2. Evaluates bitwise OR expressions (A | B) against `int-mask-of` wildcards.
     * 3. Evaluates enum backing property reads (Enum::Case->value) against `value-of<Enum>`.
     */
    public static function satisfies(Expr $expr, TypeNode $typeNode, string $currentFile = ''): bool
    {
        if ($expr instanceof ClassConstFetch) {
            return self::satisfiesClassConst($expr, $typeNode, $currentFile);
        }

        if ($expr instanceof BitwiseOr) {
            return self::satisfiesBitwiseOr($expr, $typeNode, $currentFile);
        }

        if ($expr instanceof PropertyFetch) {
            return self::satisfiesEnumBackingValue($expr, $typeNode, $currentFile);
        }

        return false;
    }

    /**
     * Evaluates a class constant fetch against ConstTypeNode, IdentifierTypeNode, or int-mask-of.
     */
    private static function satisfiesClassConst(ClassConstFetch $fetch, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($fetch->class instanceof Name) || ! ($fetch->name instanceof Identifier)) {
            return false;
        }

        $constName = $fetch->name->toString();
        $targetClassName = $fetch->class->toString();

        if ($typeNode instanceof ConstTypeNode && $typeNode->constExpr instanceof ConstFetchNode) {
            $docConstName = $typeNode->constExpr->name;

            if (str_contains($docConstName, '*')) {
                return self::matchesWildcard($constName, $docConstName);
            }

            return $constName === $docConstName;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if ($currentFile !== '') {
                $resolvedFetchClass = SpecialTypeResolver::resolveFqcnForFile($targetClassName, $currentFile);
                $resolvedTypeClass = SpecialTypeResolver::resolveFqcnForFile($typeNode->name, $currentFile);

                return $resolvedFetchClass !== '' && $resolvedFetchClass === $resolvedTypeClass;
            }

            return $targetClassName === $typeNode->name;
        }

        if ($typeNode instanceof GenericTypeNode && strtolower($typeNode->type->name) === 'int-mask-of') {
            $inner = $typeNode->genericTypes[0] ?? null;
            if ($inner instanceof ConstTypeNode && $inner->constExpr instanceof ConstFetchNode) {
                return self::matchesWildcard($constName, $inner->constExpr->name);
            }
        }

        return false;
    }

    /**
     * Recursively verifies that both operands of a bitwise OR expression satisfy an `int-mask-of` wildcard.
     */
    private static function satisfiesBitwiseOr(BitwiseOr $expr, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($typeNode instanceof GenericTypeNode) || strtolower($typeNode->type->name) !== 'int-mask-of') {
            return false;
        }

        return self::satisfies($expr->left, $typeNode, $currentFile)
            && self::satisfies($expr->right, $typeNode, $currentFile);
    }

    /**
     * Verifies whether an Enum::Case->value property access satisfies `value-of<Enum>`.
     */
    private static function satisfiesEnumBackingValue(PropertyFetch $fetch, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($fetch->name instanceof Identifier) || $fetch->name->name !== 'value') {
            return false;
        }

        if (! ($fetch->var instanceof ClassConstFetch) || ! ($fetch->var->class instanceof Name)) {
            return false;
        }

        if (! ($typeNode instanceof GenericTypeNode) || strtolower($typeNode->type->name) !== 'value-of') {
            return false;
        }

        $enumTarget = $typeNode->genericTypes[0] ?? null;
        if (! ($enumTarget instanceof IdentifierTypeNode)) {
            return false;
        }

        $enumClassName = $fetch->var->class->toString();

        if ($currentFile !== '') {
            $resolvedEnumClass = SpecialTypeResolver::resolveFqcnForFile($enumClassName, $currentFile);
            $resolvedTargetClass = SpecialTypeResolver::resolveFqcnForFile($enumTarget->name, $currentFile);

            return $resolvedEnumClass !== '' && $resolvedEnumClass === $resolvedTargetClass;
        }

        return $enumClassName === $enumTarget->name;
    }

    /**
     * Compares a constant name against a wildcard pattern prefix (e.g. 'STATUS_PAID' vs 'STATUS_*').
     */
    private static function matchesWildcard(string $actualName, string $pattern): bool
    {
        $prefix = rtrim($pattern, '*');

        return str_starts_with(strtoupper($actualName), strtoupper($prefix));
    }
}
