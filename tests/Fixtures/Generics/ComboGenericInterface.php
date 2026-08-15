<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template K of array-key
 */
interface ComboGenericInterface
{
    /**
     * @param K $key
     */
    public function setKey(mixed $key): bool;
}
