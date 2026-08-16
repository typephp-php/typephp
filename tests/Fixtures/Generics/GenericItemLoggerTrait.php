<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
trait GenericItemLoggerTrait
{
    /**
     * @param T $item
     */
    public function logItem(mixed $item): bool
    {
        return true;
    }
}
