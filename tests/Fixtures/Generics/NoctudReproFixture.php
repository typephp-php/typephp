<?php

declare(strict_types=1);

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
interface ReproMapEntry
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
 * @implements ReproMapEntry<K, V>
 */
final class ReproSimpleMapEntry implements ReproMapEntry
{
    public function __construct(
        public readonly string|int|bool|float|object $key,
        public readonly mixed $value
    ) {
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @extends IteratorAggregate<K, V>
 */
interface ReproKeyValueStore extends IteratorAggregate
{
    public function count(): int;
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproKeyValueStore<K, V>
 */
final class ReproHashStore implements ReproKeyValueStore
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
interface ReproSet extends IteratorAggregate
{
}

/**
 * @template E
 */
interface ReproReadOnlyElementStore extends IteratorAggregate
{
}

/**
 * @template E of string|int|bool|float|object
 *
 * @implements ReproReadOnlyElementStore<E>
 */
final class ReproMapKeyStore implements ReproReadOnlyElementStore
{
    /**
     * @param ReproKeyValueStore<E, mixed> $store
     */
    public function __construct(private ReproKeyValueStore $store)
    {
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
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproReadOnlyElementStore<ReproMapEntry<K, V>>
 */
final class ReproMapEntryStore implements ReproReadOnlyElementStore
{
    /**
     * @param ReproKeyValueStore<K, V> $store
     */
    public function __construct(private ReproKeyValueStore $store)
    {
    }

    /**
     * @return Traversable<int, ReproMapEntry<K, V>>
     */
    public function getIterator(): Traversable
    {
        foreach ($this->store as $k => $v) {
            yield new ReproSimpleMapEntry($k, $v);
        }
    }
}

/**
 * @template E
 */
trait ReproSetLogic
{
    /**
     * @var ReproReadOnlyElementStore<E>
     */
    protected ReproReadOnlyElementStore $store;

    public function getIterator(): Traversable
    {
        return $this->store->getIterator();
    }
}

/**
 * @template E of string|int|bool|float|object
 *
 * @implements ReproSet<E>
 */
final class ReproMapKeySet implements ReproSet
{
    /** @use ReproSetLogic<E> */
    use ReproSetLogic;

    /**
     * @var ReproReadOnlyElementStore<E>
     */
    protected ReproReadOnlyElementStore $store;

    /**
     * @template TV
     *
     * @param ReproKeyValueStore<E, TV>|ReproReadOnlyElementStore<E> $store
     */
    public function __construct(ReproKeyValueStore|ReproReadOnlyElementStore $store)
    {
        $this->store = $store instanceof ReproReadOnlyElementStore
            ? $store
            : new ReproMapKeyStore($store);
    }
}

/**
 * Simulates Noctud\Collection\Map\View\MapEntrySet.
 *
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproSet<ReproMapEntry<K, V>>
 */
final class ReproMapEntrySet implements ReproSet
{
    /** @use ReproSetLogic<ReproMapEntry<K, V>> */
    use ReproSetLogic;

    /**
     * @var ReproReadOnlyElementStore<ReproMapEntry<K, V>>
     */
    protected ReproReadOnlyElementStore $store;

    /**
     * Union parameter exactly as in Noctud:
     *
     * @param ReproKeyValueStore<K, V>|ReproReadOnlyElementStore<ReproMapEntry<K, V>> $store
     */
    public function __construct(ReproKeyValueStore|ReproReadOnlyElementStore $store)
    {
        $this->store = $store instanceof ReproReadOnlyElementStore
            ? $store
            : new ReproMapEntryStore($store);
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 */
interface ReproMapInterface
{
    /**
     * @var ReproSet<K>
     */
    public ReproSet $keys { get; }

    /**
     * @var ReproSet<ReproMapEntry<K, V>>
     */
    public ReproSet $entries { get; }
}

/**
 * Simulates Noctud\Collection\Map\MapLogic.
 *
 * @template K of string|int|bool|float|object
 * @template V
 */
trait ReproMapLogic
{
    /**
     * @var ReproKeyValueStore<K, V>
     */
    protected ReproKeyValueStore $store;

    /**
     * @var ReproSet<K>
     */
    public ReproSet $keys {
        get => $this->keys ??= new ReproMapKeySet($this->store);
    }

    /**
     * @var ReproSet<ReproMapEntry<K, V>>
     */
    public ReproSet $entries {
        get => $this->entries ??= new ReproMapEntrySet($this->store);
    }
}

/**
 * Simulates Noctud\Collection\Map\HashMap\ImmutableHashMap.
 *
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproMapInterface<K, V>
 */
final class ReproImmutableHashMap implements ReproMapInterface
{
    /** @use ReproMapLogic<K, V> */
    use ReproMapLogic;

    /**
     * @param iterable<K, V>|Closure(): iterable<K, V> $data
     */
    public function __construct(iterable|Closure $data = [])
    {
        if ($data instanceof Closure) {
            $reflector = new ReflectionClass(ReproHashStore::class);
            $this->store = $reflector->newLazyProxy(
                fn (ReproHashStore $object): ReproHashStore => $object::fromAssoc($data())
            );
        } else {
            $this->store = ReproHashStore::fromAssoc($data);
        }
    }

    public function count(): int
    {
        return $this->store->count();
    }
}

/**
 * Simulates Noctud\Collection\Map\HashMap\MutableHashMap.
 *
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproMapInterface<K, V>
 */
final class ReproMutableHashMap implements ReproMapInterface
{
    /** @use ReproMapLogic<K, V> */
    use ReproMapLogic;

    /**
     * @param iterable<K, V>|Closure(): iterable<K, V> $data
     */
    public function __construct(iterable|Closure $data = [])
    {
        if ($data instanceof Closure) {
            $reflector = new ReflectionClass(ReproHashStore::class);
            $this->store = $reflector->newLazyProxy(
                fn (ReproHashStore $object): ReproHashStore => $object::fromAssoc($data())
            );
        } else {
            $this->store = ReproHashStore::fromAssoc($data);
        }
    }

    public function count(): int
    {
        return $this->store->count();
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return ReproImmutableHashMap<K, V>
 */
function reproMakeEagerImmutableMap(iterable $data): ReproImmutableHashMap
{
    return new ReproImmutableHashMap($data);
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return ReproImmutableHashMap<K, V>
 */
function reproMakeLazyImmutableMap(iterable $data): ReproImmutableHashMap
{
    return new ReproImmutableHashMap(fn () => $data);
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return ReproMutableHashMap<K, V>
 */
function reproMakeEagerMutableMap(iterable $data): ReproMutableHashMap
{
    return new ReproMutableHashMap($data);
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V> $data
 *
 * @return ReproMutableHashMap<K, V>
 */
function reproMakeLazyMutableMap(iterable $data): ReproMutableHashMap
{
    return new ReproMutableHashMap(fn () => $data);
}

// =========================================================================
// Pattern 2: Cloned Internal Store in Widening Methods (Noctud ImmutableListLogic)
// =========================================================================

/**
 * Note: uses template V while subclass uses E (matches Noctud).
 *
 * @template V
 *
 * @extends ReproReadOnlyElementStore<V>
 */
interface ReproReadWriteIndexedStore extends ReproReadOnlyElementStore
{
    /**
     * @param V $element
     */
    public function add(mixed $element): void;

    /**
     * @param iterable<V> $source
     */
    public function addAll(iterable $source): void;
}

/**
 * @template E
 *
 * @implements ReproReadWriteIndexedStore<E>
 */
final class ReproArrayIndexStore implements ReproReadWriteIndexedStore
{
    /**
     * @var list<E>
     */
    private array $elements = [];

    /**
     * @param iterable<E> $source
     */
    public function __construct(iterable $source = [])
    {
        foreach ($source as $el) {
            $this->elements[] = $el;
        }
    }

    /**
     * @param E $element
     */
    public function add(mixed $element): void
    {
        $this->elements[] = $element;
    }

    /**
     * @param iterable<E> $source
     */
    public function addAll(iterable $source): void
    {
        foreach ($source as $el) {
            $this->elements[] = $el;
        }
    }

    /**
     * @return Traversable<non-negative-int, E>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->elements);
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->elements;
    }
}

/**
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface ReproImmutableList extends IteratorAggregate
{
    /**
     * @template NE
     *
     * @param NE $element
     *
     * @return ReproImmutableList<E|NE>
     */
    public function add(mixed $element): ReproImmutableList;

    /**
     * @template NE
     *
     * @param iterable<NE> $elements
     *
     * @return ReproImmutableList<E|NE>
     */
    public function addAll(iterable $elements): ReproImmutableList;

    /**
     * @return list<E>
     */
    public function toArray(): array;
}

/**
 * @template E
 *
 * @implements ReproImmutableList<E>
 */
final class ReproImmutableArrayList implements ReproImmutableList
{
    /**
     * @var ReproReadWriteIndexedStore<E>
     */
    private ReproReadWriteIndexedStore $store;

    /**
     * @param iterable<E>|Closure(): iterable<E> $data
     */
    public function __construct(iterable|Closure $data = [])
    {
        if ($data instanceof Closure) {
            $reflector = new ReflectionClass(ReproArrayIndexStore::class);
            $this->store = $reflector->newLazyProxy(
                fn (): ReproArrayIndexStore => new ReproArrayIndexStore($data())
            );
        } elseif ($data instanceof ReproArrayIndexStore) {
            $this->store = clone $data;
        } else {
            $this->store = new ReproArrayIndexStore($data);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function add(mixed $element): ReproImmutableList
    {
        $store = clone $this->store;
        $store->add($element);

        return $this->newCollectionOf($store);
    }

    /**
     * {@inheritDoc}
     */
    public function addAll(iterable $elements): ReproImmutableList
    {
        $store = clone $this->store;
        $store->addAll($elements);

        return $this->newCollectionOf($store);
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return iterator_to_array($this->store, false);
    }

    /**
     * @return Traversable<int, E>
     */
    public function getIterator(): Traversable
    {
        return $this->store->getIterator();
    }

    /**
     * @template NE
     *
     * @param iterable<NE> $data
     *
     * @return ReproImmutableList<NE>
     */
    private function newCollectionOf(iterable $data): ReproImmutableList
    {
        return reproListOf($data);
    }
}

/**
 * @template E
 *
 * @param iterable<E>|Closure(): iterable<E> $data
 *
 * @return ReproImmutableList<E>
 */
function reproListOf(iterable|Closure $data = []): ReproImmutableList
{
    return new ReproImmutableArrayList($data);
}

/**
 * @template E
 *
 * @param iterable<E> $data
 *
 * @return ReproImmutableList<E>
 */
function reproMakeLazyList(iterable $data): ReproImmutableList
{
    return reproListOf(fn () => $data);
}

/**
 * @template T
 */
final class ReproTokenBox
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}

/**
 * @template E
 */
final class ReproWrapper
{
    /**
     * @param ReproTokenBox<string> $helper
     */
    public function __construct(private ReproTokenBox $helper)
    {
    }

    /**
     * @template NE
     *
     * @param NE $element
     *
     * @return ReproWrapper<E|NE>
     */
    public function widenWithClonedHelper(mixed $element): self
    {
        $clonedHelper = clone $this->helper;

        return new self($clonedHelper);
    }

    /**
     * @return ReproTokenBox<string>
     */
    public function getHelper(): ReproTokenBox
    {
        return $this->helper;
    }
}

/**
 * @template E
 *
 * @param E $seed
 *
 * @return ReproWrapper<E>
 */
function reproMakeWrapper(mixed $seed): ReproWrapper
{
    return new ReproWrapper(new ReproTokenBox('ok'));
}
