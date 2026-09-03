<?php

declare(strict_types=1);

/**
 * Fixture reproducing Tempest\Support\Arr\diff_keys
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @param array<TKey, TValue> $array
 * @param array<TKey, mixed> ...$arrays
 *
 * @return array<TKey, TValue>
 */
function testDiffKeysFixture(array $array, array ...$arrays): array
{
    foreach ($arrays as $arr) {
        foreach (array_keys($arr) as $key) {
            unset($array[$key]);
        }
    }

    return $array;
}

describe('Variadic Parameters with Generic Array Contracts (@param array<K, V> ...$arrays)', function () {
    test('validates variadic array arguments when main array has string keys (Tempest diffKeys pattern)', function () {
        $initial = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'age' => 42,
        ];

        $diff = testDiffKeysFixture($initial, ['age' => 10]);

        expect($diff)->toBe([
            'first_name' => 'John',
            'last_name' => 'Doe',
        ]);
    });

    test('throws TypeError when a key in a variadic array argument violates template TKey', function () {
        $initial = [
            'first_name' => 'John',
            'last_name' => 'Doe',
        ];

        expect(fn () => testDiffKeysFixture($initial, [123 => 'something']))
            ->toThrow(TypeError::class)
        ;
    });
});
