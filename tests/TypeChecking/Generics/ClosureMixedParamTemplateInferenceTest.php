<?php

declare(strict_types=1);

/**
 * @template TKey of array-key
 * @template TValue
 */
class BoundedKeyCollectionFixture
{
    /**
     * @var array<TKey, TValue>
     */
    public array $items;

    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * @template TMapValue
     *
     * @param Closure(TValue, TKey): TMapValue $map
     *
     * @return self<TKey, TMapValue>
     */
    public function map(Closure $map): self
    {
        $result = [];
        foreach ($this->items as $key => $val) {
            $result[$key] = $map($val, $key);
        }

        return new self($result);
    }
}

describe('Closure Parameter Type Inference with Bounded Templates', function () {
    test('does not overwrite bounded TKey with mixed when closure parameter specifies mixed $key (Tempest map pattern)', function () {
        $collection = new BoundedKeyCollectionFixture(['a', 'b']);

        $mapped = $collection->map(fn (string $value, mixed $key) => $value . $key);

        expect($mapped->items)->toBe(['a0', 'b1']);
    });
});
