<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use ArrayIterator;
use IteratorAggregate;
use Traversable;
use TypePHP\Tests\Fixtures\Generics\GenericItemContainer as AliasedGenericContainer;
use TypePHP\Tests\Fixtures\Generics\ItemContract as AliasedItemContract;

interface ItemContract
{
    public function getId(): int;
}

class ConcreteItem implements AliasedItemContract
{
    public function __construct(public int $id)
    {
    }

    public function getId(): int
    {
        return $this->id;
    }
}

/**
 * @template T
 */
class GenericItemContainer
{
    /**
     * @param T $value
     */
    public function __construct(public mixed $value)
    {
    }
}

/**
 * Case A: Traversable with an aliased import in @implements IteratorAggregate
 *
 * @implements IteratorAggregate<int, AliasedItemContract>
 */
class TraversableViaImplementsWithAlias implements IteratorAggregate
{
    public function __construct(private array $items)
    {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

/**
 * Case B: Traversable with an aliased import in getIterator() return tag
 */
class TraversableViaGetIteratorWithAlias implements IteratorAggregate
{
    public function __construct(private array $items)
    {
    }

    /**
     * @return Traversable<int, AliasedItemContract>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

/**
 * Case C: Traversable with a nested GenericTypeNode item (mirrors MapEntry<K,V>)
 *
 * @implements IteratorAggregate<int, AliasedGenericContainer<string>>
 */
class TraversableWithNestedGenericItem implements IteratorAggregate
{
    public function __construct(private array $items)
    {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

/**
 * Case D: Intermediate interface in inheritance hierarchy
 *
 * @template E
 *
 * @extends IteratorAggregate<int, E>
 */
interface BaseCollectionInterface extends IteratorAggregate
{
}

/**
 * @implements BaseCollectionInterface<AliasedItemContract>
 */
class TraversableViaInheritedInterface implements BaseCollectionInterface
{
    public function __construct(private array $items)
    {
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
