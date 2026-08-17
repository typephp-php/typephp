<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

class ConcreteFileCollection implements IteratorAggregate
{
    /**
     * @var array<int, string>
     */
    private array $files = [];

    public function add(string $file): void
    {
        $this->files[] = $file;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->files);
    }
}
