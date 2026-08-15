<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Callables\GenericCallableService;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Standalone generic callback function
 *
 * @template T
 *
 * @param callable(T, T): bool $comparator
 * @param T $a
 * @param T $b
 */
function testGenericComparator(callable $comparator, mixed $a, mixed $b): bool
{
    return $comparator($a, $b);
}

describe('Generic Callables (@template T with callable(T): T)', function () {
    describe('Class Method Generic Callables', function () {
        test('executes generic callback when T is inferred as integer', function () {
            $service = new GenericCallableService();
            $double = fn (int $x): int => $x * 2;

            expect($service->transform($double, 21))->toBe(42);
        });

        test('executes generic callback when T is inferred as string', function () {
            $service = new GenericCallableService();
            $shout = fn (string $s): string => strtoupper($s);

            expect($service->transform($shout, 'hello'))->toBe('HELLO');
        });

        test('throws TypeError when callback return value violates inferred generic template T', function () {
            $service = new GenericCallableService();
            $badReturnCallback = fn (int $x): string => 'invalid';

            expect(fn () => $service->transform($badReturnCallback, 10))
                ->toThrow(TypeError::class, 'must be of type int');
        });

        test('executes generic callback with class bound (@template T of Animal)', function () {
            $service = new GenericCallableService();
            $formatter = fn (Dog $d): string => 'dog_instance';

            expect($service->formatAnimal($formatter, new Dog()))->toBe('dog_instance');
        });

        test('throws TypeError when generic animal callback returns empty string', function () {
            $service = new GenericCallableService();
            $badFormatter = fn (Dog $d): string => ''; 

            expect(fn () => $service->formatAnimal($badFormatter, new Dog()))
                ->toThrow(TypeError::class, 'must be of type non-empty-string');
        });
    });

    describe('Standalone Generic Callables with Multiple Template Arguments', function () {
        test('executes generic comparator with matching scalar arguments', function () {
            $isGreater = fn (int $x, int $y): bool => $x > $y;

            expect(testGenericComparator($isGreater, 10, 5))->toBeTrue();
            expect(testGenericComparator($isGreater, 3, 8))->toBeFalse();
        });

        test('throws TypeError when comparator return value is not a boolean', function () {
            $badComparator = fn (int $x, int $y): int => 1; 

            expect(fn () => testGenericComparator($badComparator, 10, 5))
                ->toThrow(TypeError::class, 'must be of type bool');
        });
    });
});