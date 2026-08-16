<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @template T
 *
 * @implements DoctrineCollectionInterface<T>
 */
class DoctrineCollection implements DoctrineCollectionInterface
{
    /**
     * @var array<int, mixed>
     */
    private array $elements = [];

    public function add(mixed $element): bool
    {
        $this->elements[] = $element;

        return true;
    }
}
