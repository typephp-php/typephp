<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Recursive Generic Function
 *
 * @template T of Animal
 *
 * @param T $item
 * @param int $depth
 *
 * @return T
 */
function testRecursiveGenericFunction(Animal $item, int $depth = 1): Animal
{
    if ($depth >= 2) {
        return $item;
    }

    // Inner recursive call with Cat
    testRecursiveGenericFunction(new Cat(), $depth + 1);

    // Outer call returns original Dog
    return $item;
}

/**
 * Function throwing Exception midway
 *
 * @template T of Animal
 *
 * @param T $item
 *
 * @return T
 */
function testFailingGenericFunction(Animal $item): Animal
{
    throw new RuntimeException('Midway exception');
}

describe('Recursion and Exception Call Stack Leak Prevention', function () {
    test('preserves generic template bindings across recursive calls', function () {
        $dog = new Dog();

        expect(testRecursiveGenericFunction($dog, 1))->toBe($dog);
    });

    test('cleans up call stack frame when exception is thrown midway', function () {
        expect(fn () => testFailingGenericFunction(new Dog()))
            ->toThrow(RuntimeException::class, 'Midway exception')
        ;

        expect(fn () => testFailingGenericFunction(new Cat()))
            ->toThrow(RuntimeException::class, 'Midway exception')
        ;
    });
});
