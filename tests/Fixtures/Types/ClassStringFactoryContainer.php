<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

use Countable;

class ClassStringFactoryContainer
{
    /**
     * @param class-string<Countable> $class
     */
    public static function makeCountable(string $class): string
    {
        return $class;
    }
}
