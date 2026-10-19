<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * Statically evaluates whether a deterministic scalar AST expression
 * satisfies an expected scalar, range, bitmask, or literal TypeNode contract.
 *
 * @internal
 */
final class ScalarEvaluator
{
    /**
     * O(1) hash map of type identifiers satisfied by boolean literal `true`.
     *
     * @var array<string, true>
     */
    private const TRUE_COMPATIBLE_TYPES = [
        'bool' => true,
        'boolean' => true,
        'true' => true,
        'truthy' => true,
        'scalar' => true,
        'mixed' => true,
    ];

    /**
     * O(1) hash map of type identifiers satisfied by boolean literal `false`.
     *
     * @var array<string, true>
     */
    private const FALSE_COMPATIBLE_TYPES = [
        'bool' => true,
        'boolean' => true,
        'false' => true,
        'falsy' => true,
        'falsey' => true,
        'scalar' => true,
        'mixed' => true,
    ];

    /**
     * O(1) hash map of type identifiers satisfied by literal `null`.
     *
     * @var array<string, true>
     */
    private const NULL_COMPATIBLE_TYPES = [
        'null' => true,
        'void' => true,
        'mixed' => true,
    ];

    /**
     * O(1) hash map of type identifiers satisfied by any non-empty string.
     *
     * @var array<string, true>
     */
    private const NON_EMPTY_STRING_COMPATIBLE_TYPES = [
        'string' => true,
        'non-empty-string' => true,
        'scalar' => true,
        'mixed' => true,
    ];

    /**
     * Determines whether an AST scalar expression satisfies the target type contract.
     *
     * Flow of logic:
     * 1. Evaluates integers (handling LNumber, UnaryMinus, and UnaryPlus).
     * 2. Evaluates floating-point numbers (DNumber).
     * 3. Evaluates literal strings.
     * 4. Evaluates string concatenations with guaranteed non-empty operands.
     * 5. Evaluates language constants (true, false, null).
     */
    public static function satisfies(Expr $expr, TypeNode $typeNode): bool
    {
        $intValue = self::extractIntegerValue($expr);
        if ($intValue !== null) {
            return self::satisfiesInteger($intValue, $typeNode);
        }

        $floatValue = self::extractFloatValue($expr);
        if ($floatValue !== null) {
            return self::satisfiesFloat($floatValue, $typeNode);
        }

        if ($expr instanceof String_) {
            return self::satisfiesString($expr->value, $typeNode);
        }

        if ($expr instanceof Concat && self::isGuaranteedNonEmptyString($expr)) {
            return self::isNonEmptyStringType($typeNode);
        }

        if ($expr instanceof ConstFetch) {
            return self::satisfiesConst(strtolower($expr->name->getLast()), $typeNode);
        }

        return false;
    }

    /**
     * Checks if an expression represents a literal `null` constant.
     */
    public static function isNullLiteral(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && strtolower($expr->name->getLast()) === 'null';
    }

    /**
     * Recursively verifies whether a string or concat expression is guaranteed
     * to produce a non-empty string. If either operand in a concatenation tree
     * has a static length >= 1, the resulting string can never be empty.
     */
    public static function isGuaranteedNonEmptyString(Expr $expr): bool
    {
        if ($expr instanceof String_ && $expr->value !== '') {
            return true;
        }

        if ($expr instanceof Concat) {
            return self::isGuaranteedNonEmptyString($expr->left) || self::isGuaranteedNonEmptyString($expr->right);
        }

        return false;
    }

    /**
     * Extracts an integer value from an expression, unwrapping unary sign operators.
     */
    private static function extractIntegerValue(Expr $expr): ?int
    {
        if ($expr instanceof LNumber) {
            return $expr->value;
        }

        if ($expr instanceof UnaryMinus) {
            $inner = self::extractIntegerValue($expr->expr);

            return $inner !== null ? -$inner : null;
        }

        if ($expr instanceof UnaryPlus) {
            return self::extractIntegerValue($expr->expr);
        }

        return null;
    }

    /**
     * Extracts a floating-point value from an expression, unwrapping unary sign operators.
     */
    private static function extractFloatValue(Expr $expr): ?float
    {
        if ($expr instanceof DNumber) {
            return $expr->value;
        }

        if ($expr instanceof UnaryMinus) {
            $inner = self::extractFloatValue($expr->expr);

            return $inner !== null ? -$inner : null;
        }

        if ($expr instanceof UnaryPlus) {
            return self::extractFloatValue($expr->expr);
        }

        return null;
    }

    /**
     * Evaluates an integer against ConstTypeNode, GenericTypeNode ranges/bitmasks,
     * and scalar identifier refinements (e.g. positive-int, non-zero-int).
     */
    private static function satisfiesInteger(int $value, TypeNode $typeNode): bool
    {
        if ($typeNode instanceof ConstTypeNode) {
            return $typeNode->constExpr instanceof ConstExprIntegerNode
                && (int) $typeNode->constExpr->value === $value;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower($typeNode->type->name);

            if ($base === 'int' || $base === 'integer') {
                return self::satisfiesIntRange($value, $typeNode);
            }

            if ($base === 'int-mask' || $base === 'int-mask-of') {
                return self::satisfiesIntMask($value, $typeNode);
            }
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return match (strtolower($typeNode->name)) {
                'int', 'integer', 'scalar', 'numeric', 'number', 'mixed' => true,
                'positive-int' => $value > 0,
                'negative-int' => $value < 0,
                'non-positive-int' => $value <= 0,
                'non-negative-int', 'unsigned-int' => $value >= 0,
                'non-zero-int' => $value !== 0,
                'truthy' => $value !== 0,
                'falsy', 'falsey' => $value === 0,
                'array-key' => true,
                default => false,
            };
        }

        return false;
    }

