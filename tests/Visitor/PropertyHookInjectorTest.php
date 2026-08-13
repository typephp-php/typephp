<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use PhpParser\Node;
use TypePHP\Internal\Visitor\PropertyHookInjector;

describe('PropertyHookInjector Unit Tests', function () {
    test('wraps short get property hooks (get => $expr) in ternary', function () {
        $hook = new Node\PropertyHook(
            name: 'get',
            body: new Node\Scalar\String_('invalid')
        );

        $prop = new Node\Stmt\Property(
            flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
            props: [new Node\PropertyItem('title')],
            hooks: [$hook]
        );

        PropertyHookInjector::process($prop);

        expect($prop->hooks[0]->body)->toBeInstanceOf(Node\Expr\Ternary::class);
    });

    test('wraps short set property hooks (set => $expr) in ternary to avoid parser bugs', function () {
        $hook = new Node\PropertyHook(
            name: 'set',
            body: new Node\Expr\Assign(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'title'),
                new Node\Expr\Variable('value')
            )
        );

        $prop = new Node\Stmt\Property(
            flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
            props: [new Node\PropertyItem('title')],
            hooks: [$hook]
        );

        PropertyHookInjector::process($prop);

        // The body should remain an expression (Ternary), not an array of statements
        expect($prop->hooks[0]->body)->toBeInstanceOf(Node\Expr\Ternary::class);
    });

    test('injects paramCheckStmt into block set property hooks', function () {
        $hook = new Node\PropertyHook(
            name: 'set',
            body: [
                new Node\Stmt\Expression(
                    new Node\Expr\Assign(
                        new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'title'),
                        new Node\Expr\Variable('value')
                    )
                ),
            ]
        );

        $prop = new Node\Stmt\Property(
            flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
            props: [new Node\PropertyItem('title')],
            hooks: [$hook]
        );

        PropertyHookInjector::process($prop);

        $body = $prop->hooks[0]->body;
        expect($body)->toBeArray()
            ->and($body[0])->toBeInstanceOf(Node\Stmt\Expression::class)
            ->and($body[0]->getAttribute('typephp_injected'))->toBeTrue()
        ;
    });
});