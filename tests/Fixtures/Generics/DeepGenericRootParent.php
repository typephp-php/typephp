<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
abstract class DeepGenericRootParent
{
    /**
     * @param T $element
     */
    public function processElement(mixed $element): bool
    {
        return true;
    }
}
