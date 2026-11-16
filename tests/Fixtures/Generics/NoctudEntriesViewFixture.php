<?php

declare(strict_types=1);

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @extends IteratorAggregate<K, V>
 */
interface EntriesViewKeyValueStore extends IteratorAggregate
{
    public function count(): int;

    /**
     * @return list<K>
     */
    public function keys(): array;

    /**
     * @return list<V>
     */
    public function values(): array;
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewKeyValueStore<K, V>
 */
final class EntriesViewHashStore implements EntriesViewKeyValueStore
{
    /**
     * @var array<int|string, K>
     */
    private array $keys = [];

    /**
     * @var array<int|string, V>
     */
    private array $values = [];

    /**
     * @param array<int|string, K> $keys
     * @param array<int|string, V> $values
     */
    private function __construct(array $keys = [], array $values = [])
    {
        $this->keys = $keys;
        $this->values = $values;
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<NK, NV> $source
     *
     * @return self<NK, NV>
     */
    public static function fromAssoc(iterable $source): self
    {
        $keys = [];
        $values = [];
        foreach ($source as $k => $v) {
            $hash = 'k:' . (string) $k;
            $keys[$hash] = $k;
            $values[$hash] = $v;
        }

        return new self($keys, $values);
    }

    public function count(): int
    {
        return \count($this->values);
    }

    /**
     * @return list<K>
     */
    public function keys(): array
    {
        return array_values($this->keys);
    }

    /**
     * @return list<V>
     */
    public function values(): array
    {
        return array_values($this->values);
    }

    /**
     * @return Traversable<K, V>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->keys as $hash => $k) {
            yield $k => $this->values[$hash];
        }
    }
}

/**
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface EntriesViewReadOnlyElementStore extends IteratorAggregate
{
    /**
     * @return list<E>
     */
    public function toArray(): array;
}

/**
 * @template E of string|int|bool|float|object
 *
 * @implements EntriesViewReadOnlyElementStore<E>
 */
final class EntriesViewMapKeyStore implements EntriesViewReadOnlyElementStore
{
    public function __construct(
        /**
         * @var EntriesViewKeyValueStore<E, mixed>
         */
        private readonly EntriesViewKeyValueStore $store,
    ) {
    }

    /**
     * @return Traversable<int, E>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->store as $k => $v) {
            yield $k;
        }
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->keys();
    }
}

/**
 * POISON SOURCE B: the key type is hardcoded to the upper bound union here,
 * exactly as in Noctud's MapValueStore. Reading $map->values pushes
 * KeyValueStore<union, int> onto the shared store.
 *
 * @template E
 *
 * @implements EntriesViewReadOnlyElementStore<E>
 */
final class EntriesViewMapValueStore implements EntriesViewReadOnlyElementStore
{
    public function __construct(
        /**
         * @var EntriesViewKeyValueStore<string|int|bool|float|object, E>
         */
        private readonly EntriesViewKeyValueStore $store,
    ) {
    }

    /**
     * @return Traversable<int, E>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->store as $v) {
            yield $v;
        }
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->values();
    }
}

/**
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface EntriesViewCollection extends IteratorAggregate
{
}

/**
 * @template E
 *
 * @implements EntriesViewCollection<E>
 */
final class EntriesViewMapValueCollection implements EntriesViewCollection
{
    /**
     * @var EntriesViewReadOnlyElementStore<E>
     */
    protected EntriesViewReadOnlyElementStore $store;

    /**
     * @template TK of string|int|bool|float|object
     *
     * @param EntriesViewKeyValueStore<TK, E>|EntriesViewReadOnlyElementStore<E> $store
     */
    public function __construct(EntriesViewKeyValueStore|EntriesViewReadOnlyElementStore $store)
    {
        $this->store = $store instanceof EntriesViewReadOnlyElementStore
            ? $store
            : new EntriesViewMapValueStore($store);
    }

