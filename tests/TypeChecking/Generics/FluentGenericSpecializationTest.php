<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

/**
 * Mutable collection modifying $this in place
 *
 * @template TKey of array-key = array-key
 * @template TValue = mixed
 */
class MutableSpecializedCollection
{
    public array $items;

    public function __construct(mixed $items = [])
    {
        $this->items = \is_array($items) ? $items : [$items];
    }

    /**
     * Modifies $this in place and returns $this as static<int, TValue>
     *
     * @return static<int, TValue>
     */
    public function values(): static
    {
        $this->items = array_values($this->items);

        return $this; // Returns $this which had TKey = array-key!
    }

    /**
     * Modifies $this in place and returns $this as static<string, TValue>
     *
     * @return static<string, TValue>
     */
    public function stringKeys(): static
    {
        $this->items = ['key_1' => reset($this->items)];

        return $this;
    }

    /**
     * Method returning invalid specialization violating the existing TValue = Dog binding
     *
     * @return static<int, Car>
     */
    public function badValueSpecialization(): static
    {
        return $this;
    }
}

describe('Fluent Mutable Generic Return Type Specialization (static<NarrowerKey, TValue>)', function () {
    test('allows mutable methods like values() to specialize broad TKey of array-key down to int on returned $this instance', function () {
        /** @var MutableSpecializedCollection<array-key, string> $collection */
        $collection = new MutableSpecializedCollection(['first' => 'Alice', 'second' => 'Bob']);

        $valuesResult = $collection->values();

        expect($valuesResult)->toBe($collection)
            ->and(TypePHP::getGenericType($valuesResult, 'TKey'))->toBe('int')
            ->and($valuesResult->items)->toBe(['Alice', 'Bob'])
        ;
    });

    test('allows mutable methods to specialize broad TKey down to string on returned $this instance', function () {
        /** @var MutableSpecializedCollection<array-key, string> $collection */
        $collection = new MutableSpecializedCollection([0 => 'Alice']);

        $stringKeysResult = $collection->stringKeys();

        expect($stringKeysResult)->toBe($collection)
            ->and(TypePHP::getGenericType($stringKeysResult, 'TKey'))->toBe('string')
        ;
    });

    test('strictly throws TypeError when return specialization violates underlying value type', function () {
        /** @var MutableSpecializedCollection<array-key, Dog> $collection */
        $collection = new MutableSpecializedCollection();

        expect(fn () => $collection->badValueSpecialization())
            ->toThrow(TypeError::class)
        ;
    });
});
