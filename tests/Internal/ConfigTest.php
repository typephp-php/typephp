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

    test('user include and exclude lists are replaced wholesale and do not leak default list entries', function () {
        Config::set([
            'include' => [
                'src/**',
            ],
            'exclude' => [
                'vendor/**',
                'tests/**',
                'var/**',
            ],
        ]);

        $config = Config::get();

        expect($config['include'])->toBe(['src/**'])
            ->and($config['exclude'])->toBe(['vendor/**', 'tests/**', 'var/**'])
        ;
    });

    test('inline_vars associative options are merged so single toggles can be overridden', function () {
        Config::set([
            'inline_vars' => [
                'scalars' => false,
            ],
        ]);

        $config = Config::get();

        expect($config['inline_vars']['scalars'])->toBeFalse()
            ->and($config['inline_vars']['properties'])->toBeTrue()
            ->and($config['inline_vars']['generics'])->toBeTrue()
        ;
    });

    test('resolves consumer project root when TypePHP is installed inside vendor/typephp/typephp', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_root_test_' . uniqid();
        $vendorDir = $tempBase . '/vendor/typephp/typephp/src/Internal';
        mkdir($vendorDir, 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/consumer-app']));
        file_put_contents($tempBase . '/vendor/autoload.php', '<?php');
        file_put_contents($tempBase . '/vendor/typephp/typephp/composer.json', json_encode(['name' => 'typephp/typephp']));

        try {
            $prevCwd = getcwd();
            chdir($tempBase);

            Config::reset();

            $root = Config::getProjectRoot();
            $realTempBase = realpath($tempBase) !== false ? realpath($tempBase) : $tempBase;
            $normTempBase = rtrim(str_replace('\\', '/', (string) $realTempBase), '/');

            expect($root)->toBe($normTempBase)
                ->and($root)->not()->toContain('vendor/typephp/typephp')
            ;

            if ($prevCwd !== false) {
                chdir($prevCwd);
            }
        } finally {
            @unlink($tempBase . '/composer.json');
            @unlink($tempBase . '/vendor/autoload.php');
            @unlink($tempBase . '/vendor/typephp/typephp/composer.json');
            @rmdir($tempBase . '/vendor/typephp/typephp/src/Internal');
            @rmdir($tempBase . '/vendor/typephp/typephp/src');
            @rmdir($tempBase . '/vendor/typephp/typephp');
            @rmdir($tempBase . '/vendor/typephp');
            @rmdir($tempBase . '/vendor');
            @rmdir($tempBase);
            Config::reset();
        }
    });
});
