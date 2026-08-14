<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

class NestedAggregateService implements IteratorAggregate, Countable
{
    /**
     * @param array<string, int> $items
     */
    public function __construct(
        private array $items = ['alpha' => 10, 'beta' => 20]
    ) {
    }

    public function getIterator(): Traversable
    {
        return new class ($this->items) implements IteratorAggregate {
            public function __construct(private array $data)
            {
            }

            public function getIterator(): Traversable
            {
                return new ArrayIterator($this->data);
            }
        };
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function getCustomMetadata(): string
    {
        return 'custom_metadata_string';
    }
}
