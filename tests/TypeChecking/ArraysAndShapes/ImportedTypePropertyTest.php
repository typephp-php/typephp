<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\Imported\DbalKernelPluginLoader;
use TypePHP\Tests\Fixtures\Types\Imported\ClassUsingTraitWithAlias;

describe('Class-Level Imported Types on Properties', function () {
    
    test('resolves @phpstan-type and @phpstan-import-type on class properties', function () {
        $loader = new DbalKernelPluginLoader();
        $loader->load();
        
        expect($loader->pluginInfos)->toHaveCount(1);
        expect($loader->pluginInfos[0]['name'])->toBe('SwagPayPal');
    });
    
    test('fails correctly when the imported array shape is actually violated', function () {
        $loader = new DbalKernelPluginLoader();
        
        expect(fn() => $loader->loadBad())
            ->toThrow(\TypeError::class, "['active']");
    });

    test('resolves overridden aliases in child class without breaking parent inheritance', function () {
        $loader = new DbalKernelPluginLoader();
        
        $loader->config = ['retries' => 5, 'strict' => false];
        expect($loader->config)->toBe(['retries' => 5, 'strict' => false]);
        
        expect(fn() => $loader->config = ['retries' => -1, 'strict' => false])
            ->toThrow(\TypeError::class, "['retries'] must be of type positive-int");
    });

    test('resolves aliases defined on traits applied to properties inside the trait', function () {
        $instance = new ClassUsingTraitWithAlias();

        expect($instance->coordinates)->toBe(['x' => 0, 'y' => 0]);

        $instance->coordinates = ['x' => 10, 'y' => 20];
        expect($instance->coordinates['x'])->toBe(10);

        expect(fn() => $instance->coordinates = ['x' => 10, 'y' => 'invalid'])
            ->toThrow(\TypeError::class, "['y'] must be of type int");
    });
});