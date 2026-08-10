<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
abstract class BaseGenericClonable
{
    /**
     * @var T
     */
    public mixed $item;

    public function __construct(mixed $item)
    {
        $this->item = $item;
    }

    /**
     * Parent method executing clone $this
     */
    public function duplicate(): static
    {
        return clone $this;
    }
}
