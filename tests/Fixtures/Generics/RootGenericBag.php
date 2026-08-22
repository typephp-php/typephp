<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template TElement of object
 */
abstract class RootGenericBag
{
    /**
     * @param TElement $element
     */
    public function addItem(object $element): bool
    {
        return true;
    }
}
