<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Generator;

class FixtureObjectA
{
    public function __construct(public string $a, public string $b)
    {
    }
}

class FixtureNestedObjectA
{
    public function __construct(public array $items = [])
    {
    }
}

/**
 * Fixture reproducing Tempest map_with_keys()
 *
 * @template TKey of array-key
 * @template TValue
 * @template TReturnKey of array-key
 * @template TReturnValue
 *
 * @param array<TKey, TValue> $array
 * @param callable(TValue, TKey): Generator<TReturnKey, TReturnValue> $map
 *
 * @return array<TReturnKey, TReturnValue>
 */
function testMapWithKeys(array $array, callable $map): array
{
    $result = [];
    foreach ($array as $key => $value) {
        foreach ($map($value, $key) as $k => $v) {
            $result[$k] = $v;
        }
    }

    return $result;
}

describe('Heterogeneous Generic Array Mapping (Tempest ObjectFactory pattern)', function () {
    test('accepts heterogeneous array of different objects passed to map_with_keys', function () {
        $objects = [
            new FixtureObjectA('a', 'b'),
            new FixtureObjectA('c', 'd'),
            new FixtureNestedObjectA(['item1', 'item2']),
        ];

        $result = testMapWithKeys(
            $objects,
            fn (mixed $item, mixed $key) => yield $key => \get_class($item)
        );

        expect($result)->toHaveCount(3)
            ->and($result[0])->toBe(FixtureObjectA::class)
            ->and($result[2])->toBe(FixtureNestedObjectA::class)
        ;
    });
});
