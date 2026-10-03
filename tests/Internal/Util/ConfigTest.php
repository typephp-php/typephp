<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;

describe('Config Unit Tests', function () {
    afterEach(function () {
        putenv('TYPEPHP_AUTO_BOOT');
        putenv('TYPEPHP_ON_VIOLATION');
        putenv('TYPEPHP_REPORT_FILE');
        putenv('TYPEPHP_FAIL_ON_REPORT');
        Config::reset();
    });

    test('loads default configuration array', function () {
        $config = Config::get();

        expect($config)->toBeArray()
            ->and($config)->toHaveKey('enabled')
            ->and($config)->toHaveKey('auto_boot')
            ->and($config['auto_boot'])->toBeTrue()
            ->and($config)->toHaveKey('on_violation')
            ->and($config['on_violation'])->toBe('throw')
            ->and($config)->toHaveKey('report_file')
            ->and($config['report_file'])->toBeNull()
            ->and($config)->toHaveKey('fail_on_report')
            ->and($config['fail_on_report'])->toBeFalse()
            ->and($config)->toHaveKey('cache')
            ->and($config)->toHaveKey('cache_dir')
            ->and($config['cache_dir'])->toBeNull()
            ->and($config['magic_properties'])->toBe(['write' => true, 'read' => false])
        ;
    });

    test('dynamically overrides configuration settings with set', function () {
        Config::set([
            'inline_vars' => [
                'scalars' => false,
            ],
            'on_violation' => 'report',
            'report_file' => 'var/report.json',
            'fail_on_report' => true,
        ]);

        $config = Config::get();

        expect($config['inline_vars']['scalars'])->toBeFalse()
            ->and($config['on_violation'])->toBe('report')
            ->and($config['report_file'])->toBe('var/report.json')
            ->and($config['fail_on_report'])->toBeTrue()
        ;
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
            'isAutoBootEnabled',
            'getOnViolation',
            'getReportFile',
            'isFailOnReportEnabled',
            'isReportMode',
            'isWarnMode',
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
            'isMagicPropertyWritesEnabled',
            'isMagicPropertyReadsEnabled',
            'isMagicMethodsEnabled',
            'isRespectIgnoreTagsEnabled',
            'isRespectNativeNullabilityEnabled',
            'isVendorBoundaryOnlyEnabled',
            'isArrayValidationHybrid',
            'getArrayValidationStrategy',
        ];

        foreach ($getters as $getter) {
            Config::reset();
            $val1 = Config::$getter();
            $val2 = Config::$getter();

            expect($val1)->toBe($val2);
        }
    });

    test('supports granular and boolean magic_properties configuration', function () {
        // 1. Default: write is true, read is false
        expect(Config::isMagicPropertyWritesEnabled())->toBeTrue()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeFalse()
            ->and(Config::isMagicPropertiesEnabled())->toBeTrue()
        ;

        // 2. Partial array override (enable reads)
        Config::set([
            'magic_properties' => [
                'read' => true,
            ],
        ]);
        expect(Config::isMagicPropertyWritesEnabled())->toBeTrue()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeTrue()
            ->and(Config::isMagicPropertiesEnabled())->toBeTrue()
        ;

        // 3. Boolean false override (disables both writes and reads)
        Config::set(['magic_properties' => false]);
        expect(Config::isMagicPropertyWritesEnabled())->toBeFalse()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeFalse()
            ->and(Config::isMagicPropertiesEnabled())->toBeFalse()
        ;

        // 4. Boolean true override (enables writes, keeps reads false)
        Config::set(['magic_properties' => true]);
        expect(Config::isMagicPropertyWritesEnabled())->toBeTrue()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeFalse()
            ->and(Config::isMagicPropertiesEnabled())->toBeTrue()
        ;
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
            for ($d = $deepDir; $d !== $tempBase; $d = \dirname($d)) {
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
            ->and($result)->not()->toBeEmpty()
        ;
    });

    describe('Auto-Boot Configuration Logic', function () {
        test('isAutoBootEnabled returns true by default', function () {
            expect(Config::isAutoBootEnabled())->toBeTrue();
        });

        test('isAutoBootEnabled respects runtime overrides with Config::set', function () {
            Config::set(['auto_boot' => false]);
            expect(Config::isAutoBootEnabled())->toBeFalse();

            Config::set(['auto_boot' => true]);
            expect(Config::isAutoBootEnabled())->toBeTrue();
        });

        test('isAutoBootEnabled respects TYPEPHP_AUTO_BOOT environment variable', function () {
            putenv('TYPEPHP_AUTO_BOOT=false');
            expect(Config::isAutoBootEnabled())->toBeFalse();

            putenv('TYPEPHP_AUTO_BOOT=true');
            expect(Config::isAutoBootEnabled())->toBeTrue();

            putenv('TYPEPHP_AUTO_BOOT=0');
            expect(Config::isAutoBootEnabled())->toBeFalse();

            putenv('TYPEPHP_AUTO_BOOT=1');
            expect(Config::isAutoBootEnabled())->toBeTrue();
        });

        test('isAutoBootEnabled reads extra.typephp.auto-boot from composer.json', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_composer_autoboot_' . uniqid();
            mkdir($tempDir, 0777, true);

            $composerJsonContent = json_encode([
                'name' => 'test/autoboot-test',
                'extra' => [
                    'typephp' => [
                        'auto-boot' => false,
                    ],
                ],
            ]);
            file_put_contents($tempDir . '/composer.json', $composerJsonContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                expect(Config::isAutoBootEnabled())->toBeFalse();
            } finally {
                @unlink($tempDir . '/composer.json');
                @rmdir($tempDir);
                Config::reset();
            }
        });

        test('isAutoBootEnabled prioritizes typephp.php over composer.json', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_precedence_test_' . uniqid();
            mkdir($tempDir, 0777, true);

            $composerJsonContent = json_encode([
                'name' => 'test/autoboot-test',
                'extra' => [
                    'typephp' => [
                        'auto-boot' => false,
                    ],
                ],
            ]);
            file_put_contents($tempDir . '/composer.json', $composerJsonContent);

            $typephpContent = <<<'PHP'
<?php
return [
    'auto_boot' => true,
];
PHP;
            file_put_contents($tempDir . '/typephp.php', $typephpContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                // typephp.php (true) overrides composer.json (false)
                expect(Config::isAutoBootEnabled())->toBeTrue();
            } finally {
                @unlink($tempDir . '/typephp.php');
                @unlink($tempDir . '/composer.json');
                @rmdir($tempDir);
                Config::reset();
            }
        });

        test('isAutoBootEnabled prioritizes TYPEPHP_AUTO_BOOT environment variable over all config files', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_env_priority_test_' . uniqid();
            mkdir($tempDir, 0777, true);

            $typephpContent = <<<'PHP'
<?php
return [
    'auto_boot' => false,
];
PHP;
            file_put_contents($tempDir . '/typephp.php', $typephpContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                // Environment variable (true) overrides typephp.php (false)
                putenv('TYPEPHP_AUTO_BOOT=true');
                expect(Config::isAutoBootEnabled())->toBeTrue();
            } finally {
                putenv('TYPEPHP_AUTO_BOOT');
                @unlink($tempDir . '/typephp.php');
                @rmdir($tempDir);
                Config::reset();
            }
        });
    });

    describe('Violation Handling & Reporting Configuration', function () {
        test('on_violation defaults to throw and normalizes warn/report values', function () {
            expect(Config::getOnViolation())->toBe('throw')
                ->and(Config::isReportMode())->toBeFalse()
                ->and(Config::isWarnMode())->toBeFalse()
            ;

            Config::set(['on_violation' => 'report']);
            expect(Config::getOnViolation())->toBe('report')
                ->and(Config::isReportMode())->toBeTrue()
                ->and(Config::isWarnMode())->toBeFalse()
            ;

            Config::set(['on_violation' => 'warn']);
            expect(Config::getOnViolation())->toBe('warn')
                ->and(Config::isReportMode())->toBeFalse()
                ->and(Config::isWarnMode())->toBeTrue()
            ;

            Config::set(['on_violation' => 'warning']);
            expect(Config::getOnViolation())->toBe('warn')
                ->and(Config::isWarnMode())->toBeTrue()
            ;
        });

        test('report_file defaults to null and respects custom paths', function () {
            expect(Config::getReportFile())->toBeNull();

            Config::set(['report_file' => 'var/typephp-report.json']);
            expect(Config::getReportFile())->toBe('var/typephp-report.json');

            Config::set(['report_file' => '   ']);
            expect(Config::getReportFile())->toBeNull();
        });

        test('fail_on_report defaults to false and respects boolean overrides', function () {
            expect(Config::isFailOnReportEnabled())->toBeFalse();

            Config::set(['fail_on_report' => true]);
            expect(Config::isFailOnReportEnabled())->toBeTrue();
        });

        test('respects TYPEPHP_ON_VIOLATION, TYPEPHP_REPORT_FILE, and TYPEPHP_FAIL_ON_REPORT environment variables', function () {
            putenv('TYPEPHP_ON_VIOLATION=report');
            putenv('TYPEPHP_REPORT_FILE=var/ci-report.json');
            putenv('TYPEPHP_FAIL_ON_REPORT=true');

            expect(Config::getOnViolation())->toBe('report')
                ->and(Config::isReportMode())->toBeTrue()
                ->and(Config::getReportFile())->toBe('var/ci-report.json')
                ->and(Config::isFailOnReportEnabled())->toBeTrue()
            ;

            putenv('TYPEPHP_ON_VIOLATION=warning');
            expect(Config::getOnViolation())->toBe('warn')
                ->and(Config::isWarnMode())->toBeTrue()
            ;
        });

        test('reads reporting options from composer.json extra section', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_composer_report_' . uniqid();
            mkdir($tempDir, 0777, true);

            $composerJsonContent = json_encode([
                'name' => 'test/report-config',
                'extra' => [
                    'typephp' => [
                        'on-violation' => 'report',
                        'report-file' => 'var/composer-report.json',
                        'fail-on-report' => true,
                    ],
                ],
            ]);
            file_put_contents($tempDir . '/composer.json', $composerJsonContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                expect(Config::getOnViolation())->toBe('report')
                    ->and(Config::getReportFile())->toBe('var/composer-report.json')
                    ->and(Config::isFailOnReportEnabled())->toBeTrue()
                ;
            } finally {
                @unlink($tempDir . '/composer.json');
                @rmdir($tempDir);
                Config::reset();
            }
        });
    });
});
