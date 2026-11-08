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

    test('createPropertyMemberAssignCheckCall creates FuncCall node for RuntimeTypeChecker::checkPropertyMemberAssign', function () {
        $obj = new Node\Expr\Variable('box');
        $chain = new Node\Expr\Array_([
            new Node\Expr\ArrayItem(
                new Node\Expr\Array_([
                    new Node\Expr\ArrayItem(new Node\Scalar\String_('dim')),
                    new Node\Expr\ArrayItem(new Node\Expr\ConstFetch(new Node\Name('null'))),
                ])
            ),
        ]);
        $val = new Node\Scalar\LNumber(42);

        $call = NodeBuilder::createPropertyMemberAssignCheckCall($obj, 'numbers', $chain, $val);

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('\TypePHP\Internal\RuntimeTypeChecker::checkPropertyMemberAssign')
            ->and($call->args)->toHaveCount(5)
        ;
    });

    test('createConstantCheckCall creates FuncCall node for RuntimeTypeChecker::checkConstant', function () {
        $valExpr = new Node\Expr\ClassConstFetch(new Node\Name\FullyQualified('App\Config'), 'Data');

        $call = NodeBuilder::createConstantCheckCall('App\Config::Data', $valExpr, 'list<string>', 12);

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('TypePHP\Internal\RuntimeTypeChecker::checkConstant')
            ->and($call->args)->toHaveCount(5)
            ->and($call->args[0]->value->value)->toBe('App\Config::Data')
            ->and($call->args[1]->value)->toBe($valExpr)
            ->and($call->args[2]->value->value)->toBe('list<string>')
            ->and($call->args[3]->value)->toBeInstanceOf(Node\Scalar\MagicConst\File::class)
            ->and($call->args[4]->value->value)->toBe(12)
        ;
    });

    test('createConstantClosureParamCheckCall creates FuncCall node for RuntimeTypeChecker::checkConstantClosureParam', function () {
        $paramVar = new Node\Expr\Variable('id');

        $call = NodeBuilder::createConstantClosureParamCheckCall(
            'App\Config::Formatter',
            'id',
            $paramVar,
            'positive-int',
            15
        );

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('TypePHP\Internal\RuntimeTypeChecker::checkConstantClosureParam')
            ->and($call->args)->toHaveCount(6)
            ->and($call->args[0]->value->value)->toBe('App\Config::Formatter')
            ->and($call->args[1]->value->value)->toBe('id')
            ->and($call->args[2]->value)->toBe($paramVar)
            ->and($call->args[3]->value->value)->toBe('positive-int')
            ->and($call->args[4]->value)->toBeInstanceOf(Node\Scalar\MagicConst\File::class)
            ->and($call->args[5]->value->value)->toBe(15)
        ;
    });

    test('createConstantClosureReturnCheckCall creates FuncCall node for RuntimeTypeChecker::checkConstantClosureReturn', function () {
        $valueExpr = new Node\Scalar\String_('test');

        $call = NodeBuilder::createConstantClosureReturnCheckCall(
            'App\Config::Callback',
            $valueExpr,
            'int',
            20
        );

        expect($call)->toBeInstanceOf(Node\Expr\FuncCall::class)
            ->and($call->name->toString())->toBe('TypePHP\Internal\RuntimeTypeChecker::checkConstantClosureReturn')
            ->and($call->args)->toHaveCount(5)
            ->and($call->args[0]->value->value)->toBe('App\Config::Callback')
            ->and($call->args[1]->value)->toBe($valueExpr)
            ->and($call->args[2]->value->value)->toBe('int')
            ->and($call->args[3]->value)->toBeInstanceOf(Node\Scalar\MagicConst\File::class)
            ->and($call->args[4]->value->value)->toBe(20)
        ;
    });
});
