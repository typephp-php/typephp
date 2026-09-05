<?php

declare(strict_types=1);

namespace TypePHP\Internal\Wrapper;

use Countable;
use Iterator;
use IteratorAggregate;
use OuterIterator;
use Traversable;

/**
 * @internal Proxy wrapper around Traversable objects to evaluate type contracts on current items while preserving rewindability, Countable support, and method forwarding.
 *
 * @implements OuterIterator<mixed, mixed>
 */
final class IteratorProxy implements OuterIterator, Countable
{
    private Iterator $inner;

    /**
     * @param Traversable<mixed, mixed> $iterable
     * @param \Closure(mixed, mixed): void $typeCheckCallback
     */
    public function __construct(
        Traversable $iterable,
        private \Closure $typeCheckCallback
    ) {
        $this->inner = self::resolveIterator($iterable);
    }

    /**
     * Recursively unwraps IteratorAggregate instances until a concrete Iterator is found.
     *
     * @param Traversable<mixed, mixed> $iterable
     */
    private static function resolveIterator(Traversable $iterable): Iterator
    {
        while ($iterable instanceof IteratorAggregate) {
            $iterable = $iterable->getIterator();
        }

        if ($iterable instanceof Iterator) {
            return $iterable;
        }

        return new \ArrayIterator(iterator_to_array($iterable));
    }

    public function rewind(): void
    {
        $this->inner->rewind();
    }

    public function valid(): bool
    {
        return $this->inner->valid();
    }

    public function current(): mixed
    {
        $key = $this->inner->key();
        $val = $this->inner->current();
        ($this->typeCheckCallback)($key, $val);

        return $val;
    }

    public function key(): mixed
    {
        return $this->inner->key();
    }

    public function next(): void
    {
        $this->inner->next();
    }

    public function getInnerIterator(): Iterator
    {
        return $this->inner;
    }

    public function count(): int
    {
        if ($this->inner instanceof Countable) {
            return $this->inner->count();
        }

        return iterator_count($this->inner);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        // @phpstan-ignore method.dynamicName
        return $this->inner->$method(...$args);
    }
}
