<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

abstract class BaseShiftedAbstractService implements ShiftedInterfaceContract
{
    /**
     * @param list<positive-int> $items
     */
    abstract public function processItems(array $items): bool;
}