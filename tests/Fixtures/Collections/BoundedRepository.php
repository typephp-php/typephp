<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Collections;

use TypePHP\Tests\Fixtures\Domain\Animal;

/**
 * @template T
 *
 * @phpstan-template T of Animal
 */
class BoundedRepository
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}
