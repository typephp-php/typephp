<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
abstract class ComboAbstractParent implements ComboGenericInterface
{
    use ComboGenericTrait;

    /**
     * @param T $data
     */
    public function processData(mixed $data): bool
    {
        return true;
    }
}
