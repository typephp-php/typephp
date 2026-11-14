<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use ArrayIterator;
use Closure;
use IteratorAggregate;
use Traversable;

/**
 * @template E
 */
interface TestSetInterface
{
}

/**
 * @template E of string|int|bool|float|object
 *
 * @implements TestSetInterface<E>
 */
class TestMapKeySet implements TestSetInterface
{
    /**
     * @template TV
     *
     * @param TestKeyValueStoreInterface<E, TV> $store
     */
    public function __construct(public TestKeyValueStoreInterface $store)
    {
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @extends IteratorAggregate<K, V>
 */
interface TestKeyValueStoreInterface extends IteratorAggregate
{
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements TestKeyValueStoreInterface<K, V>
 */
class TestHashKeyValueStore implements TestKeyValueStoreInterface
{
    /**
     * @var array<K, V>
     */
    public array $data = [];

    public function __construct()
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
        $store = new self();
        foreach ($source as [$k, $v]) {
            $store->data[$k] = $v;
        }

        return $store;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
interface TestMapInterface
{
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
trait TestMapLogic
{
    /**
     * @var TestKeyValueStoreInterface<K, V>
     */
    public TestKeyValueStoreInterface $store;

    /**
     * @var TestSetInterface<K>
     */
    public TestSetInterface $keys {
        get => new TestMapKeySet($this->store);
    }

    /**
     * @template R of mixed
     *
     * @param (Closure(K): R)|null $selector
     *
     * @return self<K, V>
     */
    public function sortedByKey(?Closure $selector = null): self
    {
        $map = new self();
        $map->store = clone $this->store;

        return $map;
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements TestMapInterface<K, V>
 */
class TestMutableHashMap implements TestMapInterface
{
    /** @use TestMapLogic<K, V> */
    use TestMapLogic;

    public function __construct()
    {
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<array{0: NK, 1: NV}> $data
     *
     * @return self<NK, NV>
     */
    public static function ofPairs(iterable $data = []): self
    {
        $map = new self();
        $map->store = TestHashKeyValueStore::fromPairs($data);

        return $map;
    }
}
