<?php

declare(strict_types=1);

use TypePHP\Internal\Util\Config;

function cleanTypePhpEnvVariables(): void
{
    $keys = [
        'TYPEPHP_DISABLE',
        'TYPEPHP_ENABLED',
        'TYPEPHP_AUTO_BOOT',
        'TYPEPHP_ON_VIOLATION',
        'TYPEPHP_REPORT_FILE',
        'TYPEPHP_FAIL_ON_REPORT',
        'TYPEPHP_REDACT_VALUES',
    ];

    foreach ($keys as $key) {
        putenv($key);
        putenv("{$key}=");
        unset($_ENV[$key], $_SERVER[$key]);
    }

    Config::reset();
}

describe('Config Unit Tests', function () {
    beforeEach(function () {
        cleanTypePhpEnvVariables();
    });

    afterEach(function () {
        cleanTypePhpEnvVariables();
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
            ->and($config)->toHaveKey('redact_values')
            ->and($config['redact_values'])->toBeFalse()
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
            'redact_values' => true,
        ]);

        $config = Config::get();

        expect($config['inline_vars']['scalars'])->toBeFalse()
            ->and($config['on_violation'])->toBe('report')
            ->and($config['report_file'])->toBe('var/report.json')
            ->and($config['fail_on_report'])->toBeTrue()
            ->and($config['redact_values'])->toBeTrue()
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
            'isRedactValuesEnabled',
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
        expect(Config::isMagicPropertyWritesEnabled())->toBeTrue()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeFalse()
            ->and(Config::isMagicPropertiesEnabled())->toBeTrue()
        ;

        Config::set([
            'magic_properties' => [
                'read' => true,
            ],
        ]);
        expect(Config::isMagicPropertyWritesEnabled())->toBeTrue()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeTrue()
            ->and(Config::isMagicPropertiesEnabled())->toBeTrue()
        ;

        Config::set(['magic_properties' => false]);
        expect(Config::isMagicPropertyWritesEnabled())->toBeFalse()
            ->and(Config::isMagicPropertyReadsEnabled())->toBeFalse()
            ->and(Config::isMagicPropertiesEnabled())->toBeFalse()
        ;

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

        Config::set(['array_validation' => 12345]);
        expect(Config::getArrayValidationStrategy())->toBe('full');
    });

    test('syncFlags correctly validates and falls back on edge-case inputs', function () {
        Config::set(['ignore_trace_depth' => -5]);
        expect(Config::getIgnoreTraceDepth())->toBe(25);

        Config::set(['ignore_trace_depth' => 'invalid_string']);
        expect(Config::getIgnoreTraceDepth())->toBe(25);

        Config::set(['ignore_trace_depth' => 40]);
        expect(Config::getIgnoreTraceDepth())->toBe(40);

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

    describe('Framework & Dotenv Environment Resolution ($_ENV, $_SERVER, and getenv)', function () {
        test('isEnabled dynamically reflects TYPEPHP_DISABLE from $_ENV (Laravel Dotenv compatibility)', function () {
            expect(Config::isEnabled())->toBeTrue();

            $_ENV['TYPEPHP_DISABLE'] = 'true';
            expect(Config::isEnabled())->toBeFalse();

            $_ENV['TYPEPHP_DISABLE'] = '1';
            expect(Config::isEnabled())->toBeFalse();

            $_ENV['TYPEPHP_DISABLE'] = 'false';
            expect(Config::isEnabled())->toBeTrue();

            $_ENV['TYPEPHP_DISABLE'] = '0';
            expect(Config::isEnabled())->toBeTrue();
        });

        test('isEnabled dynamically reflects TYPEPHP_DISABLE from $_SERVER', function () {
            expect(Config::isEnabled())->toBeTrue();

            $_SERVER['TYPEPHP_DISABLE'] = 'true';
            expect(Config::isEnabled())->toBeFalse();

            $_SERVER['TYPEPHP_DISABLE'] = 'false';
            expect(Config::isEnabled())->toBeTrue();
        });

        test('isEnabled dynamically reflects TYPEPHP_ENABLED from $_ENV and $_SERVER', function () {
            expect(Config::isEnabled())->toBeTrue();

            $_ENV['TYPEPHP_ENABLED'] = 'false';
            expect(Config::isEnabled())->toBeFalse();

            $_ENV['TYPEPHP_ENABLED'] = '0';
            expect(Config::isEnabled())->toBeFalse();

            unset($_ENV['TYPEPHP_ENABLED']);
            $_SERVER['TYPEPHP_ENABLED'] = 'false';
            expect(Config::isEnabled())->toBeFalse();

            $_SERVER['TYPEPHP_ENABLED'] = 'true';
            expect(Config::isEnabled())->toBeTrue();
        });

        test('isAutoBootEnabled resolves values from $_ENV and $_SERVER', function () {
            $_ENV['TYPEPHP_AUTO_BOOT'] = 'false';
            expect(Config::isAutoBootEnabled())->toBeFalse();

            $_ENV['TYPEPHP_AUTO_BOOT'] = '0';
            expect(Config::isAutoBootEnabled())->toBeFalse();

            unset($_ENV['TYPEPHP_AUTO_BOOT']);
            $_SERVER['TYPEPHP_AUTO_BOOT'] = 'false';
            expect(Config::isAutoBootEnabled())->toBeFalse();

            $_SERVER['TYPEPHP_AUTO_BOOT'] = 'true';
            expect(Config::isAutoBootEnabled())->toBeTrue();
        });

        test('getOnViolation resolves strategy from $_ENV and $_SERVER', function () {
            $_ENV['TYPEPHP_ON_VIOLATION'] = 'report';
            expect(Config::getOnViolation())->toBe('report')
                ->and(Config::isReportMode())->toBeTrue()
                ->and(Config::isWarnMode())->toBeFalse()
            ;

            $_ENV['TYPEPHP_ON_VIOLATION'] = 'warn';
            expect(Config::getOnViolation())->toBe('warn')
                ->and(Config::isReportMode())->toBeFalse()
                ->and(Config::isWarnMode())->toBeTrue()
            ;

            unset($_ENV['TYPEPHP_ON_VIOLATION']);
            $_SERVER['TYPEPHP_ON_VIOLATION'] = 'warning';
            expect(Config::getOnViolation())->toBe('warn')
                ->and(Config::isWarnMode())->toBeTrue()
            ;
        });

        test('getReportFile resolves custom file path from $_ENV and $_SERVER', function () {
            $_ENV['TYPEPHP_REPORT_FILE'] = 'storage/reports/laravel-audit.json';
            expect(Config::getReportFile())->toBe('storage/reports/laravel-audit.json');

            unset($_ENV['TYPEPHP_REPORT_FILE']);
            $_SERVER['TYPEPHP_REPORT_FILE'] = 'var/ci-report.json';
            expect(Config::getReportFile())->toBe('var/ci-report.json');
        });

        test('isFailOnReportEnabled resolves from $_ENV and $_SERVER', function () {
            $_ENV['TYPEPHP_FAIL_ON_REPORT'] = 'true';
            expect(Config::isFailOnReportEnabled())->toBeTrue();

            $_ENV['TYPEPHP_FAIL_ON_REPORT'] = '1';
            expect(Config::isFailOnReportEnabled())->toBeTrue();

            unset($_ENV['TYPEPHP_FAIL_ON_REPORT']);
            $_SERVER['TYPEPHP_FAIL_ON_REPORT'] = 'false';
            expect(Config::isFailOnReportEnabled())->toBeFalse();
        });

        test('isRedactValuesEnabled resolves from $_ENV and $_SERVER', function () {
            $_ENV['TYPEPHP_REDACT_VALUES'] = 'true';
            expect(Config::isRedactValuesEnabled())->toBeTrue();

            $_ENV['TYPEPHP_REDACT_VALUES'] = '1';
            expect(Config::isRedactValuesEnabled())->toBeTrue();

            unset($_ENV['TYPEPHP_REDACT_VALUES']);
            $_SERVER['TYPEPHP_REDACT_VALUES'] = 'false';
            expect(Config::isRedactValuesEnabled())->toBeFalse();
        });
    });

    describe('Composer.json Extra Configuration Integration', function () {
        test('reads reporting and autoboot options from composer.json extra section', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_composer_full_' . uniqid();
            mkdir($tempDir, 0777, true);

            $composerJsonContent = json_encode([
                'name' => 'test/full-composer-config',
                'extra' => [
                    'typephp' => [
                        'auto-boot' => false,
                        'on-violation' => 'report',
                        'report-file' => 'var/composer-report.json',
                        'fail-on-report' => true,
                        'redact-values' => true,
                    ],
                ],
            ]);
            file_put_contents($tempDir . '/composer.json', $composerJsonContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                expect(Config::isAutoBootEnabled())->toBeFalse()
                    ->and(Config::getOnViolation())->toBe('report')
                    ->and(Config::getReportFile())->toBe('var/composer-report.json')
                    ->and(Config::isFailOnReportEnabled())->toBeTrue()
                    ->and(Config::isRedactValuesEnabled())->toBeTrue()
                ;
            } finally {
                @unlink($tempDir . '/composer.json');
                @rmdir($tempDir);
                Config::reset();
            }
        });
    });
});
