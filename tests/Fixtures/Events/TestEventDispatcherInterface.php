<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Events;

interface TestEventDispatcherInterface
{
    /**
     * @template TEvent of object
     *
     * @param TEvent $event
     *
     * @return TEvent
     */
    public function dispatch(object $event): object;
}
