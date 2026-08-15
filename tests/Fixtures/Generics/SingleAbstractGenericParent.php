<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
abstract class SingleAbstractGenericParent
{
    /**
     * @param T $item
     */
    public function setItem(mixed $item): bool
    {
        return true;
    }
}