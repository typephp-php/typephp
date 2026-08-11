<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\DeepInheritance\DeepAliasLevel3;
use TypePHP\Tests\Fixtures\DeepInheritance\DeepInterfaceExecutor;
use TypePHP\Tests\Fixtures\DeepInheritance\DeepLevel4Executor;

describe('Deep Inheritance Chains (Classes, Interfaces & Type Alias Imports)', function () {
    test('inherits docblock contracts across a 4-level deep class hierarchy', function () {
        $executor = new DeepLevel4Executor();

        expect($executor->processDeep(100))->toBe('deep_item_100');

        expect(fn () => $executor->processDeep(-50))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $executor->processDeep(999))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('inherits docblock contracts across a 3-level deep interface hierarchy', function () {
        $executor = new DeepInterfaceExecutor();

        expect($executor->executeDeep(500))->toBeTrue();

        expect(fn () => $executor->executeDeep(-10))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('resolves chained @phpstan-import-type across 3 classes', function () {
        $service = new DeepAliasLevel3();

        expect($service->processShape(['id' => 10, 'score' => 95]))->toBeTrue();

        expect(fn () => $service->processShape(['id' => -1, 'score' => 95]))
            ->toThrow(TypeError::class, "['id']")
        ;

        expect(fn () => $service->processShape(['id' => 10, 'score' => 150]))
            ->toThrow(TypeError::class, "['score']")
        ;
    });
});
