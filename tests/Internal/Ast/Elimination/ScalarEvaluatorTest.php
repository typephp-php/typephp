<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Ast\Elimination\Evaluator\ScalarEvaluator;

describe('ScalarEvaluator Unit Tests', function () {
    describe('Integer Literals & Refinements', function () {
        test('evaluates positive-int', function () {
            expect(ScalarEvaluator::satisfies(new LNumber(42), new IdentifierTypeNode('positive-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(0), new IdentifierTypeNode('positive-int')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(5)), new IdentifierTypeNode('positive-int')))->toBeFalse();
        });

        test('evaluates negative-int with UnaryMinus', function () {
            expect(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(10)), new IdentifierTypeNode('negative-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('negative-int')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new LNumber(0), new IdentifierTypeNode('negative-int')))->toBeFalse();
        });

        test('evaluates non-negative-int and non-positive-int', function () {
            expect(ScalarEvaluator::satisfies(new LNumber(0), new IdentifierTypeNode('non-negative-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('non-negative-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(1)), new IdentifierTypeNode('non-negative-int')))->toBeFalse();

            expect(ScalarEvaluator::satisfies(new LNumber(0), new IdentifierTypeNode('non-positive-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(10)), new IdentifierTypeNode('non-positive-int')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(1), new IdentifierTypeNode('non-positive-int')))->toBeFalse();
        });

        test('evaluates int<min, max> bounds', function () {
            $range = new GenericTypeNode(new IdentifierTypeNode('int'), [
                new ConstTypeNode(new ConstExprIntegerNode('1')),
                new ConstTypeNode(new ConstExprIntegerNode('100')),
            ]);

            expect(ScalarEvaluator::satisfies(new LNumber(50), $range))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(1), $range))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(100), $range))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(0), $range))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new LNumber(150), $range))->toBeFalse();
        });

        test('evaluates int-mask literal values', function () {
            $mask = new GenericTypeNode(new IdentifierTypeNode('int-mask'), [
                new ConstTypeNode(new ConstExprIntegerNode('1')),
                new ConstTypeNode(new ConstExprIntegerNode('2')),
                new ConstTypeNode(new ConstExprIntegerNode('4')),
            ]);

            expect(ScalarEvaluator::satisfies(new LNumber(0), $mask))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(3), $mask))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(7), $mask))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(8), $mask))->toBeFalse();
        });
    });

    describe('Float Literals & Integer Coercion', function () {
        test('evaluates float and positive-float', function () {
            expect(ScalarEvaluator::satisfies(new DNumber(3.14), new IdentifierTypeNode('float')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new DNumber(3.14), new IdentifierTypeNode('positive-float')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new DNumber(2.5)), new IdentifierTypeNode('positive-float')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new DNumber(2.5)), new IdentifierTypeNode('negative-float')))->toBeTrue();
        });

        test('evaluates direct float literal comparison with epsilon tolerance', function () {
            $constType = new ConstTypeNode(new ConstExprFloatNode('12.34'));

            expect(ScalarEvaluator::satisfies(new DNumber(12.34), $constType))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new DNumber(12.35), $constType))->toBeFalse();
        });

        test('evaluates integer coercion for float and float literal contracts', function () {
            $floatLiteralType = new ConstTypeNode(new ConstExprFloatNode('10.0'));

            expect(ScalarEvaluator::satisfies(new LNumber(10), $floatLiteralType))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(11), $floatLiteralType))->toBeFalse();

            expect(ScalarEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('float')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('positive-float')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(5)), new IdentifierTypeNode('negative-float')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new UnaryMinus(new LNumber(5)), new IdentifierTypeNode('positive-float')))->toBeFalse();
        });
    });

    describe('String Literals & Concatenations', function () {
        test('evaluates string and non-empty-string', function () {
            expect(ScalarEvaluator::satisfies(new String_('hello'), new IdentifierTypeNode('string')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new String_('hello'), new IdentifierTypeNode('non-empty-string')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new String_(''), new IdentifierTypeNode('non-empty-string')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new String_(''), new IdentifierTypeNode('string')))->toBeTrue();
        });

        test('evaluates lowercase-string and uppercase-string', function () {
            expect(ScalarEvaluator::satisfies(new String_('abc'), new IdentifierTypeNode('lowercase-string')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new String_('Abc'), new IdentifierTypeNode('lowercase-string')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies(new String_('ABC'), new IdentifierTypeNode('uppercase-string')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new String_('aBC'), new IdentifierTypeNode('uppercase-string')))->toBeFalse();
        });

        test('evaluates string concatenation with non-empty prefix guarantees non-empty-string', function () {
            $concat = new Concat(new String_('user_'), new LNumber(42));

            expect(ScalarEvaluator::satisfies($concat, new IdentifierTypeNode('non-empty-string')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($concat, new IdentifierTypeNode('string')))->toBeTrue();
        });

        test('evaluates string literal match against ConstTypeNode', function () {
            $constType = new ConstTypeNode(new ConstExprStringNode('active', ConstExprStringNode::SINGLE_QUOTED));

            expect(ScalarEvaluator::satisfies(new String_('active'), $constType))->toBeTrue()
                ->and(ScalarEvaluator::satisfies(new String_('pending'), $constType))->toBeFalse();
        });
    });

    describe('Constants (true, false, null)', function () {
        test('evaluates true and false', function () {
            $trueNode = new ConstFetch(new Name('true'));
            $falseNode = new ConstFetch(new Name('false'));

            expect(ScalarEvaluator::satisfies($trueNode, new IdentifierTypeNode('bool')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($trueNode, new IdentifierTypeNode('true')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($trueNode, new IdentifierTypeNode('false')))->toBeFalse()
                ->and(ScalarEvaluator::satisfies($falseNode, new IdentifierTypeNode('bool')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($falseNode, new IdentifierTypeNode('false')))->toBeTrue();
        });

        test('evaluates null and void', function () {
            $nullNode = new ConstFetch(new Name('null'));

            expect(ScalarEvaluator::satisfies($nullNode, new IdentifierTypeNode('null')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($nullNode, new IdentifierTypeNode('void')))->toBeTrue()
                ->and(ScalarEvaluator::satisfies($nullNode, new IdentifierTypeNode('int')))->toBeFalse();
        });
    });
});