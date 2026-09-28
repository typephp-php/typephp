<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

describe('Closure Variable Type Preservation (Arrow Functions & Long Closures)', function () {
    test('preserves outer variable type contract inside short closures (arrow functions)', function () {
        /** @var positive-int $id */
        $id = 10;

        $arrowFn = fn () => $id;
        expect($arrowFn())->toBe(10);

        $badArrowFn = fn () => $id = -5;
        expect($badArrowFn)->toThrow(TypeError::class, 'Variable $id');
    });

    test('preserves outer variable type contract inside long closures (use ($id))', function () {
        /** @var positive-int $count */
        $count = 100;

        $closure = function () use ($count) {
            return $count;
        };
        expect($closure())->toBe(100);

        $badClosure = function () use ($count) {
            $count = -50;
        };
        expect($badClosure)->toThrow(TypeError::class, 'Variable $count');
    });

    test('preserves outer variable type contract when captured by reference (use (&$ref))', function () {
        /** @var positive-int $num */
        $num = 50;

        $refClosure = function () use (&$num) {
            $num = -99;
        };

        expect($refClosure)->toThrow(TypeError::class, 'Variable $num');
    });

    test('does not leak outer variable type contract into closures without use clause', function () {
        /** @var positive-int $leaked */
        $leaked = 100;

        $closure = function (): string {
            $leaked = 'valid_string_in_isolated_closure_scope';

            return $leaked;
        };

        expect($closure())->toBe('valid_string_in_isolated_closure_scope')
            ->and($leaked)->toBe(100)
        ;
    });

    test('allows assigning negative integers to uncaptured variable in closure', function () {
        /** @var positive-int $isolatedCount */
        $isolatedCount = 50;

        $closure = function (): int {
            $isolatedCount = -99;

            return $isolatedCount;
        };

        expect($closure())->toBe(-99)
            ->and($isolatedCount)->toBe(50)
        ;
    });

    test('does not leak outer script variable type contracts into functions', function () {
        /** @var positive-int $scriptScopedVar */
        $scriptScopedVar = 42;

        $fn = function (): string {
            $scriptScopedVar = 'completely_different_string';

            return $scriptScopedVar;
        };

        expect($fn())->toBe('completely_different_string')
            ->and($scriptScopedVar)->toBe(42)
        ;
    });
});
