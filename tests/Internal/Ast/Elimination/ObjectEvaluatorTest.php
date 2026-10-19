<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;

describe('ObjectEvaluator Unit Tests', function () {
    test('never eliminates generic object contracts (preserves WeakMap registration)', function () {
        $newExpr = new New_(new Name('Collection'));
        $generic = new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('Dog')]);

        expect(StaticTypeEvaluator::satisfies($newExpr, $generic))->toBeFalse();
    });

    test('evaluates return $this against @return $this and @return self', function () {
        $thisExpr = new Variable('this');

        expect(StaticTypeEvaluator::satisfies($thisExpr, new ThisTypeNode()))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($thisExpr, new IdentifierTypeNode('$this')))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($thisExpr, new IdentifierTypeNode('self')))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($thisExpr, new IdentifierTypeNode('string')))->toBeFalse()
        ;
    });

    test('evaluates return new static() against @return static', function () {
        $newStatic = new New_(new Name('static'));

        expect(StaticTypeEvaluator::satisfies($newStatic, new IdentifierTypeNode('static')))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($newStatic, new IdentifierTypeNode('self')))->toBeFalse()
        ;
    });

    test('evaluates return new self() against @return self', function () {
        $newSelf = new New_(new Name('self'));

        expect(StaticTypeEvaluator::satisfies($newSelf, new IdentifierTypeNode('self')))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($newSelf, new IdentifierTypeNode('static')))->toBeFalse()
        ;
    });

    test('evaluates return new ClassName() against non-generic @return ClassName', function () {
        $newUser = new New_(new Name('User'));

        expect(StaticTypeEvaluator::satisfies($newUser, new IdentifierTypeNode('User')))->toBeTrue()
            ->and(StaticTypeEvaluator::satisfies($newUser, new IdentifierTypeNode('Product')))->toBeFalse()
        ;
    });
});
