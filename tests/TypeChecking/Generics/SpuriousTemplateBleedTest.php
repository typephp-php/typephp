<?php

declare(strict_types=1);

/**
 * A generic key-value store holding items.
 *
 * @template K
 * @template V
 */
class KeyValueStoreSim
{
    /**
     * @param array<K, V> $items
     */
    public function __construct(
        public array $items = []
    ) {
    }
}

/**
 * An entry object holding key and value.
 *
 * @template K
 * @template V
 */
class EntrySim
{
    public function __construct(
        public mixed $key,
        public mixed $value
    ) {
    }
}

/**
 * An entry store that receives KeyValueStoreSim<K, V>.
 * Implements IteratorAggregate, but yields `int => EntrySim<K, V>`, NOT `K => V`.
 *
 * @template K
 * @template V
 *
 * @implements IteratorAggregate<int, EntrySim<K, V>>
 */
class EntryStoreSim implements IteratorAggregate
{
    /**
     * @param KeyValueStoreSim<K, V> $store
     */
    public function __construct(
        public KeyValueStoreSim $store
    ) {
    }

    public function getIterator(): Traversable
    {
        foreach ($this->store->items as $k => $v) {
            yield new EntrySim($k, $v);
        }
    }
}

/**
 * An operation whose template names happen to match the store: K and V.
 *
 * @template K
 * @template V
 */
class OperationWithMatchingTemplateNames
{
    /**
     * @param iterable<K, V> $data
     */
    public function __construct(
        public iterable $data
    ) {
    }

    public function run(): array
    {
        $items = [];
        foreach ($this->data as $k => $v) {
            $items[$k] = $v;
        }

        return $items;
    }
}

describe('Spurious Template Bleed into Traversable Wrapper', function () {
    test('fails because TypePHP assumes Traversable templates K and V are its yielded types', function () {
        $kvStore = new KeyValueStoreSim(['user' => 'Alice']);

        $entryStore = new EntryStoreSim($kvStore);

        $operation = new OperationWithMatchingTemplateNames($entryStore);

        $operation->run();

        expect(true)->toBeTrue();
    });
});
