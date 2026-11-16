<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Closure;
use stdClass;
use TypePHP\Exception\TypeError;

abstract class TupleInferenceAnimal
{
    public function __construct(public readonly string $name)
    {
    }
}

final class TupleInferenceDog extends TupleInferenceAnimal
{
}

final class TupleInferenceCat extends TupleInferenceAnimal
{
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
final class TuplePairStore
{
    /**
     * @param array<int, array{0: K, 1: V}> $pairs
     */
    public function __construct(public array $pairs = [])
    {
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<array{0: NK, 1: NV}> $source
     *
     * @return self<NK, NV>
     */
    public static function fromPairs(iterable $source): self
    {
        $pairs = [];
        foreach ($source as [$k, $v]) {
            $pairs[] = [$k, $v];
        }

        return new self($pairs);
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<array{0: NK, 1: NV}>|Closure(): iterable<array{0: NK, 1: NV}> $data
     *
     * @return self<NK, NV>
     */
    public static function ofPairs(iterable|Closure $data): self
    {
        if ($data instanceof Closure) {
            return self::fromPairs($data());
        }

        return self::fromPairs($data);
    }

    /**
     * @template NK of string|int
     * @template NV of TupleInferenceAnimal
     *
     * @param list<array{id: NK, pet: NV}> $records
     *
     * @return list<NV>
     */
    public static function extractPetsFromNamedShapes(array $records): array
    {
        $out = [];
        foreach ($records as $r) {
            $out[] = $r['pet'];
        }

        return $out;
    }
}

describe('Tuple & Array Shape Multi-Element Template Inference', function () {
    describe('1. Unifying Heterogeneous Key Types Across Tuples (array{0: NK, 1: NV})', function () {
        test('unifies string, int, float, bool, and object keys across multiple tuple elements', function () {
            $obj = new stdClass();

            $store = TuplePairStore::fromPairs([
                ['1', 'a'],
                [1, 'b'],
                [$obj, 'c'],
                [false, 'd'],
                [2.5, 'e'],
            ]);

            expect($store->pairs)->toHaveCount(5);
        });

        test('unifies tuple key and value types when parameter is a union with Closure (iterable|Closure)', function () {
            $obj = new stdClass();

            $store = TuplePairStore::ofPairs([
                [$obj, 'a'],
                ['b', 2],
            ]);

            expect($store->pairs)->toHaveCount(2);
        });
    });

    describe('2. Unifying Heterogeneous Object & Nullable Value Types Across Tuples', function () {
        test('unifies sibling object classes (Dog and Cat) across tuple elements', function () {
            $dog1 = new TupleInferenceDog('Rex');
            $cat = new TupleInferenceCat('Whiskers');
            $dog2 = new TupleInferenceDog('Buddy');

            $store = TuplePairStore::ofPairs([
                ['a', $dog1],
                ['b', $cat],
                ['c', $dog2],
            ]);

            expect($store->pairs)->toHaveCount(3);
        });

        test('unifies non-null and null values when null appears in a later tuple element', function () {
            $store = TuplePairStore::ofPairs([
                ['01', 'a'],
                ['1', 'b'],
                ['2', null],
            ]);

            expect($store->pairs)->toHaveCount(3);
        });

        test('unifies named array shape fields across list elements while respecting upper bounds', function () {
            $dog = new TupleInferenceDog('Rex');
            $cat = new TupleInferenceCat('Whiskers');

            $pets = TuplePairStore::extractPetsFromNamedShapes([
                ['id' => 'first', 'pet' => $dog],
                ['id' => 2, 'pet' => $cat],
            ]);

            expect($pets)->toHaveCount(2);
        });
    });

    describe('3. Enforcing Template Upper Bounds Across All Tuple Elements', function () {
        test('rejects tuple when a later element violates the template upper bound (null key)', function () {
            expect(fn () => TuplePairStore::fromPairs([
                ['valid_key', 'a'],
                [null, 'b'],
            ]))->toThrow(TypeError::class);
        });

        test('rejects named shape when a later element violates the template upper bound', function () {
            $dog = new TupleInferenceDog('Rex');

            expect(fn () => TuplePairStore::extractPetsFromNamedShapes([
                ['id' => 'first', 'pet' => $dog],
                ['id' => 2, 'pet' => new stdClass()],
            ]))->toThrow(TypeError::class);
        });
    });
});
