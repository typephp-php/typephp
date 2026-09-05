<?php

declare(strict_types=1);

use PhpParser\Node;
use TypePHP\Internal\Ast\NodeBuilder;

describe('NodeBuilder Unit Tests', function () {
    test('createPropertyCheckCall creates FuncCall node for RuntimeTypeChecker::checkProperty', function () {
        $val = new Node\Expr\Variable('val');
        $obj = new Node\Expr\Variable('this');

        $call = NodeBuilder::createPropertyCheckCall($val, $obj, 'propName');

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('\TypePHP\Internal\RuntimeTypeChecker::checkProperty')
        ;
    });

    test('createVariableCheckCall creates FuncCall node for RuntimeTypeChecker::checkVariable', function () {
        $val = new Node\Expr\Variable('val');

        $call = NodeBuilder::createVariableCheckCall($val, 'positive-int', 'age');

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('\TypePHP\Internal\RuntimeTypeChecker::checkVariable')
        ;
    });

    test('createTernaryThrowExpr wraps FuncCall in a Ternary throw expression', function () {
        $val = new Node\Expr\Variable('val');
        $checkCall = NodeBuilder::createVariableCheckCall($val, 'positive-int', 'age');

        $ternary = NodeBuilder::createTernaryThrowExpr($checkCall);

        expect($ternary)->toBeInstanceOf(Node\Expr\Ternary::class)
            ->and($ternary->if)->toBeInstanceOf(Node\Expr\Throw_::class)
        ;
    });
});
