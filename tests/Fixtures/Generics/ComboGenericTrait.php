<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template V
 */
trait ComboGenericTrait
{
    /**
     * @param V $val
     */
    public function setVal(mixed $val): bool
    {
        return true;
    }
}
