<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Array values helper (exact Tempest Arr\values pattern)
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @param iterable<TKey, TValue> $array
 *
 * @return list<TValue>
 */
function testHeterogeneousValues(iterable $array): array
{
    return array_values(\is_array($array) ? $array : iterator_to_array($array));
}

/**
 * Higher-order mapper that DOES have a callable parameter (must still pre-infer V)
 *
 * @template K of array-key
 * @template V
 * @template V2
 *
 * @param array<K, V> $array
 * @param callable(V): V2 $cb
 *
 * @return array<K, V2>
 */
function testHigherOrderMap(array $array, callable $cb): array
{
    $out = [];
    foreach ($array as $k => $v) {
        $out[$k] = $cb($v);
    }

    return $out;
}

describe('Heterogeneous Array Generics in Array Helpers (Tempest values/flatten pattern)', function () {
    test('accepts heterogeneous array of objects and strings in standalone array utility function', function () {
        $mixedDiscoveredItems = [
            0 => new Dog(),
            1 => new Dog(),
            2 => 'App\\Models\\DiscoveredEntity',
        ];

        $result = testHeterogeneousValues($mixedDiscoveredItems);

        expect($result)->toHaveCount(3)
            ->and($result[0])->toBeInstanceOf(Dog::class)
            ->and($result[2])->toBe('App\\Models\\DiscoveredEntity')
        ;
    });

    test('higher-order functions with callables still pre-infer template and enforce consistency', function () {
        $stringify = fn(int $x): string => "num_{$x}";

        expect(testHigherOrderMap([10, 20], $stringify))->toBe(['num_10', 'num_20']);

        expect(fn() => testHigherOrderMap([10, 'not_an_int'], $stringify))
            ->toThrow(TypeError::class, "['1']");
    });
});
