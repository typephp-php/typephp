<?php

declare(strict_types=1);

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
class MapSim
{
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V>|Closure():iterable<K, V> $data
 *
 * @return MapSim<K, V>
 */
function mapOfSim(iterable|Closure $data): MapSim
{
    return new MapSim();
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V>|Closure():iterable<K, V> $data
 *
 * @return MapSim<K, V>
 */
function testHelperMapOf(iterable|Closure $data): MapSim
{
    if (! \is_callable($data)) {
        return mapOfSim(fn () => $data);
    }

    return mapOfSim($data);
}

describe('Lazy Closure Template Inference Failure', function () {
    test('specializes union bound template when returning instance', function () {
        $data = ['user' => 1];
        $result = testHelperMapOf($data);

        expect($result)->toBeInstanceOf(MapSim::class);
        expect(TypePHP\TypePHP::getGenericTypes($result))->toBe([
            'K' => 'string',
            'V' => 'int',
        ]);
    });
});
