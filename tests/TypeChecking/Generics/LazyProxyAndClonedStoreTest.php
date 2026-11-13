<?php

declare(strict_types=1);

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
     * @var array<int|string, mixed>
     */
    private array $data = [];

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
        $store = new self();
        foreach ($source as $k => $v) {
            $store->data[$k] = $v;
        }

        return $store;
    }

    public function count(): int
    {
        return \count($this->data);
    }

    /**
     * @return Traversable<K, V>
     */
    public function getIterator(): Traversable
    {
        yield from $this->data;
    }
}

/**
 * Note: intentionally has no upper bound on E (just like Noctud\Collection\Set\Set).
 *
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface ReproSet extends IteratorAggregate
{
}

/**
 * Note: adds upper bound on E (just like Noctud\Collection\Map\View\MapKeySet).
 *
 * @template E of string|int|bool|float|object
 *
 * @implements ReproSet<E>
 */
final class ReproMapKeySet implements ReproSet
{
    /**
     * @template TV
     *
     * @param ReproKeyValueStore<E, TV> $store
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
 */
final class ReproLazyMap
{
    /**
     * @var ReproKeyValueStore<K, V>
     */
    private ReproKeyValueStore $store;

    /**
     * @var ReproSet<K>
     */
    public ReproSet $keys {
        get => $this->keys ??= new ReproMapKeySet($this->store);
    }

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
 * @return ReproLazyMap<K, V>
 */
function reproMakeLazyMap(iterable $data): ReproLazyMap
{
    return new ReproLazyMap(fn () => $data);
}

// =========================================================================
// Pattern 2: Cloned Internal Store in Widening Methods (Noctud ImmutableListLogic)
// =========================================================================

/**
 * @template E
 *
 * @extends IteratorAggregate<non-negative-int, E>
 */
interface ReproReadOnlyElementStore extends IteratorAggregate
{
}

/**
 * Note: uses template V while subclass ArrayIndexStore uses E (just like Noctud).
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

describe('Lazy Proxy Generic Property Inference', function () {
    test('accessing $keys view on lazy map does not fail with stale upper-bound binding', function () {
        $map = reproMakeLazyMap(['a' => 1, 'b' => 2]);

        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);
    });

    test('accessing $keys view after materializing lazy map proxy preserves K = string', function () {
        $map = reproMakeLazyMap(['a' => 1, 'b' => 2]);

        expect($map->count())->toBe(2);
        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);
    });
});

describe('Cloned Internal Store in Widening Methods', function () {
    test('add() on lazily initialized immutable list does not leak unsubstituted template E', function () {
        $list = reproMakeLazyList([1, 2]);
        $result = $list->add(3);

        expect($result->toArray())->toBe([1, 2, 3]);
    });

    test('addAll() on lazily initialized immutable list does not leak unsubstituted template E', function () {
        $list = reproMakeLazyList([1]);
        $result = $list->addAll([2, 3]);

        expect($result->toArray())->toBe([1, 2, 3]);
    });

    test('add() with type widening on eager immutable list succeeds without mutating source bindings', function () {
        $list = reproListOf([1, 2]);
        $widened = $list->add('x');

        expect($widened->toArray())->toBe([1, 2, 'x']);
        expect($list->toArray())->toBe([1, 2]);
    });

    test('cloning an unrelated generic object inside a widening method does not corrupt its template bindings', function () {
        $wrapper = reproMakeWrapper(10);
        $widened = $wrapper->widenWithClonedHelper(20);

        expect($widened->getHelper()->value)->toBe('ok');
    });
});
