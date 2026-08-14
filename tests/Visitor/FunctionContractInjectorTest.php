<?php

declare(strict_types=1);

use PhpParser\Node;
use TypePHP\Internal\Visitor\FunctionContractInjector;

describe('FunctionContractInjector Unit Tests', function () {
    test('injects setupScope and return check into function with docblocks', function () {
        $doc = new PhpParser\Comment\Doc('/** @param positive-int $id @return non-empty-string */');

        $fn = new Node\Stmt\Function_('testUser', [
            'params' => [
                new Node\Param(new Node\Expr\Variable('id'), null, new Node\Identifier('int')),
            ],
            'stmts' => [
                new Node\Stmt\Return_(new Node\Scalar\String_('alice')),
            ],
        ], [
            'comments' => [$doc],
        ]);

        FunctionContractInjector::inject($fn);

        expect($fn->stmts)->not()->toBeEmpty();

        $firstStmt = $fn->stmts[0];
        expect($firstStmt)->toBeInstanceOf(Node\Stmt\If_::class)
            ->and($firstStmt->getAttribute('typephp_injected'))->toBeTrue()
        ;
    });

    test('injects wrapCallable and wrapIterable for parameters with callable or iterable docblocks', function () {
        $doc = new PhpParser\Comment\Doc('/** @param callable(int): string $cb @param iterable<string> $items */');

        $fn = new Node\Stmt\Function_('processData', [
            'params' => [
                new Node\Param(new Node\Expr\Variable('cb')),
                new Node\Param(new Node\Expr\Variable('items')),
            ],
            'stmts' => [],
        ], [
            'comments' => [$doc],
        ]);

        FunctionContractInjector::inject($fn);

        // Statement 0 is setupScope, Statement 1 is wrapCallable, Statement 2 is wrapIterable
        expect(\count($fn->stmts))->toBeGreaterThanOrEqual(3)
            ->and($fn->stmts[1]->getAttribute('typephp_injected'))->toBeTrue()
            ->and($fn->stmts[2]->getAttribute('typephp_injected'))->toBeTrue()
        ;
    });

    test('does not inject return checks into magic lifecycle methods like constructors', function () {
        $fn = new Node\Stmt\ClassMethod('__construct', [
            'params' => [
                new Node\Param(new Node\Expr\Variable('id')),
            ],
            'stmts' => [],
        ]);

        FunctionContractInjector::inject($fn);

        // Should have param check (setupScope, wrapCallable, wrapIterable) but NO return check
        $hasReturn = false;
        foreach ($fn->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\Return_) {
                $hasReturn = true;
            }
        }

        expect($hasReturn)->toBeFalse();
    });
});
