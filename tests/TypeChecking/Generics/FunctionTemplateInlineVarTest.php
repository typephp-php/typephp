<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

/**
 * Tempest Arr\range reproduction fixture
 *
 * @template T of int|float
 *
 * @param T $start
 * @param T $end
 * @param T $step
 *
 * @return list<T>
 */
function testGenericRangeFunction(mixed $start, mixed $end, mixed $step = 1): array
{
    /** @var T $step */
    $step = 1;

    return [$start, $step, $end];
}

/**
 * Generic function with inline @var list<T>
 *
 * @template T
 *
 * @param T $item
 *
 * @return list<T>
 */
function testGenericListWrapFunction(mixed $item): array
{
    /** @var list<T> $list */
    $list = [$item];

    return $list;
}

/**
 * Generic function with a closure accessing @var T
 *
 * @template T
 *
 * @param T $val
 *
 * @return T
 */
function testGenericFunctionWithClosure(mixed $val): mixed
{
    $closure = function () use ($val): mixed {
        /** @var T $inner */
        $inner = $val;

        return $inner;
    };

    return $closure();
}

/**
 * Standalone function with local @phpstan-type alias on inline @var
 *
 * @phpstan-type LocalId positive-int
 */
function testStandaloneFunctionAlias(int $id): int
{
    /** @var LocalId $localId */
    $localId = $id;

    return $localId;
}

/**
 * @template T
 *
 * @param T $x
 */
function testBadGenericFunction(mixed $x): mixed
{
    /** @var T $val */
    $val = 'not_an_int';

    return $val;
}

describe('Standalone Generic Function Template Resolution in Inline @var', function () {
    test('resolves inline @var T when called from within a test class (Tempest range() reproduction)', function () {
        $result = testGenericRangeFunction(0, 9, 2);

        expect($result)->toBe([0, 1, 9]);
    });

    test('resolves inline @var T when T is inferred as float', function () {
        $result = testGenericRangeFunction(0.0, 9.0, 2.0);

        expect($result)->toBe([0.0, 1, 9.0]);
    });

    test('throws TypeError with resolved concrete type when inline variable violates bound template T', function () {
        expect(fn () => testBadGenericFunction(42))
            ->toThrow(TypeError::class, 'must be of type int')
        ;
    });

    test('resolves inline @var list<T> in standalone generic function', function () {
        expect(testGenericListWrapFunction('hello'))->toBe(['hello']);
        expect(testGenericListWrapFunction(123))->toBe([123]);
    });

    test('resolves inline @var T inside closures defined within standalone generic functions', function () {
        expect(testGenericFunctionWithClosure(500))->toBe(500);
        expect(testGenericFunctionWithClosure('valid_string'))->toBe('valid_string');
    });

    test('resolves @phpstan-type aliases on inline @var inside standalone functions', function () {
        expect(testStandaloneFunctionAlias(42))->toBe(42);

        expect(fn () => testStandaloneFunctionAlias(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
