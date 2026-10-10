<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\CallablesAndIterators;

use Closure;
use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * 1. Basic callable-local template
 *
 * @param callable<T>(T): T $callback
 */
function testApplyCallableLocalGeneric(callable $callback, mixed $value): mixed
{
    return $callback($value);
}

/**
 * 2. Multiple callable-local templates
 *
 * @param callable<T, R>(T): R $callback
 */
function testApplyCallableMultiLocalGeneric(callable $callback, mixed $value): mixed
{
    return $callback($value);
}

/**
 * 3. Bounded callable-local template
 *
 * @param callable<T of Animal>(T): T $callback
 */
function testApplyCallableBoundedLocalGeneric(callable $callback, mixed $value): mixed
{
    return $callback($value);
}

/**
 * 4. Closure syntax with local template
 *
 * @param Closure<T>(T): T $closure
 */
function testApplyClosureLocalGeneric(Closure $closure, mixed $value): mixed
{
    return $closure($value);
}

describe('Callable-Local Generics (callable<T>(T): T & Closure<T>(T): T)', function () {
    describe('1. Single Local Template <T>(T): T', function () {
        test('infers local T as int and returns int', function () {
            $cb = fn (int $x): int => $x + 1;

            $result = testApplyCallableLocalGeneric($cb, 5);
            expect($result)->toBe(6);
        });

        test('infers local T as string and returns string', function () {
            $cb = fn (string $s): string => strtoupper($s);

            $result = testApplyCallableLocalGeneric($cb, 'hello');
            expect($result)->toBe('HELLO');
        });

        test('throws TypeError when callback return type violates inferred local T', function () {
            $badCb = fn (int $x): string => 'not_an_int';

            expect(fn () => testApplyCallableLocalGeneric($badCb, 5))
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });

        test('allows calling the same callback multiple times with different types without cross-call locking', function () {
            /** @var callable<T>(T): T $identity */
            $identity = fn ($x) => $x;

            expect(testApplyCallableLocalGeneric($identity, 42))->toBe(42)
                ->and(testApplyCallableLocalGeneric($identity, 'world'))->toBe('world')
            ;
        });
    });

    describe('2. Multiple Local Templates <T, R>(T): R', function () {
        test('infers T and R independently', function () {
            $stringify = fn (int $x): string => "val_{$x}";

            $result = testApplyCallableMultiLocalGeneric($stringify, 10);
            expect($result)->toBe('val_10');
        });

        test('throws TypeError when callback return violates inferred R', function () {
            $badCb = fn (int $x): int => 999;

            /** @var callable<T>(T): string $typedCb */
            $typedCb = $badCb;

            expect(fn () => testApplyCallableMultiLocalGeneric($typedCb, 10))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });
    });

    describe('3. Bounded Local Templates <T of Animal>(T): T', function () {
        test('accepts Dog satisfying bound Animal', function () {
            $dog = new Dog();
            $echo = fn (Dog $d): Dog => $d;

            $result = testApplyCallableBoundedLocalGeneric($echo, $dog);
            expect($result)->toBe($dog);
        });

        test('throws TypeError when argument violates bound Animal', function () {
            $car = new Car();
            $echo = fn ($c) => $c;

            expect(fn () => testApplyCallableBoundedLocalGeneric($echo, $car))
                ->toThrow(TypeError::class, 'Animal')
            ;
        });
    });

    describe('4. Closure Syntax Closure<T>(T): T', function () {
        test('works identically with Closure<T>(T): T syntax', function () {
            $closure = fn (int $x): int => $x * 2;

            $result = testApplyClosureLocalGeneric($closure, 21);
            expect($result)->toBe(42);
        });
    });
});
