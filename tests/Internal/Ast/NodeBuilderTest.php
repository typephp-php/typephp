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

    test('createMemberAssignCheckCall creates FuncCall node for RuntimeTypeChecker::checkMemberAssign', function () {
        $root = new Node\Expr\Variable('stats');
        $chain = new Node\Expr\Array_([
            new Node\Expr\ArrayItem(
                new Node\Expr\Array_([
                    new Node\Expr\ArrayItem(new Node\Scalar\String_('dim')),
                    new Node\Expr\ArrayItem(new Node\Scalar\String_('count')),
                ])
            ),
        ]);
        $val = new Node\Scalar\String_('test');

        $call = NodeBuilder::createMemberAssignCheckCall($root, $chain, $val, 'array{count: int}', 'stats');

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('\TypePHP\Internal\RuntimeTypeChecker::checkMemberAssign')
            ->and($call->args)->toHaveCount(6)
        ;

        $caller = new Node\Scalar\String_('App\Service::run');
        $thisArg = new Node\Expr\Variable('this');
        $callWithCaller = NodeBuilder::createMemberAssignCheckCall($root, $chain, $val, 'array{count: int}', 'stats', $caller, $thisArg);

        expect($callWithCaller->args)->toHaveCount(8);
    });
});
