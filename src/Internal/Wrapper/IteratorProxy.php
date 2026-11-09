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
    private ?Iterator $inner = null;

    /**
     * @param Traversable<mixed, mixed> $iterable
     * @param \Closure(mixed, mixed): void $typeCheckCallback
     */
    public function __construct(
        private Traversable $iterable,
        private \Closure $typeCheckCallback
    ) {
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

        /** @var Iterator $iterable */
        return $iterable;
    }

    private function getInner(): Iterator
    {
        if ($this->inner === null) {
            $this->inner = self::resolveIterator($this->iterable);
        }

        return $this->inner;
    }

    public function rewind(): void
    {
        if ($this->iterable instanceof IteratorAggregate) {
            $this->inner = self::resolveIterator($this->iterable);
        } else {
            $this->inner ??= self::resolveIterator($this->iterable);
        }

        if (! ($this->inner instanceof \Generator)) {
            $this->inner->rewind();
        }
    }

    public function valid(): bool
    {
        return $this->getInner()->valid();
    }

    public function current(): mixed
    {
        $key = $this->getInner()->key();
        $val = $this->getInner()->current();
        ($this->typeCheckCallback)($key, $val);

        return $val;
    }

    public function key(): mixed
    {
        return $this->getInner()->key();
    }

    public function next(): void
    {
        $this->getInner()->next();
    }

    public function getInnerIterator(): Iterator
    {
        return $this->getInner();
    }

    public function count(): int
    {
        if ($this->iterable instanceof Countable) {
            return $this->iterable->count();
        }

        $inner = $this->getInner();
        if ($inner instanceof Countable) {
            return $inner->count();
        }

        return iterator_count($inner);
    }

    /**
     * @param array<int|string, mixed> $args
     */
    public function __call(string $method, array $args): mixed
    {
        if (method_exists($this->iterable, $method)) {
            // @phpstan-ignore method.dynamicName
            return $this->iterable->$method(...$args);
        }

        $inner = $this->getInner();
        if ($inner !== $this->iterable && method_exists($inner, $method)) {
            // @phpstan-ignore method.dynamicName
            return $inner->$method(...$args);
        }

        // @phpstan-ignore method.dynamicName
        return $this->iterable->$method(...$args);
    }
}
