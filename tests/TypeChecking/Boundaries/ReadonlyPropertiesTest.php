<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Readonly\ReadonlyOrder;
use TypePHP\Tests\Fixtures\Readonly\ReadonlyUser;
use TypePHP\Tests\Fixtures\Readonly\UninitializedReadonlyContainer;

/**
 * Function accepting an object shape
 *
 * @param object{id: positive-int, name: non-empty-string} $obj
 */
function testObjectShapeOnReadonly(object $obj): bool
{
    return true;
}

describe('PHP 8.1+ Readonly Properties & Constructor Promotion', function () {
    describe('Explicit Readonly Properties in Constructor', function () {
        test('instantiates class with valid readonly property assignments', function () {
            $user = new ReadonlyUser(42, 'Alice');

            expect($user->id)->toBe(42)
                ->and($user->username)->toBe('Alice');
        });

        test('throws TypeError when initializing readonly property with invalid integer', function () {
            expect(fn () => new ReadonlyUser(-5, 'Alice'))
                ->toThrow(TypeError::class, 'positive-int');
        });

        test('throws TypeError when initializing readonly property with empty string', function () {
            expect(fn () => new ReadonlyUser(42, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });
    });

    describe('Promoted Readonly Constructor Parameters (PHP 8.1+)', function () {
        test('instantiates class with valid promoted readonly properties', function () {
            $order = new ReadonlyOrder(100, 'SKU-500', 5);

            expect($order->orderId)->toBe(100)
                ->and($order->sku)->toBe('SKU-500')
                ->and($order->quantity)->toBe(5);
        });

        test('throws TypeError when promoted readonly orderId violates positive-int', function () {
            expect(fn () => new ReadonlyOrder(-1, 'SKU-500', 5))
                ->toThrow(TypeError::class, 'Argument $orderId must be of type positive-int');
        });

        test('throws TypeError when promoted readonly sku violates non-empty-string', function () {
            expect(fn () => new ReadonlyOrder(100, '', 5))
                ->toThrow(TypeError::class, 'Argument $sku must be of type non-empty-string');
        });

        test('throws TypeError when promoted readonly quantity exceeds max bound of int<1, 100>', function () {
            expect(fn () => new ReadonlyOrder(100, 'SKU-500', 250))
                ->toThrow(TypeError::class, 'Argument $quantity');
        });
    });

    describe('Uninitialized Readonly Properties & Object Shapes', function () {
        test('safely rejects uninitialized readonly object without crashing PHP engine', function () {
            $uninitialized = new UninitializedReadonlyContainer();

            expect(fn () => testObjectShapeOnReadonly($uninitialized))
                ->toThrow(TypeError::class, "property 'id' is uninitialized");
        });

        test('validates and accepts readonly container once initialized', function () {
            $container = new UninitializedReadonlyContainer();
            $container->initialize(10, 'Report');

            expect(testObjectShapeOnReadonly($container))->toBeTrue();
        });

        test('throws TypeError when deferred readonly initialization receives invalid value', function () {
            $container = new UninitializedReadonlyContainer();

            expect(fn () => $container->initialize(-50, 'Report'))
                ->toThrow(TypeError::class, 'positive-int');

            expect(fn () => $container->initialize(10, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });
    });
});