<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

class UserRegisteredEvent
{
    public function __construct(public int $userId = 42)
    {
    }
}
