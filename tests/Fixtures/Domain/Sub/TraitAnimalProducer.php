<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Domain\Sub;

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\AnimalGeneratorTrait;

/**
 * @template T of Animal|null
 *
 * @implements \IteratorAggregate<int, T>
 */
final class TraitAnimalProducer implements \IteratorAggregate
{
    /** @use AnimalGeneratorTrait<T> */
    use AnimalGeneratorTrait;

    /**
     * @param T $item
     */
    public function __construct(
        private readonly ?Animal $item = null,
    ) {
    }

    public function getIterator(): \Traversable
    {
        yield $this->item;
    }
}
