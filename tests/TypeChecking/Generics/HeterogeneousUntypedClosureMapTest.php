<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Closure;

class FixturePrimaryKeyStatement
{
    public function __construct(public string $name = 'id')
    {
    }
}

class FixtureTextStatement
{
    public function __construct(public string $name = 'body')
    {
    }
}

/**
 * Fixture reproducing Tempest\Support\Arr\map()
 *
 * @template TKey of array-key
 * @template TValue
 * @template TMapValue
 *
 * @param array<TKey, TValue> $array
 * @param Closure(TValue, TKey): TMapValue $map
 *
 * @return array<TKey, TMapValue>
 */
function testUntypedClosureMap(array $array, Closure $map): array
{
    $result = [];
    foreach ($array as $key => $value) {
        $result[$key] = $map($value, $key);
    }

    return $result;
}

describe('Heterogeneous Array Mapping with Untyped Closures (Tempest CreateTableStatement pattern)', function () {
    test('maps array containing different polymorphic statements when closure is untyped', function () {
        $statements = [
            new FixturePrimaryKeyStatement('id'),
            new FixtureTextStatement('body'),
        ];

        $result = testUntypedClosureMap($statements, fn ($stmt) => $stmt->name);

        expect($result)->toBe(['id', 'body']);
    });
});
