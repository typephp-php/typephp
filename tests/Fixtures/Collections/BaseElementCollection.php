<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

use Closure;
use IteratorAggregate;
use Traversable;

/**
 * Fixture mimicking Shopware's Collection with class-level @template TElement
 *
 * @template TElement
 *
 * @implements IteratorAggregate<int, TElement>
 */
abstract class BaseElementCollection implements IteratorAggregate
{
    /**
     * @var array<int, TElement>
     */
    protected array $elements = [];

    public function __construct()
    {
    }

    /**
     * Directly populates elements without method-level contract inference
     */
    public function setElementsDirectly(array $elements): void
    {
        $this->elements = $elements;
    }

    /**
     * Method using class-level template TElement in Closure parameter
     *
     * @param Closure(TElement): bool $closure
     */
    public function filter(Closure $closure): static
    {
        $filtered = array_filter($this->elements, $closure);

        $instance = new static();
        $instance->setElementsDirectly(array_values($filtered));

        return $instance;
    }

    /**
     * Method returning generator with class-level template TElement
     *
     * @return Traversable<TElement>
     */
    public function getIterator(): Traversable
    {
        yield from $this->elements;
    }

    /**
     * Method accepting iterable with class-level template TElement
     *
     * @param iterable<TElement> $items
     */
    public function mergeItems(iterable $items): void
    {
        foreach ($items as $item) {
            $this->elements[] = $item;
        }
    }

    public function count(): int
    {
        return \count($this->elements);
    }
}