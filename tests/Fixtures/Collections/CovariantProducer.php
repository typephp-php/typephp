<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

/**
 * @template T
 *
 * @phpstan-template-covariant T
 *
 * @template-implements CovariantProducerInterface<T>
 */
class CovariantProducer implements CovariantProducerInterface
{
    /**
     * @param T $item
     */
    public function __construct(private mixed $item)
    {
    }

    public function get(): mixed
    {
        return $this->item;
    }
}
