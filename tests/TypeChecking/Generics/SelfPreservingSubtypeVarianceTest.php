<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Closure;
use TypePHP\Exception\TypeError;

final class SelfPreservingItem
{
    public function __construct(
        public readonly int $id,
        public readonly bool $swapped = false,
    ) {
    }
}

/**
 * @template E
 */
interface SelfPreservingSetInterface
{
    /**
     * @return list<E>
     */
    public function toArray(): array;
}

/**
 * @template K of array-key
 * @template V
 */
interface SelfPreservingMapInterface
{
    /**
     * @param K $key
     *
     * @return V
     */
    public function get(int|string $key): mixed;
}

/**
 * @template K of array-key
 * @template V
 *
 * @implements SelfPreservingMapInterface<K, V>
 */
final class SelfPreservingHashMap implements SelfPreservingMapInterface
{
    /**
     * @param array<K, V> $entries
     */
    public function __construct(private array $entries = [])
    {
    }

    /**
     * @param K $key
     *
     * @return V
     */
    public function get(int|string $key): mixed
    {
        return $this->entries[$key];
    }
}

/**
 * @template E
 *
 * @implements SelfPreservingSetInterface<E>
 */
class SelfPreservingBaseSet implements SelfPreservingSetInterface
{
    /**
     * @var list<E>
     */
    protected array $items = [];

    /**
     * @param iterable<E> $data
     */
    public function __construct(iterable $data = [])
    {
        foreach ($data as $item) {
            $this->items[] = $item;
        }
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->items;
    }

    /**
     * @template GK of array-key
     * @template GV
     *
     * @param Closure(E): GK $keySelector
     * @param (Closure(E): GV)|null $valueTransform
     *
     * @return ($valueTransform is null ? SelfPreservingMapInterface<GK, SelfPreservingSetInterface<E>> : SelfPreservingMapInterface<GK, SelfPreservingSetInterface<GV>>)
     */
    public function groupBy(Closure $keySelector, ?Closure $valueTransform = null): SelfPreservingMapInterface
    {
        $buckets = [];
        foreach ($this->items as $item) {
            $k = $keySelector($item);
            $buckets[$k][] = $valueTransform !== null ? $valueTransform($item) : $item;
        }

        $wrapped = [];
        foreach ($buckets as $k => $bucket) {
            $wrapped[$k] = $valueTransform !== null
                ? new self($bucket)
                : $this->newCollectionOf($bucket);
        }

        return new SelfPreservingHashMap($wrapped);
    }

    /**
     * @template NE
     *
     * @param iterable<NE> $data
     *
     * @return SelfPreservingSetInterface<NE>
     */
    protected function newCollectionOf(iterable $data): SelfPreservingSetInterface
    {
        return new self($data);
    }
}

/**
 * Non-generic domain subtype that fixes E = SelfPreservingItem via @implements
 * and overrides newCollectionOf() to return `new static($data)` (matching Noctud's TraitItemCollection & LineItems).
 *
 * @implements SelfPreservingSetInterface<SelfPreservingItem>
 */
final class SelfPreservingDomainSet extends SelfPreservingBaseSet implements SelfPreservingSetInterface
{
    /**
     * @param iterable<SelfPreservingItem> $data
     */
    public function __construct(iterable $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @param iterable<SelfPreservingItem> $data
     *
     * @return static
     */
    protected function newCollectionOf(iterable $data): SelfPreservingSetInterface
    {
        return new self($data);
    }
}

/**
 * Non-generic subtype fixing E = string (mismatched element type for negative test).
 *
 * @implements SelfPreservingSetInterface<string>
 */
final class SelfPreservingStringSet extends SelfPreservingBaseSet implements SelfPreservingSetInterface
{
}

/**
 * Helper to test negative case where a concrete subtype with the WRONG fixed template argument is returned.
 */
final class SelfPreservingMismatchProbe
{
    /**
     * @return SelfPreservingMapInterface<string, SelfPreservingSetInterface<SelfPreservingItem>>
     */
    public static function getMismatchedMap(): SelfPreservingMapInterface
    {
        return new SelfPreservingHashMap(['bad' => new SelfPreservingStringSet(['a', 'b'])]);
    }
}

describe('Self-Preserving Concrete Subtype Variance in Nested Generic Containers', function () {
    test('accepts concrete subtype fixing E = SelfPreservingItem when return type expects Map<K, Set<SelfPreservingItem>>', function () {
        $item1 = new SelfPreservingItem(1, true);
        $item2 = new SelfPreservingItem(2, false);

        $collection = new SelfPreservingDomainSet([$item1, $item2]);

        $groups = $collection->groupBy(static fn (SelfPreservingItem $i): string => $i->swapped ? 'y' : 'n');
        $ids = $collection->groupBy(
            static fn (SelfPreservingItem $i): string => $i->swapped ? 'y' : 'n',
            static fn (SelfPreservingItem $i): int => $i->id
        );

        expect($groups->get('y'))->toBeInstanceOf(SelfPreservingDomainSet::class)
            ->and($groups->get('y')->toArray())->toBe([$item1])
            ->and($ids->get('y')->toArray())->toBe([1])
        ;
    });

    test('rejects concrete subtype when its fixed template argument does not match expected nested generic type', function () {
        expect(fn () => SelfPreservingMismatchProbe::getMismatchedMap())
            ->toThrow(TypeError::class)
        ;
    });
});
