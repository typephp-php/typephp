<?php

declare(strict_types=1);

namespace TypePHP\Internal\Ast\Elimination\Evaluator;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\UnaryPlus;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;

/**
 * Statically folds deterministic binary and unary arithmetic expressions at compile time,
 * handling IEEE 754 floating-point calculations with epsilon tolerance.
 *
 * @internal
 */
final class ArithmeticEvaluator
{
    /**
     * Standard IEEE 754 comparison tolerance matching runtime ConstValidator.
     */
    public const FLOAT_EPSILON = 1e-9;

    /**
     * Recursively folds an arithmetic expression into a deterministic int or float.
     *
     * Flow of logic:
     * 1. Resolves integer and float scalar literal values.
     * 2. Evaluates unary signs (+ and -) on folded sub-expressions.
     * 3. Recursively evaluates left and right operands of binary arithmetic operations.
     * 4. Guards against division and modulo by zero, aborting on invalid arithmetic.
     * 5. Computes addition, subtraction, multiplication, division, modulo, and exponentiation.
     * 6. Verifies that the resulting number is finite (not NAN or INF).
     */
    public static function evaluate(Expr $expr): int|float|null
    {
        if ($expr instanceof LNumber) {
            return $expr->value;
        }

        if ($expr instanceof DNumber) {
            return $expr->value;
        }

        if ($expr instanceof UnaryMinus) {
            $inner = self::evaluate($expr->expr);

            return $inner !== null ? -$inner : null;
        }

        if ($expr instanceof UnaryPlus) {
            return self::evaluate($expr->expr);
        }

        if ($expr instanceof BinaryOp) {
            return self::evaluateBinaryOp($expr);
        }

        return null;
    }

    /**
     * Computes the result of a binary arithmetic operation on folded operands.
     */
    private static function evaluateBinaryOp(BinaryOp $expr): int|float|null
    {
        $left = self::evaluate($expr->left);
        $right = self::evaluate($expr->right);

        if ($left === null || $right === null) {
            return null;
        }

        $result = match ($expr::class) {
            BinaryOp\Plus::class => $left + $right,
            BinaryOp\Minus::class => $left - $right,
            BinaryOp\Mul::class => $left * $right,
            BinaryOp\Div::class => self::divide($left, $right),
            BinaryOp\Mod::class => self::modulo($left, $right),
            BinaryOp\Pow::class => $left ** $right,
            default => null,
        };

        if ($result === null || ! is_finite((float) $result)) {
            return null;
        }

        return $result;
    }

    /**
     * Safely executes division, guarding against division by zero.
     */
    private static function divide(int|float $left, int|float $right): int|float|null
    {
        if (abs((float) $right) < self::FLOAT_EPSILON) {
            return null;
        }

        return $left / $right;
    }

    /**
     * Safely executes integer modulo arithmetic, guarding against modulo by zero.
     */
    private static function modulo(int|float $left, int|float $right): int|null
    {
        $intRight = (int) $right;
        if ($intRight === 0) {
            return null;
        }

        return (int) $left % $intRight;
    }
}
