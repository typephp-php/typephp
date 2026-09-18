<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Forwarding;

final class ItemB implements ItemBase
{
    public function __construct(public string $name = 'B')
    {
    }
}
