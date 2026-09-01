<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Exact Tempest Arr\range generic signature pattern
 *
 * @template T of int|float
 *
 * @param T $start
 * @param T $end
 * @param T|null $step
 *
 * @return list<T>
 */
function testTempestRangePattern(int|float $start, int|float $end, int|float|null $step = 1): array
{
    return [$start, $end, $step];
}

/**
 * Generic function with object upper bound (@template T of Animal)
 *
 * @template T of Animal
 *
 * @param T $first
 * @param T $second
 *
 * @return list<T>
 */
function testAnimalPairUnification(Animal $first, Animal $second): array
{
    return [$first, $second];
}

/**
 * Variadic generic parameters with upper bound
 *
 * @template T of int|float
 *
 * @param T ...$numbers
 *
 * @return list<T>
 */
function testVariadicBoundWidening(int|float ...$numbers): array
{
    return $numbers;
}

/**
 * Class with method-level template bound widening
 */
class MathServiceWithGenerics
{
    /**
     * @template T of int|float
     *
     * @param T $a
     * @param T $b
     *
     * @return T
     */
    public function sum(int|float $a, int|float $b): int|float
    {
        return $a + $b;
    }
}

describe('Template Bound Widening and Type Unification (@template T of Bound)', function () {
    test('reproduces Tempest range(0, 9.8798, 0.48) argument type widening', function () {
        $result = testTempestRangePattern(0, 9.8798, 0.48);

        expect($result)->toBe([0, 9.8798, 0.48]);
    });

    test('unifies different subclasses of the declared upper bound (Dog and Cat for Animal)', function () {
        $dog = new Dog();
        $cat = new Cat();

        $result = testAnimalPairUnification($dog, $cat);

        expect($result)->toBe([$dog, $cat]);
    });

    test('unifies mixed int and float across method parameters on classes', function () {
        $service = new MathServiceWithGenerics();

        $result = $service->sum(10, 2.5);

        expect($result)->toBe(12.5);
    });

    test('widens variadic arguments satisfying the template upper bound', function () {
        $result = testVariadicBoundWidening(1, 2.5, 3, 4.75);

        expect($result)->toBe([1, 2.5, 3, 4.75]);
    });

    test('throws TypeError when an argument violates the template upper bound entirely', function () {
        expect(fn () => testTempestRangePattern(0, 'invalid', 1))
            ->toThrow(TypeError::class, 'must be of type')
        ;
    });

    test('throws TypeError when an object argument violates the class upper bound', function () {
        expect(fn () => testAnimalPairUnification(new Dog(), new Car()))
            ->toThrow(TypeError::class, 'must be of type')
        ;
    });
});
