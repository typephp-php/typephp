<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

use Closure;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @template TElement
 * @template TKey of array-key = array-key
 *
 * @implements IteratorAggregate<TKey, TElement>
 */
abstract class ShopwareCollection implements IteratorAggregate, Countable
{
    /**
     * @var array<TKey, TElement>
     */
    protected array $elements = [];

    /**
     * Directly populates elements without method contract inference
     */
    public function setElementsDirectly(array $elements): void
    {
        $this->elements = $elements;
    }

    /**
     * @param TElement $element
     */
    protected function validateType(mixed $element): void
    {
    }

    /**
     * @param Closure(TElement): bool $closure
     */
    public function filter(Closure $closure): static
    {
        $filtered = array_filter($this->elements, $closure);

        $instance = new static();
        $instance->setElementsDirectly($filtered);

        return $instance;
    }

    /**
     * @return Traversable<TElement>
     */
    public function getIterator(): Traversable
    {
        yield from $this->elements;
    }

    public function count(): int
    {
        return \count($this->elements);
    }
}
