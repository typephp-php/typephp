<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Commands\Core;

/**
 * @template TValue
 */
abstract class ValueCommand
{
    /**
     * @param TValue $value
     */
    public function __construct(public mixed $value)
    {
    }
}
