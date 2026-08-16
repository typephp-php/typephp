<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Dog;

class SingleLineInlineTraitUseService
{
    /** @use GenericItemLoggerTrait<Dog> */
    use GenericItemLoggerTrait;
}