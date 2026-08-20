<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Events\ConsoleCommandEvent;
use TypePHP\Tests\Fixtures\Events\ConsoleErrorEvent;
use TypePHP\Tests\Fixtures\Events\TestFlowDispatcher;
use TypePHP\Tests\Fixtures\Events\TestHybridGenericContainer;
use TypePHP\Tests\Fixtures\Events\UserRegisteredEvent;

describe('Method-Level vs Class-Level Generics (Symfony/Shopware Event Dispatcher)', function () {
    test('allows dispatching multiple different event classes on the same dispatcher instance (Method-Level Template Scoping)', function () {
        $dispatcher = new TestFlowDispatcher();

        $commandEvent = new ConsoleCommandEvent('migrate:core');
        $result1 = $dispatcher->dispatch($commandEvent);
        expect($result1)->toBeInstanceOf(ConsoleCommandEvent::class);

        $errorEvent = new ConsoleErrorEvent('connection timeout');
        $result2 = $dispatcher->dispatch($errorEvent);
        expect($result2)->toBeInstanceOf(ConsoleErrorEvent::class);

        $userEvent = new UserRegisteredEvent(100);
        $result3 = $dispatcher->dispatch($userEvent);
        expect($result3)->toBeInstanceOf(UserRegisteredEvent::class);
    });

    test('maintains class-level template in WeakMap while allowing method-level template to vary per call', function () {
        $container = new TestHybridGenericContainer('initial_string_data');
        $res1 = $container->convert(new ConsoleCommandEvent());
        expect($res1)->toBeInstanceOf(ConsoleCommandEvent::class);

        $res2 = $container->convert(new ConsoleErrorEvent());
        expect($res2)->toBeInstanceOf(ConsoleErrorEvent::class);
    });
});
