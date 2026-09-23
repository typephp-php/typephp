<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;

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

    test('initializes cachedConfig on demand across all getters when uninitialized and tests cached branch', function () {
        $getters = [
            'isEnabled',
            'getIgnoreTraceDepth',
            'isInlinePropertiesEnabled',
            'isInlineGenericsEnabled',
            'isInlineCallablesEnabled',
            'isInlineScalarsEnabled',
            'isInlineArraysEnabled',
            'isInlineObjectsEnabled',
            'hasActiveInlineChecks',
            'isCacheCheckMtimeEnabled',
            'isParamsOutEnabled',
            'isSelfOutEnabled',
            'isParamsEnabled',
            'isReturnsEnabled',
            'isStrictReturnGenericInvarianceEnabled',
            'isMagicPropertiesEnabled',
            'isMagicMethodsEnabled',
            'isRespectIgnoreTagsEnabled',
            'isRespectNativeNullabilityEnabled',
            'isVendorBoundaryOnlyEnabled',
            'isArrayValidationHybrid',
            'getArrayValidationStrategy',
        ];

        foreach ($getters as $getter) {
            Config::reset();
            // First call triggers: if (self::$cachedConfig === null) { self::get(); }
            $val1 = Config::$getter();

            // Second call triggers the false branch (already cached)
            $val2 = Config::$getter();

            expect($val1)->toBe($val2);
        }
    });

    test('hasActiveInlineChecks returns false when all inline var checks are disabled', function () {
        Config::set([
            'inline_vars' => [
                'generics' => false,
                'callables' => false,
                'scalars' => false,
                'arrays' => false,
                'objects' => false,
            ],
        ]);

        expect(Config::hasActiveInlineChecks())->toBeFalse();

        Config::set([
            'inline_vars' => [
                'objects' => true,
            ],
        ]);
        expect(Config::hasActiveInlineChecks())->toBeTrue();
    });

    test('handles array validation strategy configuration', function () {
        Config::set(['array_validation' => 'hybrid']);
        expect(Config::isArrayValidationHybrid())->toBeTrue()
            ->and(Config::getArrayValidationStrategy())->toBe('hybrid')
        ;

        Config::set(['array_validation' => 'full']);
        expect(Config::isArrayValidationHybrid())->toBeFalse()
            ->and(Config::getArrayValidationStrategy())->toBe('full')
        ;

        // Non-string fallback
        Config::set(['array_validation' => 12345]);
        expect(Config::getArrayValidationStrategy())->toBe('full');
    });

    test('syncFlags correctly validates and falls back on edge-case inputs', function () {
        // Invalid ignore_trace_depth values fallback to 25
        Config::set(['ignore_trace_depth' => -5]);
        expect(Config::getIgnoreTraceDepth())->toBe(25);

        Config::set(['ignore_trace_depth' => 'invalid_string']);
        expect(Config::getIgnoreTraceDepth())->toBe(25);

        Config::set(['ignore_trace_depth' => 40]);
        expect(Config::getIgnoreTraceDepth())->toBe(40);

        // Non-array inline_vars fallback
        Config::set(['inline_vars' => null]);
        expect(Config::isInlinePropertiesEnabled())->toBeTrue();
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

    test('resolves project root when installed in vendor using startingDir parameter', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_vendor_test_' . uniqid();
        $fakeVendorDir = $tempBase . '/vendor/typephp/typephp/src/Internal/Util';
        mkdir($fakeVendorDir, 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/consumer-app']));

        try {
            $resolved = Config::getProjectRoot($fakeVendorDir);
            $realTempBase = realpath($tempBase) !== false ? realpath($tempBase) : $tempBase;
            $normTempBase = rtrim(str_replace('\\', '/', (string) $realTempBase), '/');

            expect($resolved)->toBe($normTempBase);
        } finally {
            @unlink($tempBase . '/composer.json');
            @rmdir($fakeVendorDir);
            @rmdir($tempBase . '/vendor/typephp/typephp/src/Internal');
            @rmdir($tempBase . '/vendor/typephp/typephp/src');
            @rmdir($tempBase . '/vendor/typephp/typephp');
            @rmdir($tempBase . '/vendor/typephp');
            @rmdir($tempBase . '/vendor');
            @rmdir($tempBase);
        }
    });

    test('resolves project root by climbing parent directories when not in vendor', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_climb_test_' . uniqid();
        $nestedSubDir = $tempBase . '/src/Modules/Commerce/Services';
        mkdir($nestedSubDir, 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/monorepo']));

        try {
            $resolved = Config::getProjectRoot($nestedSubDir);
            $realTempBase = realpath($tempBase) !== false ? realpath($tempBase) : $tempBase;
            $normTempBase = rtrim(str_replace('\\', '/', (string) $realTempBase), '/');

            expect($resolved)->toBe($normTempBase);
        } finally {
            @unlink($tempBase . '/composer.json');
            @rmdir($nestedSubDir);
            @rmdir($tempBase . '/src/Modules/Commerce');
            @rmdir($tempBase . '/src/Modules');
            @rmdir($tempBase . '/src');
            @rmdir($tempBase);
        }
    });

    test('falls back to current directory when no composer.json or autoload.php is found after 10 parent steps', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_deep_empty_' . uniqid();
        $deepDir = $tempBase . '/1/2/3/4/5/6/7/8/9/10/11';
        mkdir($deepDir, 0777, true);

        try {
            $resolved = Config::getProjectRoot($deepDir);
            expect($resolved)->toBeString()
                ->and($resolved)->not()->toBeEmpty()
            ;
        } finally {
            for ($d = $deepDir; $d !== $tempBase; $d = dirname($d)) {
                @rmdir($d);
            }
            @rmdir($tempBase);
        }
    });

    test('isParamsOutEnabled returns false when params_out is disabled or params is disabled', function () {
        Config::set(['params' => true, 'params_out' => true]);
        expect(Config::isParamsOutEnabled())->toBeTrue();

        Config::set(['params' => true, 'params_out' => false]);
        expect(Config::isParamsOutEnabled())->toBeFalse();

        Config::set(['params' => false, 'params_out' => true]);
        expect(Config::isParamsOutEnabled())->toBeFalse();
    });

    test('set initializes cachedConfig if called when cachedConfig is null', function () {
        Config::reset();

        Config::set(['enabled' => false]);

        expect(Config::isEnabled())->toBeFalse();
    });

    test('falls back to defaultConfig extensions when typephp.php does not exist', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_no_config_' . uniqid();
        mkdir($tempDir, 0777, true);

        try {
            Config::reset();

            $ref = new ReflectionClass(Config::class);
            $prop = $ref->getProperty('projectRoot');
            $prop->setValue(null, $tempDir);

            $config = Config::get();
            expect($config['extensions'])->toBeEmpty();
        } finally {
            Config::reset();
            @rmdir($tempDir);
        }
    });

    test('hits root break when directory traversal reaches filesystem root', function () {
        $root = DIRECTORY_SEPARATOR === '/' ? '/' : 'C:/';
        $result = Config::getProjectRoot($root);

        expect($result)->toBeString()
            ->and($result)->not()->toBeEmpty();
    });
});
