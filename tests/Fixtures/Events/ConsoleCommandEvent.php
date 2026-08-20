<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

class ConsoleCommandEvent
{
    public function __construct(public string $command = 'migrate')
    {
    }
}