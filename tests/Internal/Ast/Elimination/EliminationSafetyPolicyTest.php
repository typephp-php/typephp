<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Ast\Elimination;

use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Ast\Elimination\EliminationSafetyPolicy;

describe('EliminationSafetyPolicy Unit Tests', function () {
    test('returns false when return type is null', function () {
        $fn = new Function_('test');
        expect(EliminationSafetyPolicy::isReturnEliminationSafe($fn, null, null))->toBeFalse();
    });

    test('returns false when return type is a GenericTypeNode (WeakMap side effect)', function () {
        $fn = new Function_('test');
        $generic = new GenericTypeNode(new IdentifierTypeNode('Collection'), [new IdentifierTypeNode('Dog')]);

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($fn, null, $generic))->toBeFalse();
    });

    test('returns true for standalone functions (zero inheritance risk)', function () {
        $fn = new Function_('test');
        $type = new IdentifierTypeNode('positive-int');

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($fn, null, $type))->toBeTrue();
    });

    test('returns true for closures and arrow functions (zero inheritance risk)', function () {
        $closure = new Closure();
        $arrow = new ArrowFunction(['expr' => new String_('ok')]);
        $type = new IdentifierTypeNode('string');

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($closure, null, $type))->toBeTrue()
            ->and(EliminationSafetyPolicy::isReturnEliminationSafe($arrow, null, $type))->toBeTrue()
        ;
    });

    test('returns true for private class methods regardless of inheritance', function () {
        $method = new ClassMethod('secret', [
            'flags' => Class_::MODIFIER_PRIVATE,
        ]);
        $context = ['hasRealInheritance' => true];
        $type = new IdentifierTypeNode('int');

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($method, $context, $type))->toBeTrue();
    });

    test('returns true for public methods in classes without inheritance (hasRealInheritance === false)', function () {
        $method = new ClassMethod('render', [
            'flags' => Class_::MODIFIER_PUBLIC,
        ]);
        $context = ['hasRealInheritance' => false];
        $type = new IdentifierTypeNode('string');

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($method, $context, $type))->toBeTrue();
    });

    test('returns false for public methods in classes with inheritance (preserves LSP)', function () {
        $method = new ClassMethod('render', [
            'flags' => Class_::MODIFIER_PUBLIC,
        ]);
        $context = ['hasRealInheritance' => true];
        $type = new IdentifierTypeNode('string');

        expect(EliminationSafetyPolicy::isReturnEliminationSafe($method, $context, $type))->toBeFalse();
    });
});