    /**
     * @return Traversable<int, E>
     */
    public function getIterator(): Traversable
    {
        return $this->store->getIterator();
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->toArray();
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
interface EntriesViewMapEntry
{
    /**
     * @var K
     */
    public string|int|bool|float|object $key { get; }

    /**
     * @var V
     */
    public mixed $value { get; }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewMapEntry<K, V>
 */
final class EntriesViewSimpleMapEntry implements EntriesViewMapEntry
{
    /**
     * @param K $key
     * @param V $value
     */
    public function __construct(
        /**
         * @var K
         */
        public readonly string|int|bool|float|object $key,
        /**
         * @var V
         */
        public readonly mixed $value,
    ) {
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewReadOnlyElementStore<EntriesViewMapEntry<K, V>>
 */
final class EntriesViewMapEntryStore implements EntriesViewReadOnlyElementStore
{
    public function __construct(
        /**
         * @var EntriesViewKeyValueStore<K, V>
         */
        private readonly EntriesViewKeyValueStore $store,
    ) {
    }

    /**
     * @return Traversable<int, EntriesViewMapEntry<K, V>>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->store as $k => $v) {
            yield new EntriesViewSimpleMapEntry($k, $v);
        }
    }

    /**
     * @return list<EntriesViewMapEntry<K, V>>
     */
    public function toArray(): array
    {
        return iterator_to_array($this, false);
    }
}

/**
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface EntriesViewSet extends IteratorAggregate
{
}

/**
 * @template E
 *
 * @property EntriesViewReadOnlyElementStore<E> $store
 */
trait EntriesViewSetLogic
{
    /**
     * @return Traversable<int, E>
     */
    public function getIterator(): Traversable
    {
        return $this->store->getIterator();
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->toArray();
    }
}

/**
 * @template E of string|int|bool|float|object
 *
 * @implements EntriesViewSet<E>
 */
final class EntriesViewMapKeySet implements EntriesViewSet
{
    /** @use EntriesViewSetLogic<E> */
    use EntriesViewSetLogic;

    /**
     * @var EntriesViewReadOnlyElementStore<E>
     */
    protected EntriesViewReadOnlyElementStore $store;

    /**
     * @template TV
     *
     * @param EntriesViewKeyValueStore<E, TV>|EntriesViewReadOnlyElementStore<E> $store
     */
    public function __construct(EntriesViewKeyValueStore|EntriesViewReadOnlyElementStore $store)
    {
        $this->store = $store instanceof EntriesViewReadOnlyElementStore
            ? $store
            : new EntriesViewMapKeyStore($store);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewSet<EntriesViewMapEntry<K, V>>
 */
final class EntriesViewMapEntrySet implements EntriesViewSet
{
    /** @use EntriesViewSetLogic<EntriesViewMapEntry<K, V>> */
    use EntriesViewSetLogic;

    /**
     * @var EntriesViewReadOnlyElementStore<EntriesViewMapEntry<K, V>>
     */
    protected EntriesViewReadOnlyElementStore $store;

    /**
     * @param EntriesViewKeyValueStore<K, V>|EntriesViewReadOnlyElementStore<EntriesViewMapEntry<K, V>> $store
     */
    public function __construct(EntriesViewKeyValueStore|EntriesViewReadOnlyElementStore $store)
    {
        $this->store = $store instanceof EntriesViewReadOnlyElementStore
            ? $store
            : new EntriesViewMapEntryStore($store);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
interface EntriesViewMap
{
    /**
     * @var EntriesViewSet<K>
     */
    public EntriesViewSet $keys { get; }

    /**
     * @var EntriesViewCollection<V>
     */
    public EntriesViewCollection $values { get; }

    /**
     * @var EntriesViewSet<EntriesViewMapEntry<K, V>>
     */
    public EntriesViewSet $entries { get; }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
trait EntriesViewMapLogic
{
    /**
     * @var EntriesViewKeyValueStore<K, V>
     */
    protected EntriesViewKeyValueStore $store;

    /**
     * @var EntriesViewSet<K>
     */
    public EntriesViewSet $keys {
        get => $this->keys ??= new EntriesViewMapKeySet($this->store);
    }

    /**
     * @var EntriesViewCollection<V>
     */
    public EntriesViewCollection $values {
        get => $this->values ??= new EntriesViewMapValueCollection($this->store);
    }

    /**
     * @var EntriesViewSet<EntriesViewMapEntry<K, V>>
     */
    public EntriesViewSet $entries {
        get => $this->entries ??= new EntriesViewMapEntrySet($this->store);
    }

    /**
     * Diagnostic accessor. Deliberately carries no return-type annotation: a generic
     * one would run a return check that refines the poisoned store back to K=string
     * and hides the bug.
     */
    public function internalStore(): EntriesViewKeyValueStore
    {
        return $this->store;
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewMap<K, V>
 */
final class EntriesViewImmutableHashMap implements EntriesViewMap
{
    /** @use EntriesViewMapLogic<K, V> */
    use EntriesViewMapLogic;

    /**
     * @param iterable<K, V> $data
     */
    public function __construct(iterable $data = [])
    {
        $this->store = EntriesViewHashStore::fromAssoc($data);
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<NK, NV> $data
     *
     * @return EntriesViewMap<NK, NV>
     */
    public static function of(iterable $data = []): EntriesViewMap
    {
        return new self($data);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements EntriesViewMap<K, V>
 */
final class EntriesViewMutableHashMap implements EntriesViewMap
{
    /** @use EntriesViewMapLogic<K, V> */
    use EntriesViewMapLogic;

    /**
     * @param iterable<K, V> $data
     */
    public function __construct(iterable $data = [])
    {
        $this->store = EntriesViewHashStore::fromAssoc($data);
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<NK, NV> $data
     *
     * @return EntriesViewMap<NK, NV>
     */
    public static function of(iterable $data = []): EntriesViewMap
    {
        return new self($data);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return EntriesViewMap<K, V>
 */
function entriesViewImmutableMap(iterable $data): EntriesViewMap
{
    return EntriesViewImmutableHashMap::of($data);
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return EntriesViewMap<K, V>
 */
function entriesViewMutableMap(iterable $data): EntriesViewMap
{
    return EntriesViewMutableHashMap::of($data);
}

/**
 * @param positive-int $n
 */
function entriesViewCanary(int $n): int
{
    return $n;
}
