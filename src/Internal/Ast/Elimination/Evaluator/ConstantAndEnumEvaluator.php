<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use BackedEnum;
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
use UnitEnum;

/**
 * Statically evaluates whether constant fetches, enum cases, bitmasks,
 * and wildcard patterns satisfy expected TypeNode contracts.
 *
 * @internal
 */
final class ConstantAndEnumEvaluator
{
    /**
     * O(1) hash map of relative class keywords.
     *
     * @var array<string, true>
     */
    private const RELATIVE_CLASS_KEYWORDS = [
        'self' => true,
        'static' => true,
        'parent' => true,
        '$this' => true,
    ];

    /**
     * Determines whether an expression satisfies constant or enum contracts.
     *
     * Flow of logic:
     * 1. Evaluates ClassConstFetch nodes against exact constants, wildcards, and loaded enum cases.
     * 2. Evaluates bitwise OR expressions (A | B) against `int-mask-of` wildcards.
     * 3. Evaluates backed enum property reads (Enum::Case->value) against `value-of<Enum>`.
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
        if (strtolower($constName) === 'class') {
            return false;
        }

        $targetClassName = $fetch->class->toString();

        if ($typeNode instanceof ConstTypeNode && $typeNode->constExpr instanceof ConstFetchNode) {
            if (! self::classesMatch($targetClassName, $typeNode->constExpr->className, $currentFile)) {
                return false;
            }

            $docConstName = $typeNode->constExpr->name;

            if (str_contains($docConstName, '*')) {
                return self::matchesWildcard($constName, $docConstName);
            }

            return $constName === $docConstName;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if (! self::classesMatch($targetClassName, $typeNode->name, $currentFile)) {
                return false;
            }

            $resolvedEnum = self::resolveClassForReflection($targetClassName, $currentFile);
            if ($resolvedEnum === '' || ! enum_exists($resolvedEnum, false)) {
                return false;
            }

            return self::isEnumCase($resolvedEnum, $constName);
        }

        if ($typeNode instanceof GenericTypeNode && strtolower((string) $typeNode->type) === 'int-mask-of') {
            $inner = $typeNode->genericTypes[0] ?? null;
            if ($inner instanceof ConstTypeNode && $inner->constExpr instanceof ConstFetchNode) {
                if (! self::classesMatch($targetClassName, $inner->constExpr->className, $currentFile)) {
                    return false;
                }

                $pattern = $inner->constExpr->name;
                if (str_contains($pattern, '*')) {
                    return self::matchesWildcard($constName, $pattern);
                }

                return $constName === $pattern;
            }
        }

        return false;
    }

    /**
     * Recursively verifies that both operands of a bitwise OR expression satisfy an `int-mask-of` wildcard.
     */
    private static function satisfiesBitwiseOr(BitwiseOr $expr, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($typeNode instanceof GenericTypeNode) || strtolower((string) $typeNode->type) !== 'int-mask-of') {
            return false;
        }

        return self::satisfies($expr->left, $typeNode, $currentFile)
            && self::satisfies($expr->right, $typeNode, $currentFile);
    }

    /**
     * Verifies whether a BackedEnum::Case->value property access satisfies `value-of<Enum>`.
     */
    private static function satisfiesEnumBackingValue(PropertyFetch $fetch, TypeNode $typeNode, string $currentFile): bool
    {
        if (! ($fetch->name instanceof Identifier) || $fetch->name->name !== 'value') {
            return false;
        }

        if (! ($fetch->var instanceof ClassConstFetch) || ! ($fetch->var->class instanceof Name) || ! ($fetch->var->name instanceof Identifier)) {
            return false;
        }

        if (! ($typeNode instanceof GenericTypeNode) || strtolower((string) $typeNode->type) !== 'value-of') {
            return false;
        }

        $enumTarget = $typeNode->genericTypes[0] ?? null;
        if (! ($enumTarget instanceof IdentifierTypeNode)) {
            return false;
        }

        $enumClassName = $fetch->var->class->toString();
        $caseName = $fetch->var->name->toString();

        if (strtolower($caseName) === 'class') {
            return false;
        }

        if (! self::classesMatch($enumClassName, $enumTarget->name, $currentFile)) {
            return false;
        }

        $resolvedEnum = self::resolveClassForReflection($enumClassName, $currentFile);
        if ($resolvedEnum === '' || ! enum_exists($resolvedEnum, false) || ! is_subclass_of($resolvedEnum, BackedEnum::class)) {
            return false;
        }

        return self::isEnumCase($resolvedEnum, $caseName);
    }

    /**
     * Verifies whether two class references deterministically denote the same class/enum.
     */
    private static function classesMatch(string $fetchClass, string $docClass, string $currentFile): bool
    {
        if ($docClass === '') {
            return false;
        }

        $lowerFetch = strtolower($fetchClass);
        $lowerDoc = strtolower($docClass);

        if ($lowerFetch === 'self' && $lowerDoc === 'self') {
            return true;
        }

        if (isset(self::RELATIVE_CLASS_KEYWORDS[$lowerFetch]) || isset(self::RELATIVE_CLASS_KEYWORDS[$lowerDoc])) {
            return false;
        }

        if ($fetchClass === $docClass) {
            return true;
        }

        if ($currentFile !== '') {
            $resolvedFetchClass = SpecialTypeResolver::resolveFqcnForFile($fetchClass, $currentFile, false);
            $resolvedTypeClass = SpecialTypeResolver::resolveFqcnForFile($docClass, $currentFile, false);

            return $resolvedFetchClass !== '' && $resolvedFetchClass === $resolvedTypeClass;
        }

        return false;
    }

    /**
     * Resolves a class name without invoking autoloading.
     */
    private static function resolveClassForReflection(string $rawClass, string $currentFile): string
    {
        if ($currentFile !== '') {
            return SpecialTypeResolver::resolveFqcnForFile($rawClass, $currentFile, false);
        }

        return ltrim($rawClass, '\\');
    }

    /**
     * Verifies that a symbol is a declared enum case (excluding regular class constants on enums).
     */
    private static function isEnumCase(string $enumClass, string $caseName): bool
    {
        if (! enum_exists($enumClass, false) || ! is_subclass_of($enumClass, UnitEnum::class)) {
            return false;
        }

        foreach ($enumClass::cases() as $case) {
            if ($case->name === $caseName) {
                return true;
            }
        }

        return false;
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
