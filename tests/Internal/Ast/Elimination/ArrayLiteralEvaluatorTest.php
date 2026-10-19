<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\Evaluator\ArrayLiteralEvaluator;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

describe('ArrayLiteralEvaluator Unit Tests', function () {
    describe('Empty Array Literals ([])', function () {
        test('satisfies array, list, iterable, and list<T>', function () {
            $empty = new Array_([]);

            expect(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('array')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('list')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('iterable')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new ArrayTypeNode(new IdentifierTypeNode('int'))))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')])))->toBeTrue()
            ;
        });

        test('rejects non-empty-list and non-empty-array on empty array', function () {
            $empty = new Array_([]);

            expect(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('non-empty-list')))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('non-empty-array')))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($empty, new GenericTypeNode(new IdentifierTypeNode('non-empty-list'), [new IdentifierTypeNode('int')])))->toBeFalse()
            ;
        });

        test('satisfies shape with all optional keys, but rejects shape with required keys', function () {
            $empty = new Array_([]);

            $allOptionalShape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), true, new IdentifierTypeNode('int')),
            ]);

            $requiredShape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('int')),
            ]);

            expect(ArrayLiteralEvaluator::satisfiesEmptyArray($allOptionalShape))->toBeTrue()
                ->and(ArrayLiteralEvaluator::satisfiesEmptyArray($requiredShape))->toBeFalse()
            ;
        });
    });

    describe('Sequential Lists ([1, 2, 3])', function () {
        test('evaluates valid sequential list of positive-int', function () {
            $list = new Array_([
                new ArrayItem(new LNumber(10)),
                new ArrayItem(new LNumber(20)),
                new ArrayItem(new LNumber(30)),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);

            expect(StaticTypeEvaluator::satisfies($list, $type))->toBeTrue();
        });

        test('rejects list containing item violating element contract', function () {
            $list = new Array_([
                new ArrayItem(new LNumber(10)),
                new ArrayItem(new LNumber(-5)),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);

            expect(StaticTypeEvaluator::satisfies($list, $type))->toBeFalse();
        });

        test('rejects list with associative or non-sequential integer keys', function () {
            $assoc = new Array_([
                new ArrayItem(new LNumber(10), new String_('key')),
            ]);

            $nonSequential = new Array_([
                new ArrayItem(new LNumber(10), new LNumber(1)),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('int')]);

            expect(StaticTypeEvaluator::satisfies($assoc, $type))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($nonSequential, $type))->toBeFalse()
            ;
        });

        test('rejects list with unpack or dynamic elements', function () {
            $list = new Array_([
                new ArrayItem(new LNumber(10)),
                new ArrayItem(new Variable('dynamicVar')),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('int')]);

            expect(StaticTypeEvaluator::satisfies($list, $type))->toBeFalse();
        });
    });

    describe('Sealed and Unsealed Array Shapes', function () {
        test('evaluates valid sealed array shape with literal values', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1), new String_('id')),
                new ArrayItem(new String_('Alice'), new String_('name')),
            ]);

            $shape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
                new ArrayShapeItemNode(new ConstExprStringNode('name', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('non-empty-string')),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $shape))->toBeTrue();
        });

        test('rejects sealed array shape when required key is missing', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1), new String_('id')),
            ]);

            $shape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
                new ArrayShapeItemNode(new ConstExprStringNode('name', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('non-empty-string')),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $shape))->toBeFalse();
        });

        test('rejects sealed array shape when extra key is present', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1), new String_('id')),
                new ArrayItem(new String_('forbidden'), new String_('extra')),
            ]);

            $shape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $shape))->toBeFalse();
        });

        test('evaluates unsealed array shape with valid extra string values', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1), new String_('id')),
                new ArrayItem(new String_('extra_info'), new String_('tag')),
            ]);

            $unsealed = new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('string'), new IdentifierTypeNode('string'));

            $shape = ArrayShapeNode::createUnsealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
            ], $unsealed);

            expect(StaticTypeEvaluator::satisfies($array, $shape))->toBeTrue();
        });
    });

    describe('Arrays and Lists Containing Object Instantiations', function () {
        test('evaluates valid sequential list of object instantiations (list<User>)', function () {
            $array = new Array_([
                new ArrayItem(new New_(new Name('User'))),
                new ArrayItem(new New_(new Name('User'))),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('User')]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });

        test('evaluates valid T[] array of object instantiations (User[])', function () {
            $array = new Array_([
                new ArrayItem(new New_(new Name('User'))),
            ]);

            $type = new ArrayTypeNode(new IdentifierTypeNode('User'));

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });

        test('rejects list of objects when an item is the wrong class', function () {
            $array = new Array_([
                new ArrayItem(new New_(new Name('User'))),
                new ArrayItem(new New_(new Name('Product'))),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('User')]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeFalse();
        });

        test('evaluates array shape containing object instantiations', function () {
            $array = new Array_([
                new ArrayItem(new New_(new Name('User')), new String_('author')),
                new ArrayItem(new LNumber(1), new String_('id')),
            ]);

            $shape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('author', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('User')),
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $shape))->toBeTrue();
        });
    });

    describe('Homogeneous and Heterogeneous Unions in Arrays (list<int|string>)', function () {
        test('evaluates heterogeneous scalar list (mix of ints and strings)', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1)),
                new ArrayItem(new String_('two')),
                new ArrayItem(new LNumber(3)),
                new ArrayItem(new String_('four')),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('string')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });

        test('evaluates homogeneous scalar list against a union contract', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(10)),
                new ArrayItem(new LNumber(20)),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('string')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });

        test('rejects heterogeneous list when an element violates all union members', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(1)),
                new ArrayItem(new String_('two')),
                new ArrayItem(new ConstFetch(new Name('false'))),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('string')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeFalse();
        });

        test('evaluates heterogeneous list of constructed objects (list<User|Product>)', function () {
            $array = new Array_([
                new ArrayItem(new New_(new Name('User'))),
                new ArrayItem(new New_(new Name('Product'))),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('list'), [
                new UnionTypeNode([new IdentifierTypeNode('User'), new IdentifierTypeNode('Product')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });

        test('evaluates key-value array with union values (array<string, int|bool>)', function () {
            $array = new Array_([
                new ArrayItem(new LNumber(100), new String_('port')),
                new ArrayItem(new ConstFetch(new Name('true')), new String_('enabled')),
            ]);

            $type = new GenericTypeNode(new IdentifierTypeNode('array'), [
                new IdentifierTypeNode('string'),
                new UnionTypeNode([new IdentifierTypeNode('int'), new IdentifierTypeNode('bool')]),
            ]);

            expect(StaticTypeEvaluator::satisfies($array, $type))->toBeTrue();
        });
    });
});
