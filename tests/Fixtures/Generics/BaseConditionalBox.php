<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @template T
 */
abstract class BaseConditionalBox
{
    /**
     * @param mixed $input
     *
     * @return (T is Dog ? positive-int : non-empty-string)
     */
    public function processInput(mixed $input): mixed
    {
        return $input;
    }
}
