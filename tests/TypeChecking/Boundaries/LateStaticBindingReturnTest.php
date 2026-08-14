<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Services\GrandChildEntityFactory;
use TypePHP\Tests\Fixtures\Services\UserEntityFactory;

describe('Late Static Binding Return Contracts (@return static)', function () {
    describe('Static Factory Methods', function () {
        test('accepts valid late-static-bound instance returned from parent static factory method', function () {
            $user = UserEntityFactory::create();

            expect($user)->toBeInstanceOf(UserEntityFactory::class);
        });

        test('throws TypeError when parent static factory method returns stdClass instead of late-static-bound child class', function () {
            expect(fn () => UserEntityFactory::createWrongInstance())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory');
        });

        test('throws TypeError when static factory method returns a sibling class instead of the called late-static class', function () {
            expect(fn () => UserEntityFactory::createSibling())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory');
        });
    });

    describe('Multi-Level 3-Tier Late Static Binding (GrandParent -> Parent -> GrandChild)', function () {
        test('resolves late-static-bound class to the deepest 3rd-tier descendant', function () {
            $grandChild = GrandChildEntityFactory::create();

            expect($grandChild)->toBeInstanceOf(GrandChildEntityFactory::class);
        });

        test('throws TypeError when 3rd-tier descendant returns an instance that violates the deepest child type', function () {
            expect(fn () => GrandChildEntityFactory::createSibling())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\GrandChildEntityFactory');
        });
    });

    describe('Collections of Late-Static-Bound Instances (list<static>)', function () {
        test('accepts list containing matching late-static-bound instances', function () {
            $batch = UserEntityFactory::createBatch(3);

            expect($batch)->toHaveCount(3)
                ->and($batch[0])->toBeInstanceOf(UserEntityFactory::class);
        });

        test('throws TypeError when list contains an item violating late-static-bound type', function () {
            expect(fn () => UserEntityFactory::createBadBatch())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory');
        });
    });

    describe('Instance Methods Returning static ($this)', function () {
        test('accepts valid $this instance returned from fluent instance method', function () {
            $user = new UserEntityFactory();

            expect($user->withSetting('theme'))->toBe($user);
        });

        test('throws TypeError when fluent instance method returns sibling instance', function () {
            $user = new UserEntityFactory();

            expect(fn () => $user->withBadSetting())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory');
        });
    });
});