    /**
     * Verifies if an integer falls within static `int<min, max>` bounds.
     */
    private static function satisfiesIntRange(int $value, GenericTypeNode $node): bool
    {
        $min = self::resolveRangeBound($node->genericTypes[0] ?? null);
        $max = self::resolveRangeBound($node->genericTypes[1] ?? null);

        if ($min !== null && $value < $min) {
            return false;
        }

        if ($max !== null && $value > $max) {
            return false;
        }

        return true;
    }

    /**
     * Verifies if an integer bitmask matches allowed bit flags in `int-mask<A, B, C>`
     * or `int-mask-of<self::FLAG_*>`. Zero is always valid as it represents an empty bitmask.
     */
    private static function satisfiesIntMask(int $value, GenericTypeNode $node): bool
    {
        if ($value === 0) {
            return true;
        }

        $allowedMask = 0;
        foreach ($node->genericTypes as $gt) {
            if ($gt instanceof ConstTypeNode && $gt->constExpr instanceof ConstExprIntegerNode) {
                $allowedMask |= (int) $gt->constExpr->value;
            } else {
                return false;
            }
        }

        return ($value & ~$allowedMask) === 0;
    }

    /**
     * Resolves integer bounds from range AST nodes, ignoring 'min', 'max', and '*'.
     */
    private static function resolveRangeBound(?TypeNode $boundNode): ?int
    {
        if ($boundNode === null) {
            return null;
        }

        if ($boundNode instanceof ConstTypeNode && $boundNode->constExpr instanceof ConstExprIntegerNode) {
            return (int) $boundNode->constExpr->value;
        }

        if ($boundNode instanceof IdentifierTypeNode) {
            $name = strtolower($boundNode->name);
            if ($name === 'min' || $name === 'max' || $name === '*') {
                return null;
            }

            if (is_numeric($boundNode->name)) {
                return (int) $boundNode->name;
            }
        }

        return null;
    }

    /**
     * Evaluates a float against float literal constants and float refinements.
     */
    private static function satisfiesFloat(float $value, TypeNode $typeNode): bool
    {
        if ($typeNode instanceof ConstTypeNode) {
            return $typeNode->constExpr instanceof ConstExprFloatNode
                && abs($value - (float) $typeNode->constExpr->value) < 1e-9;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return match (strtolower($typeNode->name)) {
                'float', 'double', 'scalar', 'numeric', 'number', 'mixed' => true,
                'positive-float' => $value > 0.0,
                'negative-float' => $value < 0.0,
                'non-positive-float' => $value <= 0.0,
                'non-negative-float' => $value >= 0.0,
                'non-zero-float' => $value !== 0.0 && $value !== -0.0,
                'truthy' => $value !== 0.0,
                'falsy', 'falsey' => $value === 0.0,
                default => false,
            };
        }

        return false;
    }

    /**
     * Evaluates a string against string literals and string refinements (e.g. non-empty, casing).
     */
    private static function satisfiesString(string $value, TypeNode $typeNode): bool
    {
        if ($typeNode instanceof ConstTypeNode) {
            return $typeNode->constExpr instanceof ConstExprStringNode
                && $typeNode->constExpr->value === $value;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            return match (strtolower($typeNode->name)) {
                'string', 'literal-string', 'scalar', 'mixed' => true,
                'non-empty-string' => $value !== '',
                'lowercase-string' => strtolower($value) === $value,
                'non-empty-lowercase-string' => $value !== '' && strtolower($value) === $value,
                'uppercase-string' => strtoupper($value) === $value,
                'non-empty-uppercase-string' => $value !== '' && strtoupper($value) === $value,
                'numeric-string' => is_numeric($value),
                'truthy', 'truthy-string', 'non-falsy-string' => (bool) $value === true,
                'falsy', 'falsey' => (bool) $value === false,
                'array-key' => true,
                default => false,
            };
        }

        return false;
    }

    /**
     * Checks if the type node accepts any non-empty string.
     */
    private static function isNonEmptyStringType(TypeNode $typeNode): bool
    {
        if ($typeNode instanceof IdentifierTypeNode) {
            return isset(self::NON_EMPTY_STRING_COMPATIBLE_TYPES[strtolower($typeNode->name)]);
        }

        return false;
    }

    /**
     * Evaluates literal language constants (`true`, `false`, `null`) against expected contracts.
     */
    private static function satisfiesConst(string $constName, TypeNode $typeNode): bool
    {
        if ($typeNode instanceof ConstTypeNode) {
            return ($constName === 'true' && $typeNode->constExpr instanceof ConstExprTrueNode)
                || ($constName === 'false' && $typeNode->constExpr instanceof ConstExprFalseNode)
                || ($constName === 'null' && $typeNode->constExpr instanceof ConstExprNullNode);
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            $typeName = strtolower($typeNode->name);

            if ($constName === 'true') {
                return isset(self::TRUE_COMPATIBLE_TYPES[$typeName]);
            }

            if ($constName === 'false') {
                return isset(self::FALSE_COMPATIBLE_TYPES[$typeName]);
            }

            if ($constName === 'null') {
                return isset(self::NULL_COMPATIBLE_TYPES[$typeName]);
            }
        }

        return false;
    }
}
