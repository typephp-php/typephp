<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\UnaryMinus;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\DNumber;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

describe('StaticTypeEvaluator Comprehensive Unit Tests', function () {
    describe('Safety Invariants', function () {
        test('never eliminates IntersectionTypeNode at AST transform time (defers to runtime)', function () {
            $intersection = new IntersectionTypeNode([
                new IdentifierTypeNode('Countable'),
                new IdentifierTypeNode('ArrayAccess'),
            ]);

            expect(StaticTypeEvaluator::satisfies(new LNumber(1), $intersection))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies(new New_(new Name('ArrayObject')), $intersection))->toBeFalse()
            ;
        });

        test('never eliminates GenericTypeNode for classes to preserve runtime WeakMap registrations', function () {
            $genericObject = new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('Dog')]);
            $newCollection = new New_(new Name('Collection'));

            expect(StaticTypeEvaluator::satisfies($newCollection, $genericObject))->toBeFalse();
        });

        test('permits scalar and array generic containers to be evaluated at AST transform time', function () {
            $intRange = new GenericTypeNode(new IdentifierTypeNode('int'), [
                new ConstTypeNode(new ConstExprIntegerNode('1')),
                new ConstTypeNode(new ConstExprIntegerNode('10')),
            ]);
            expect(StaticTypeEvaluator::satisfies(new LNumber(5), $intRange))->toBeTrue();

            $listType = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('int')]);
            expect(StaticTypeEvaluator::satisfies(new Array_([]), $listType))->toBeTrue();
        });
    });

    describe('Nullable Types (?T)', function () {
        test('evaluates literal null against NullableTypeNode', function () {
            $nullable = new NullableTypeNode(new IdentifierTypeNode('positive-int'));
            $nullExpr = new ConstFetch(new Name('null'));

            expect(StaticTypeEvaluator::satisfies($nullExpr, $nullable))->toBeTrue();
        });

        test('evaluates inner satisfied and violated values against NullableTypeNode', function () {
            $nullable = new NullableTypeNode(new IdentifierTypeNode('positive-int'));

            expect(StaticTypeEvaluator::satisfies(new LNumber(42), $nullable))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new LNumber(0), $nullable))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies(new UnaryMinus(new LNumber(5)), $nullable))->toBeFalse()
            ;
        });
    });

    describe('Union Types (A | B)', function () {
        test('satisfies union if any member branch is statically proven', function () {
            $statusUnion = new UnionTypeNode([
                new ConstTypeNode(new ConstExprStringNode('active', ConstExprStringNode::SINGLE_QUOTED)),
                new ConstTypeNode(new ConstExprStringNode('pending', ConstExprStringNode::SINGLE_QUOTED)),
                new IdentifierTypeNode('null'),
            ]);

            expect(StaticTypeEvaluator::satisfies(new String_('active'), $statusUnion))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('pending'), $statusUnion))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new ConstFetch(new Name('null')), $statusUnion))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('archived'), $statusUnion))->toBeFalse()
            ;
        });

        test('evaluates union of scalar refinements and literals', function () {
            $mixedUnion = new UnionTypeNode([
                new IdentifierTypeNode('positive-int'),
                new IdentifierTypeNode('non-empty-string'),
            ]);

            expect(StaticTypeEvaluator::satisfies(new LNumber(100), $mixedUnion))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('user_1'), $mixedUnion))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new LNumber(-5), $mixedUnion))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies(new String_(''), $mixedUnion))->toBeFalse()
            ;
        });
    });

    describe('Scalars & Expressions Integration', function () {
        test('evaluates integer literals, negative numbers, and ranges', function () {
            expect(StaticTypeEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('positive-int')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new UnaryMinus(new LNumber(10)), new IdentifierTypeNode('negative-int')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new LNumber(0), new IdentifierTypeNode('non-negative-int')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new LNumber(10), new IdentifierTypeNode('non-zero-int')))->toBeTrue()
            ;
        });

        test('evaluates float literals and float refinements', function () {
            expect(StaticTypeEvaluator::satisfies(new DNumber(3.14), new IdentifierTypeNode('float')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new DNumber(3.14), new IdentifierTypeNode('positive-float')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new UnaryMinus(new DNumber(1.5)), new IdentifierTypeNode('negative-float')))->toBeTrue()
            ;
        });

        test('evaluates string literals, empty checks, and casing refinements', function () {
            expect(StaticTypeEvaluator::satisfies(new String_('hello'), new IdentifierTypeNode('non-empty-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('abc'), new IdentifierTypeNode('lowercase-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('ABC'), new IdentifierTypeNode('uppercase-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_('123.45'), new IdentifierTypeNode('numeric-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies(new String_(''), new IdentifierTypeNode('non-empty-string')))->toBeFalse()
            ;
        });

        test('evaluates deterministic string concatenation with non-empty prefix', function () {
            $concat = new Concat(new String_('user_'), new Variable('dynamicId'));

            expect(StaticTypeEvaluator::satisfies($concat, new IdentifierTypeNode('non-empty-string')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($concat, new IdentifierTypeNode('string')))->toBeTrue()
            ;
        });

        test('evaluates booleans and null constants', function () {
            $trueNode = new ConstFetch(new Name('true'));
            $falseNode = new ConstFetch(new Name('false'));
            $nullNode = new ConstFetch(new Name('null'));

            expect(StaticTypeEvaluator::satisfies($trueNode, new IdentifierTypeNode('bool')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($trueNode, new IdentifierTypeNode('true')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($falseNode, new IdentifierTypeNode('bool')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($falseNode, new IdentifierTypeNode('false')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($nullNode, new IdentifierTypeNode('null')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($nullNode, new IdentifierTypeNode('void')))->toBeTrue()
            ;
        });
    });

    describe('Arrays, Lists & Shapes Integration', function () {
        test('evaluates empty arrays against permissible and non-permissible types', function () {
            $empty = new Array_([]);

            expect(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('array')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('list')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new ArrayTypeNode(new IdentifierTypeNode('int'))))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('non-empty-list')))->toBeFalse()
                ->and(StaticTypeEvaluator::satisfies($empty, new IdentifierTypeNode('non-empty-array')))->toBeFalse()
            ;
        });

        test('evaluates sequential lists with all satisfied literal elements', function () {
            $list = new Array_([
                new ArrayItem(new LNumber(1)),
                new ArrayItem(new LNumber(2)),
                new ArrayItem(new LNumber(3)),
            ]);
            $listType = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);

            expect(StaticTypeEvaluator::satisfies($list, $listType))->toBeTrue();
        });

        test('rejects sequential list when an element violates contract', function () {
            $list = new Array_([
                new ArrayItem(new LNumber(1)),
                new ArrayItem(new LNumber(-5)),
            ]);
            $listType = new GenericTypeNode(new IdentifierTypeNode('list'), [new IdentifierTypeNode('positive-int')]);

            expect(StaticTypeEvaluator::satisfies($list, $listType))->toBeFalse();
        });

        test('evaluates sealed and unsealed array shapes', function () {
            $shapeData = new Array_([
                new ArrayItem(new LNumber(1), new String_('id')),
                new ArrayItem(new String_('Alice'), new String_('name')),
            ]);

            $sealedShape = ArrayShapeNode::createSealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
                new ArrayShapeItemNode(new ConstExprStringNode('name', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('non-empty-string')),
            ]);

            expect(StaticTypeEvaluator::satisfies($shapeData, $sealedShape))->toBeTrue();

            $unsealedShape = ArrayShapeNode::createUnsealed([
                new ArrayShapeItemNode(new ConstExprStringNode('id', ConstExprStringNode::SINGLE_QUOTED), false, new IdentifierTypeNode('positive-int')),
            ], new ArrayShapeUnsealedTypeNode(new IdentifierTypeNode('string'), new IdentifierTypeNode('string')));

            expect(StaticTypeEvaluator::satisfies($shapeData, $unsealedShape))->toBeTrue();
        });
    });

    describe('Constants, Enums & Bitmasks Integration', function () {
        test('evaluates class constant exact matches and wildcards', function () {
            $constFetch = new ClassConstFetch(new Name('self'), new Identifier('STATUS_PAID'));
            $exactType = new ConstTypeNode(new ConstFetchNode('self', 'STATUS_PAID'));
            $wildcard = new ConstTypeNode(new ConstFetchNode('self', 'STATUS_*'));

            expect(StaticTypeEvaluator::satisfies($constFetch, $exactType))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($constFetch, $wildcard))->toBeTrue()
            ;
        });

        test('evaluates enum case fetches against enum base type and case wildcards', function () {
            $enumFetch = new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active'));

            expect(StaticTypeEvaluator::satisfies($enumFetch, new IdentifierTypeNode('StatusEnum')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($enumFetch, new ConstTypeNode(new ConstFetchNode('StatusEnum', '*'))))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($enumFetch, new ConstTypeNode(new ConstFetchNode('StatusEnum', 'ACT*'))))->toBeTrue()
            ;
        });

        test('evaluates bitmask zero literal and bitwise OR flags', function () {
            $maskType = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
                new ConstTypeNode(new ConstFetchNode('self', 'FLAG_*')),
            ]);

            $flagA = new ClassConstFetch(new Name('self'), new Identifier('FLAG_READ'));
            $flagB = new ClassConstFetch(new Name('self'), new Identifier('FLAG_WRITE'));
            $bitwiseOr = new BitwiseOr($flagA, $flagB);

            expect(StaticTypeEvaluator::satisfies(new LNumber(0), $maskType))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($flagA, $maskType))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($bitwiseOr, $maskType))->toBeTrue()
            ;
        });

        test('evaluates enum backing property access against value-of<Enum>', function () {
            $propFetch = new PropertyFetch(
                new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active')),
                new Identifier('value')
            );
            $valueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [new IdentifierTypeNode('StatusEnum')]);

            expect(StaticTypeEvaluator::satisfies($propFetch, $valueOf))->toBeTrue();
        });
    });

    describe('Objects & Identity Returns Integration', function () {
        test('evaluates exact non-generic class instantiation (new User())', function () {
            $newUser = new New_(new Name('User'));

            expect(StaticTypeEvaluator::satisfies($newUser, new IdentifierTypeNode('User')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($newUser, new IdentifierTypeNode('Product')))->toBeFalse()
            ;
        });

        test('evaluates $this against $this and self', function () {
            $thisVar = new Variable('this');

            expect(StaticTypeEvaluator::satisfies($thisVar, new ThisTypeNode()))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($thisVar, new IdentifierTypeNode('$this')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($thisVar, new IdentifierTypeNode('self')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($thisVar, new IdentifierTypeNode('string')))->toBeFalse()
            ;
        });

        test('evaluates new static() against static', function () {
            $newStatic = new New_(new Name('static'));

            expect(StaticTypeEvaluator::satisfies($newStatic, new IdentifierTypeNode('static')))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($newStatic, new IdentifierTypeNode('self')))->toBeFalse()
            ;
        });
    });

    describe('Dynamic Expressions (Must Always Return False / Defer to Runtime)', function () {
        test('defers variable expressions', function () {
            expect(StaticTypeEvaluator::satisfies(new Variable('unprovenVar'), new IdentifierTypeNode('int')))->toBeFalse();
        });

        test('defers method calls', function () {
            $methodCall = new MethodCall(new Variable('this'), new Identifier('getScore'));
            expect(StaticTypeEvaluator::satisfies($methodCall, new IdentifierTypeNode('positive-int')))->toBeFalse();
        });

        test('defers function calls', function () {
            $funcCall = new FuncCall(new Name('rand'));
            expect(StaticTypeEvaluator::satisfies($funcCall, new IdentifierTypeNode('int')))->toBeFalse();
        });
    });
});
