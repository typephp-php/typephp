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

/**
 * @template T
 * @param callable(array{id: T}): T $cb
 * @param array{id: T} $payload
 * @return T
 */
function testGenericCallableShape(callable $cb, array $payload): mixed
{
    return $cb($payload);
}

describe('Generic Callables (@template T with callable(T): T)', function () {
    describe('Class Method Generic Callables', function () {
        test('executes generic callback when T is inferred as integer', function () {
            $service = new GenericCallableService();
            $double = fn(int $x): int => $x * 2;

            expect($service->transform($double, 21))->toBe(42);
        });

        test('executes generic callback when T is inferred as string', function () {
            $service = new GenericCallableService();
            $shout = fn(string $s): string => strtoupper($s);

            expect($service->transform($shout, 'hello'))->toBe('HELLO');
        });

        test('throws TypeError when callback return value violates inferred generic template T', function () {
            $service = new GenericCallableService();
            $badReturnCallback = fn(int $x): string => 'invalid';

            expect(fn() => $service->transform($badReturnCallback, 10))
                ->toThrow(TypeError::class, 'must be of type int');
        });

        test('executes generic callback with class bound (@template T of Animal)', function () {
            $service = new GenericCallableService();
            $formatter = fn(Dog $d): string => 'dog_instance';

            expect($service->formatAnimal($formatter, new Dog()))->toBe('dog_instance');
        });

        test('throws TypeError when generic animal callback returns empty string', function () {
            $service = new GenericCallableService();
            $badFormatter = fn(Dog $d): string => '';

            expect(fn() => $service->formatAnimal($badFormatter, new Dog()))
                ->toThrow(TypeError::class, 'must be of type non-empty-string');
        });

        test('generic callable with array shape does not corrupt cached shape across calls', function () {
            $res1 = testGenericCallableShape(fn(array $p) => $p['id'], ['id' => 10]);
            expect($res1)->toBe(10);

            $res2 = testGenericCallableShape(fn(array $p) => $p['id'], ['id' => 'hello']);
            expect($res2)->toBe('hello');
        });
    });

    describe('Standalone Generic Callables with Multiple Template Arguments', function () {
        test('executes generic comparator with matching scalar arguments', function () {
            $isGreater = fn(int $x, int $y): bool => $x > $y;

            expect(testGenericComparator($isGreater, 10, 5))->toBeTrue();
            expect(testGenericComparator($isGreater, 3, 8))->toBeFalse();
        });

        test('throws TypeError when comparator return value is not a boolean', function () {
            $badComparator = fn(int $x, int $y): int => 1;

            expect(fn() => testGenericComparator($badComparator, 10, 5))
                ->toThrow(TypeError::class, 'must be of type bool');
        });
    });

    describe('Higher-Order Generic Array Transformers (array<K, V> with callable(V): V2)', function () {
        test('infers template parameters from array and validates array items on entry', function () {
            $service = new GenericCallableService();
            $stringify = fn(int $n): string => "val_{$n}";

            expect($service->mapArray($stringify, ['item1' => 10, 'item2' => 20]))
                ->toBe(['item1' => 'val_10', 'item2' => 'val_20']);

            expect(fn() => $service->mapArray($stringify, ['item1' => 10, 'item2' => 'invalid_string']))
                ->toThrow(TypeError::class, "['item2']");
        });

        test('validates sequential lists with list<T> and callback(T): R', function () {
            $service = new GenericCallableService();
            $double = fn(int $n): int => $n * 2;

            expect($service->mapList($double, [1, 2, 3]))->toBe([2, 4, 6]);

            expect(fn() => $service->mapList($double, [1, 'bad_int', 3]))
                ->toThrow(TypeError::class, '[1]');
        });

        test('validates both key and value passed into callback(K, V): V2', function () {
            $service = new GenericCallableService();
            $combiner = fn(string $k, int $v): string => "{$k}:{$v}";

            $result = $service->mapWithKey($combiner, ['alpha' => 10, 'beta' => 20]);
            expect($result)->toBe(['alpha' => 'alpha:10', 'beta' => 'beta:20']);

            expect(fn() => $service->mapWithKey($combiner, [0 => 10]))
                ->toThrow(TypeError::class, '$k');
        });

        test('throws TypeError when callback parameter type conflicts with inferred array value V', function () {
            $service = new GenericCallableService();

            $stringOnlyCallback = fn(string $s): string => strtoupper($s);

            expect(fn() => $service->mapArray($stringOnlyCallback, ['a' => 10]))
                ->toThrow(TypeError::class, 'must be of type string');
        });

        test('accepts PHP 8.1+ first-class callables in generic array transformer', function () {
            $service = new GenericCallableService();
            $doubler = new class() {
                public function double(int $n): string
                {
                    return "doubled_{$n}";
                }
            };

            $result = $service->mapArray($doubler->double(...), ['a' => 5, 'b' => 10]);
            expect($result)->toBe(['a' => 'doubled_5', 'b' => 'doubled_10']);
        });

        test('handles empty array cleanly without crashing on template inference', function () {
            $service = new GenericCallableService();
            $fn = fn(int $x): string => (string) $x;

            expect($service->mapArray($fn, []))->toBe([]);
        });
    });
});
