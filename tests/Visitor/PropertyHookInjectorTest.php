<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use PhpParser\Comment\Doc;
use PhpParser\Node;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Ast\PropertyHookInjector;

describe('PropertyHookInjector Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Get Property Hooks', function () {
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

        test('wraps return statements inside block get property hooks (get { return $expr; })', function () {
            $hook = new Node\PropertyHook(
                name: 'get',
                body: [
                    new Node\Stmt\Return_(new Node\Scalar\String_('hello')),
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
                ->and($body[0])->toBeInstanceOf(Node\Stmt\Return_::class)
                ->and($body[0]->expr)->toBeInstanceOf(Node\Expr\Ternary::class)
            ;
        });
    });

    describe('Set Property Hooks', function () {
        test('wraps short set property hooks (set => $expr) in ternary keeping assignment on false branch', function () {
            $assignment = new Node\Expr\Assign(
                new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'title'),
                new Node\Expr\Variable('value')
            );

            $hook = new Node\PropertyHook(
                name: 'set',
                body: $assignment
            );

            $prop = new Node\Stmt\Property(
                flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
                props: [new Node\PropertyItem('title')],
                hooks: [$hook]
            );

            PropertyHookInjector::process($prop);

            expect($prop->hooks[0]->body)->toBeInstanceOf(Node\Expr\Ternary::class)
                ->and($prop->hooks[0]->body->else)->toBe($assignment)
            ;
        });

        test('injects paramCheckStmt at top of block set property hooks', function () {
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

        test('extracts custom parameter name in set property hook (set(int $customVal))', function () {
            $hook = new Node\PropertyHook(
                name: 'set',
                body: [
                    new Node\Stmt\Expression(
                        new Node\Expr\Assign(
                            new Node\Expr\PropertyFetch(new Node\Expr\Variable('this'), 'score'),
                            new Node\Expr\Variable('customVal')
                        )
                    ),
                ]
            );
            $hook->params = [
                new Node\Param(new Node\Expr\Variable('customVal'), null, new Node\Identifier('int')),
            ];

            $prop = new Node\Stmt\Property(
                flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
                props: [new Node\PropertyItem('score')],
                hooks: [$hook]
            );

            PropertyHookInjector::process($prop);

            $body = $prop->hooks[0]->body;
            expect($body)->toBeArray()
                ->and($body[0]->expr->var->name)->toBe('customVal')
            ;
        });
    });

    describe('Ignore Tag Suppression (@typephp-ignore)', function () {
        test('skips injecting checks when property docblock contains @typephp-ignore', function () {
            $doc = new Doc("/**\n * @typephp-ignore\n * @var positive-int\n */");

            $hook = new Node\PropertyHook(
                name: 'get',
                body: new Node\Scalar\String_('unmodified')
            );

            $prop = new Node\Stmt\Property(
                flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
                props: [new Node\PropertyItem('unvalidatedProp')],
                hooks: [$hook],
                attributes: ['comments' => [$doc]]
            );

            PropertyHookInjector::process($prop);

            expect($prop->hooks[0]->body)->toBeInstanceOf(Node\Scalar\String_::class);
        });

        test('skips properties without hooks gracefully', function () {
            $prop = new Node\Stmt\Property(
                flags: Node\Stmt\Class_::MODIFIER_PUBLIC,
                props: [new Node\PropertyItem('normalProp')]
            );

            PropertyHookInjector::process($prop);

            expect($prop->props[0]->name->toString())->toBe('normalProp');
        });
    });
});
