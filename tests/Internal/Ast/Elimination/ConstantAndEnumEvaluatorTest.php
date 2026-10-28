<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\BinaryOp\BitwiseOr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

describe('ConstantAndEnumEvaluator Unit Tests', function () {
    describe('Class Constants & Wildcards', function () {
        test('evaluates exact class constant match', function () {
            $fetch = new ClassConstFetch(new Name('self'), new Identifier('STATUS_ACTIVE'));
            $type = new ConstTypeNode(new ConstFetchNode('self', 'STATUS_ACTIVE'));

            expect(StaticTypeEvaluator::satisfies($fetch, $type))->toBeTrue();
        });

        test('evaluates wildcard constant prefix (self::STATUS_*)', function () {
            $fetch = new ClassConstFetch(new Name('self'), new Identifier('STATUS_PAID'));
            $wildcard = new ConstTypeNode(new ConstFetchNode('self', 'STATUS_*'));

            expect(StaticTypeEvaluator::satisfies($fetch, $wildcard))->toBeTrue();

            $unmatched = new ClassConstFetch(new Name('self'), new Identifier('ROLE_ADMIN'));
            expect(StaticTypeEvaluator::satisfies($unmatched, $wildcard))->toBeFalse();
        });

        test('evaluates int-mask-of wildcard constants and bitwise OR combinations', function () {
            $flagA = new ClassConstFetch(new Name('self'), new Identifier('FLAG_READ'));
            $flagB = new ClassConstFetch(new Name('self'), new Identifier('FLAG_WRITE'));
            $orExpr = new BitwiseOr($flagA, $flagB);

            $maskOf = new GenericTypeNode(new IdentifierTypeNode('int-mask-of'), [
                new ConstTypeNode(new ConstFetchNode('self', 'FLAG_*')),
            ]);

            expect(StaticTypeEvaluator::satisfies($flagA, $maskOf))->toBeTrue()
                ->and(StaticTypeEvaluator::satisfies($orExpr, $maskOf))->toBeTrue()
            ;

            $badFlag = new ClassConstFetch(new Name('self'), new Identifier('OTHER_FLAG'));
            $badOrExpr = new BitwiseOr($flagA, $badFlag);
            expect(StaticTypeEvaluator::satisfies($badOrExpr, $maskOf))->toBeFalse();
        });
    });

    describe('Enums and Backing Values', function () {
        test('evaluates enum case fetch against enum base type', function () {
            $fetch = new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active'));
            $type = new IdentifierTypeNode('StatusEnum');

            expect(StaticTypeEvaluator::satisfies($fetch, $type))->toBeTrue();
        });

        test('evaluates enum case fetch against enum wildcard (StatusEnum::*)', function () {
            $fetch = new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active'));
            $wildcard = new ConstTypeNode(new ConstFetchNode('StatusEnum', '*'));

            expect(StaticTypeEvaluator::satisfies($fetch, $wildcard))->toBeTrue();
        });

        test('evaluates enum case fetch against prefix wildcard (StatusEnum::ACT*)', function () {
            $fetch = new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active'));
            $prefix = new ConstTypeNode(new ConstFetchNode('StatusEnum', 'ACT*'));

            expect(StaticTypeEvaluator::satisfies($fetch, $prefix))->toBeTrue();
        });

        test('evaluates enum backing property access against value-of<Enum>', function () {
            $propFetch = new PropertyFetch(
                new ClassConstFetch(new Name('StatusEnum'), new Identifier('Active')),
                new Identifier('value')
            );

            $valueOf = new GenericTypeNode(new IdentifierTypeNode('value-of'), [
                new IdentifierTypeNode('StatusEnum'),
            ]);

            expect(StaticTypeEvaluator::satisfies($propFetch, $valueOf))->toBeTrue();
        });
    });
});
