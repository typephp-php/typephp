<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

class ConsoleErrorEvent
{
    public function __construct(public string $error = 'database error')
    {
    }
}
