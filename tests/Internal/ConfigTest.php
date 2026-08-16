<?php

declare(strict_types=1);

use TypePHP\Internal\Config;

describe('Config Unit Tests', function () {
    afterEach(function () {
        Config::reset();
    });

    test('loads default configuration array', function () {
        $config = Config::get();

        expect($config)->toBeArray()
            ->and($config)->toHaveKey('enabled')
            ->and($config)->toHaveKey('cache')
            ->and($config)->toHaveKey('cache_dir')
            ->and($config['cache_dir'])->toBeNull()
        ;
    });

    test('dynamically overrides configuration settings with set', function () {
        Config::set([
            'inline_vars' => [
                'scalars' => false,
            ],
        ]);

        $config = Config::get();

        expect($config['inline_vars']['scalars'])->toBeFalse();
    });

    test('resets configuration cache with reset', function () {
        Config::set(['cache' => false]);
        expect(Config::get()['cache'])->toBeFalse();

        Config::reset();
        expect(Config::get())->toBeArray();
    });

    test('resolves and memoizes project root path via getProjectRoot', function () {
        $root1 = Config::getProjectRoot();
        $root2 = Config::getProjectRoot();

        expect($root1)->toBeString()
            ->and($root1)->not()->toBeEmpty()
            ->and(is_dir($root1))->toBeTrue()
            ->and($root1)->toBe($root2)
            ->and(file_exists($root1 . '/composer.json') || file_exists($root1 . '/vendor/autoload.php'))->toBeTrue()
        ;
    });

    test('loads typephp.php from project root directory', function () {
        $projectRoot = Config::getProjectRoot();
        $configFile = $projectRoot . '/typephp.php';

        if (file_exists($configFile)) {
            $config = Config::get();
            expect($config)->toBeArray()
                ->and($config['include'])->toBeArray()
            ;
        }
    });
});