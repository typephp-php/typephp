<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
class GenericMapFixture
{
    /**
     * @var array<mixed, mixed>
     */
    public array $elements = [];

    /**
     * @param array<mixed, mixed> $elements
     */
    public function __construct(array $elements = [])
    {
        $this->elements = $elements;
    }

    /**
     * Factory recreating a new map instance from an un-reified generator or array.
     *
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<NK, NV> $data
     *
     * @return GenericMapFixture<NK, NV>
     */
    public static function create(iterable $data): GenericMapFixture
    {
        $items = [];
        foreach ($data as $k => $v) {
            $items[$k] = $v;
        }

        return new self($items);
    }

    /**
     * Method preserving K and V, returning through factory reconstruction.
     *
     * @return GenericMapFixture<K, V>
     */
    public function filter(): GenericMapFixture
    {
        return self::create($this->elements);
    }
}

/**
 * @return GenericMapFixture<string, int>
 */
function getFilteredMap(): GenericMapFixture
{
    /** @var GenericMapFixture<string, int> $map */
    $map = new GenericMapFixture(['a' => 1, 'b' => 2]);

    return $map->filter();
}

describe('Factory Re-construction Generic Return Variance', function () {
    test('specializes instance template from declared upper bound to return type on return', function () {
        $result = getFilteredMap();

        expect($result)->toBeInstanceOf(GenericMapFixture::class)
            ->and($result->elements)->toBe(['a' => 1, 'b' => 2])
        ;
    });
});
