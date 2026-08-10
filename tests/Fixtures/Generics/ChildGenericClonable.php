<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 *
 * @extends BaseGenericClonable<T>
 */
class ChildGenericClonable extends BaseGenericClonable
{
    /**
     * @param T $newItem
     */
    public function setItem(mixed $newItem): void
    {
        $this->item = $newItem;
    }
}
