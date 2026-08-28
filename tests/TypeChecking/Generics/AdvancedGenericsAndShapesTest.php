<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * 1. class-string<T> Factory Contract
 *
 * @template T of Animal
 *
 * @param class-string<T> $class
 * @param mixed $instanceToReturn
 *
 * @return T
 */
function testClassStringFactory(string $class, mixed $instanceToReturn): mixed
{
    return $instanceToReturn;
}

/**
 * 2. Variadic Generic Template Contract
 *
 * @template T
 *
 * @param T ...$items
 *
 * @return T[]
 */
function testCollectSameType(...$items): array
{
    return $items;
}

/**
 * 3. Unbound Template Return Fallback Contract
 *
 * @template T of Animal
 *
 * @param mixed $result
 *
 * @return T
 */
function testCreateAnimalFallback(mixed $result): mixed
{
    return $result;
}

/**
 * 4. Tagged / Discriminated Union Array Shape
 *
 * @return array{status: 'success', code: 200, data: array{id: positive-int}} | array{status: 'error', code: 400|500, message: non-empty-string}
 */
function testProcessApiResponse(bool $isSuccess, mixed $payload): array
{
    return $payload;
}

/**
 * @template T
 *
 * @param array{id: T, label: string} $payload
 *
 * @return array{id: T, label: string}
 */
function testGenericShapeMultiCall(array $payload): array
{
    return $payload;
}

/**
 * @template T
 *
 * @param T $typeSample
 * @param array{id: T} $payload
 */
function testLinkedGenericShape(mixed $typeSample, array $payload): array
{
    return $payload;
}

describe('class-string<T> Factory Contracts', function () {
    test('accepts valid class-string and returns matching instance', function () {
        $dog = new Dog();
        $result = testClassStringFactory(Dog::class, $dog);

        expect($result)->toBe($dog);
    });

    test('throws TypeError when class-string argument does not satisfy bound', function () {
        expect(fn () => testClassStringFactory(Car::class, new Car()))
            ->toThrow(TypeError::class, 'must be a class-string of TypePHP\Tests\Fixtures\Domain\Animal')
        ;
    });

    test('throws TypeError when returned object does not match bound class-string', function () {
        // Class string asks for Dog, but function returns Cat
        expect(fn () => testClassStringFactory(Dog::class, new Cat()))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Variadic Generic Templates (@param T ...$items)', function () {
    test('accepts variadic items of the same inferred type T', function () {
        $result = testCollectSameType(10, 20, 30);
        expect($result)->toBe([10, 20, 30]);
    });

    test('throws TypeError when variadic items have inconsistent types', function () {
        // T is inferred as int from 1st item, 3rd item 'invalid' violates T = int
        expect(fn () => testCollectSameType(10, 20, 'invalid'))
            ->toThrow(TypeError::class, 'template T = int')
        ;
    });
});

describe('Unbound Template Return Fallbacks (@template T of Bound)', function () {
    test('accepts returned value satisfying unbound template bound', function () {
        $dog = new Dog();
        $result = testCreateAnimalFallback($dog);

        expect($result)->toBe($dog);
    });

    test('throws TypeError when unbound return value violates template bound', function () {
        // Car is not an Animal
        expect(fn () => testCreateAnimalFallback(new Car()))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Tagged / Discriminated Union Array Shapes', function () {
    test('accepts valid success payload matching success union shape', function () {
        $successPayload = [
            'status' => 'success',
            'code' => 200,
            'data' => ['id' => 10],
        ];

        expect(testProcessApiResponse(true, $successPayload))->toBe($successPayload);
    });

    test('accepts valid error payload matching error union shape', function () {
        $errorPayload = [
            'status' => 'error',
            'code' => 500,
            'message' => 'Internal Server Error',
        ];

        expect(testProcessApiResponse(false, $errorPayload))->toBe($errorPayload);
    });

    test('throws TypeError on shape failing all union variants', function () {
        $badPayload = [
            'status' => 'success',
            'code' => 200,
        ];

        expect(fn () => testProcessApiResponse(true, $badPayload))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('calling a method with generic array shape multiple times with different template types succeeds', function () {
        $res1 = testGenericShapeMultiCall(['id' => 123, 'label' => 'first']);
        expect($res1)->toBe(['id' => 123, 'label' => 'first']);

        $res2 = testGenericShapeMultiCall(['id' => 'abc', 'label' => 'second']);
        expect($res2)->toBe(['id' => 'abc', 'label' => 'second']);
    });

    test('linked generic array shape does not corrupt template binding across calls', function () {
        testLinkedGenericShape(10, ['id' => 10]);

        $res = testLinkedGenericShape('hello', ['id' => 'hello']);
        expect($res)->toBe(['id' => 'hello']);
    });
});
