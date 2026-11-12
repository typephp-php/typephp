<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Generator;
use IteratorAggregate;
use Traversable;
use TypePHP\Exception\TypeError;

interface GenFilterWalkable
{
}

abstract class GenFilterAnimal
{
    public function __construct(public readonly string $name)
    {
    }
}

final class GenFilterDog extends GenFilterAnimal implements GenFilterWalkable
{
}

final class GenFilterCat extends GenFilterAnimal
{
}

/**
 * Multi-hop template renaming store hierarchy matching Noctud's:
 * ReadOnlyElementStore<E> <- ReadWriteElementStore<E> <- AbstractElementStore<V> <- ArrayIndexStore<E>
 *
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface GenReadOnlyStore extends IteratorAggregate
{
}

/**
 * @template V
 *
 * @implements GenReadOnlyStore<V>
 */
abstract class GenAbstractStore implements GenReadOnlyStore
{
    /**
     * @var list<V>
     */
    protected array $elements = [];

    /**
     * @return Traversable<int, V>
     */
    public function getIterator(): Traversable
    {
        return new \ArrayIterator($this->elements);
    }

    /**
     * @return list<V>
     */
    public function toArray(): array
    {
        return $this->elements;
    }
}

/**
 * @template E
 *
 * @extends GenAbstractStore<E>
 */
final class GenArrayIndexStore extends GenAbstractStore
{
    /**
     * @param iterable<E> $source
     */
    public function __construct(iterable $source = [])
    {
        foreach ($source as $item) {
            $this->elements[] = $item;
        }
    }
}

/**
 * @template K
 * @template V
 */
abstract class GenAbstractOperation
{
    /**
     * @param iterable<K, V> $data
     */
    public function __construct(protected iterable $data)
    {
    }
}

/**
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @extends GenAbstractOperation<K, V>
 */
final class GenFilterOperation extends GenAbstractOperation
{
    /**
     * @param callable(V, K): bool $predicate
     *
     * @return Generator<K, V>
     */
    public function byPredicate(callable $predicate): Generator
    {
        foreach ($this->data as $k => $v) {
            if ($predicate($v, $k)) {
                yield $k => $v;
            }
        }
    }

    /**
     * @param callable(V): bool $predicate
     *
     * @return Generator<K, V>
     */
    public function byValue(callable $predicate): Generator
    {
        return $this->byPredicate(fn ($v, $k) => $predicate($v));
    }
}

/**
 * @template V
 *
 * @extends GenAbstractOperation<int, V>
 */
final class GenFlattenOperation extends GenAbstractOperation
{
    /**
     * @return Generator<int, (V is iterable<mixed> ? value-of<V|array{}> : V)>
     */
    public function items(): Generator
    {
        foreach ($this->data as $v) {
            if (is_iterable($v)) {
                foreach ($v as $x) {
                    yield $x;
                }
            } else {
                yield $v;
            }
        }
    }
}

/**
 * @template E
 */
final class GenImmutableCollection
{
    /**
     * @var GenArrayIndexStore<E>
     */
    private GenArrayIndexStore $store;

    /**
     * @param iterable<E> $data
     */
    public function __construct(iterable $data = [])
    {
        $this->store = $data instanceof GenArrayIndexStore
            ? clone $data
            : new GenArrayIndexStore($data);
    }

    /**
     * @template T
     *
     * @param class-string<T> $type
     *
     * @return self<T>
     */
    public function filterInstanceOf(string $type): self
    {
        return self::of((new GenFilterOperation($this->store))->byValue(fn ($v) => $v instanceof $type));
    }

    /**
     * @return self<(E is null ? never : E)>
     */
    public function filterNotNull(): self
    {
        return self::of((new GenFilterOperation($this->store))->byValue(fn ($v) => $v !== null));
    }

    /**
     * @return self<(E is iterable<mixed> ? value-of<E|array{}> : E)>
     */
    public function flatten(): self
    {
        return self::of((new GenFlattenOperation($this->store))->items());
    }

    /**
     * @param E $element
     */
    public function contains(mixed $element): bool
    {
        return \in_array($element, $this->store->toArray(), true);
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->toArray();
    }

    /**
     * @template NE
     *
     * @param iterable<NE> $data
     *
     * @return self<NE>
     */
    public static function of(iterable $data = []): self
    {
        return new self($data);
    }
}

describe('Generator-Backed Transformations (filterInstanceOf, filterNotNull, flatten)', function () {
    describe('1. filterInstanceOf() Narrowing via FilterOperation Generator', function () {
        test('narrows union element type Dog|Cat to Dog', function () {
            $dog1 = new GenFilterDog('Rex');
            $cat = new GenFilterCat('Whiskers');
            $dog2 = new GenFilterDog('Buddy');

            $collection = GenImmutableCollection::of([$dog1, $cat, $dog2]);
            $filtered = $collection->filterInstanceOf(GenFilterDog::class);

            expect($filtered->toArray())->toBe([$dog1, $dog2])
                ->and($filtered->contains($dog1))->toBeTrue()
            ;

            // Filtered collection is now strictly GenImmutableCollection<GenFilterDog>
            expect(fn () => $filtered->contains($cat))->toThrow(TypeError::class);
        });

        test('narrows union element type Dog|Cat to Walkable interface', function () {
            $dog = new GenFilterDog('Rex');
            $cat = new GenFilterCat('Whiskers');

            $collection = GenImmutableCollection::of([$dog, $cat]);
            $filtered = $collection->filterInstanceOf(GenFilterWalkable::class);

            expect($filtered->toArray())->toBe([$dog]);
        });

        test('returns empty collection typed to target class when no elements match', function () {
            $cat = new GenFilterCat('Whiskers');

            $collection = GenImmutableCollection::of([$cat]);
            $filtered = $collection->filterInstanceOf(GenFilterDog::class);

            expect($filtered->toArray())->toBe([]);
        });
    });

    describe('2. filterNotNull() Narrowing via FilterOperation Generator', function () {
        test('narrows all-null collection to empty collection of never without return violation', function () {
            $collection = GenImmutableCollection::of([null, null, null]);
            $filtered = $collection->filterNotNull();

            expect($filtered->toArray())->toBe([]);
        });
    });

    describe('3. flatten() with Conditional value-of Return Type and Multi-Hop Store Hierarchy', function () {
        test('flattens nested arrays of ints', function () {
            $collection = GenImmutableCollection::of([[1, 2], [3, 4], [5]]);
            $flattened = $collection->flatten();

            expect($flattened->toArray())->toBe([1, 2, 3, 4, 5]);
        });

        test('flattens mixed array and scalar elements', function () {
            $collection = GenImmutableCollection::of([[1, 2], 3, [4]]);
            $flattened = $collection->flatten();

            expect($flattened->toArray())->toBe([1, 2, 3, 4]);
        });

        test('preserves non-iterable object element type when flattening flat object collection', function () {
            $dog1 = new GenFilterDog('Rex');
            $dog2 = new GenFilterDog('Buddy');

            $collection = GenImmutableCollection::of([$dog1, $dog2]);
            $flattened = $collection->flatten();

            expect($flattened->toArray())->toBe([$dog1, $dog2]);
        });
    });
});
