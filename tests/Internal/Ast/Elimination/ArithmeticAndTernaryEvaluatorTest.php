<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\BinaryOp\Div;
use PhpParser\Node\Expr\BinaryOp\Minus;
use PhpParser\Node\Expr\BinaryOp\Mod;
use PhpParser\Node\Expr\BinaryOp\Mul;
use PhpParser\Node\Expr\BinaryOp\Plus;
use PhpParser\Node\Expr\BinaryOp\Pow;
use PhpParser\Node\Expr\Ternary;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\Encapsed;
use PhpParser\Node\Scalar\EncapsedStringPart;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\Evaluator\ArithmeticEvaluator;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

describe('ArithmeticEvaluator, Ternary Confluence & Interpolation Unit Tests', function () {
    describe('Constant Arithmetic Folding', function () {
        test('evaluates addition (30 + 15 = 45) against positive-int and range', function () {
            $expr = new Plus(new LNumber(30), new LNumber(15));

            expect(ArithmeticEvaluator::evaluate($expr))->toBe(45);
            expect(StaticTypeEvaluator::satisfies($expr, new IdentifierTypeNode('positive-int')))->toBeTrue();

            $range = new GenericTypeNode(new IdentifierTypeNode('int'), [
                new IdentifierTypeNode('1'),
                new IdentifierTypeNode('50'),
            ]);
            expect(StaticTypeEvaluator::satisfies($expr, $range))->toBeTrue();
        });

        test('evaluates nested multiplication (60 * 60 * 24 = 86400)', function () {
            $nested = new Mul(new Mul(new LNumber(60), new LNumber(60)), new LNumber(24));

            expect(ArithmeticEvaluator::evaluate($nested))->toBe(86400);
            expect(StaticTypeEvaluator::satisfies($nested, new IdentifierTypeNode('positive-int')))->toBeTrue();
        });

        test('evaluates subtraction producing negative integer', function () {
            $expr = new Minus(new LNumber(10), new LNumber(25)); // -15

            expect(ArithmeticEvaluator::evaluate($expr))->toBe(-15);
            expect(StaticTypeEvaluator::satisfies($expr, new IdentifierTypeNode('negative-int')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($expr, new IdentifierTypeNode('positive-int')))->toBeFalse();
        });

        test('evaluates division and integer modulo', function () {
            $div = new Div(new LNumber(100), new LNumber(4)); // 25
            expect(ArithmeticEvaluator::evaluate($div))->toBe(25);
            expect(StaticTypeEvaluator::satisfies($div, new IdentifierTypeNode('positive-int')))->toBeTrue();

            $mod = new Mod(new LNumber(17), new LNumber(5)); // 2
            expect(ArithmeticEvaluator::evaluate($mod))->toBe(2);
            expect(StaticTypeEvaluator::satisfies($mod, new IdentifierTypeNode('positive-int')))->toBeTrue();
        });

        test('evaluates exponentiation (2 ** 8 = 256)', function () {
            $pow = new Pow(new LNumber(2), new LNumber(8));

            expect(ArithmeticEvaluator::evaluate($pow))->toBe(256);
            expect(StaticTypeEvaluator::satisfies($pow, new IdentifierTypeNode('positive-int')))->toBeTrue();
        });

        test('handles IEEE 754 float arithmetic with epsilon tolerance (0.1 + 0.2 vs 0.3)', function () {
            $floatAdd = new Plus(new DNumber(0.1), new DNumber(0.2));

            $floatType = new ConstTypeNode(new ConstExprFloatNode('0.3'));
            expect(StaticTypeEvaluator::satisfies($floatAdd, $floatType))->toBeTrue();
        });

        test('aborts constant folding and defers to runtime on division and modulo by zero', function () {
            $divZero = new Div(new LNumber(100), new LNumber(0));
            expect(ArithmeticEvaluator::evaluate($divZero))->toBeNull();
            expect(StaticTypeEvaluator::satisfies($divZero, new IdentifierTypeNode('int')))->toBeFalse();

            $modZero = new Mod(new LNumber(100), new LNumber(0));
            expect(ArithmeticEvaluator::evaluate($modZero))->toBeNull();
            expect(StaticTypeEvaluator::satisfies($modZero, new IdentifierTypeNode('int')))->toBeFalse();
        });
    });

    describe('Ternary Branch Confluence ($cond ? A : B)', function () {
        test('satisfies contract when both ternary branches are proven valid', function () {
            $ternary = new Ternary(
                new Variable('isAdmin'),
                new String_('admin'),
                new String_('user')
            );

            $union = new UnionTypeNode([
                new ConstTypeNode(new ConstExprStringNode('admin', ConstExprStringNode::SINGLE_QUOTED)),
                new ConstTypeNode(new ConstExprStringNode('user', ConstExprStringNode::SINGLE_QUOTED)),
            ]);

            expect(StaticTypeEvaluator::satisfies($ternary, $union))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($ternary, new IdentifierTypeNode('non-empty-string')))->toBeTrue();
        });

        test('rejects ternary when one branch violates the contract', function () {
            $ternary = new Ternary(
                new Variable('isAdmin'),
                new String_('admin'),
                new String_('guest') // Not in union!
            );

            $union = new UnionTypeNode([
                new ConstTypeNode(new ConstExprStringNode('admin', ConstExprStringNode::SINGLE_QUOTED)),
                new ConstTypeNode(new ConstExprStringNode('user', ConstExprStringNode::SINGLE_QUOTED)),
            ]);

            expect(StaticTypeEvaluator::satisfies($ternary, $union))->toBeFalse();
        });

        test('defers shorthand elvis operator ($a ?: $b) to runtime', function () {
            $elvis = new Ternary(
                new Variable('a'),
                null,
                new String_('fallback')
            );

            expect(StaticTypeEvaluator::satisfies($elvis, new IdentifierTypeNode('string')))->toBeFalse();
        });
    });

    describe('String Interpolation (Encapsed)', function () {
        test('guarantees non-empty-string when template contains a non-empty string part', function () {
            $interpolated = new Encapsed([
                new EncapsedStringPart('user_'),
                new Variable('id'),
            ]);

            expect(StaticTypeEvaluator::satisfies($interpolated, new IdentifierTypeNode('non-empty-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($interpolated, new IdentifierTypeNode('string')))->toBeTrue();
        });

        test('does not guarantee non-empty-string when all parts are variables or empty', function () {
            $bareVarInterpolation = new Encapsed([
                new Variable('prefix'),
                new Variable('suffix'),
            ]);

            expect(StaticTypeEvaluator::satisfies($bareVarInterpolation, new IdentifierTypeNode('non-empty-string')))->toBeFalse();
        });
    });
});