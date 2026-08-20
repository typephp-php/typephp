<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Doctrine;

abstract class Name
{
    public function __construct(public string $identifier)
    {
    }
}
