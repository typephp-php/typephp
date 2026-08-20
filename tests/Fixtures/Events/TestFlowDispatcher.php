<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

class TestFlowDispatcher implements TestEventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        return $event;
    }
}