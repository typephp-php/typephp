<?php

declare(strict_types=1);

use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;

describe('FileFilter Unit Tests', function () {
    test('returns false for null, empty, or false file paths', function () {
        expect(FileFilter::isFileExcluded(null))->toBeFalse()
            ->and(FileFilter::isFileExcluded(''))->toBeFalse()
        ;
    });

    test('excludes vendor directory paths automatically', function () {
        $vendorPath1 = 'C:/project/vendor/composer/autoload.php';
        $vendorPath2 = '/var/www/project/vendor/phpunit/phpunit/src/Framework.php';

        expect(FileFilter::isFileExcluded($vendorPath1))->toBeTrue()
            ->and(FileFilter::isFileExcluded($vendorPath2))->toBeTrue()
        ;
    });

    test('excludes storage and cache paths matching default config patterns', function () {
        Config::reset();

        $storagePath = str_replace('\\', '/', getcwd() . '/storage/framework/views/cache.php');
        $varPath = str_replace('\\', '/', getcwd() . '/var/cache/test.php');

        expect(FileFilter::isFileExcluded($storagePath))->toBeTrue()
            ->and(FileFilter::isFileExcluded($varPath))->toBeTrue()
        ;
    });

    test('allows application source and test files', function () {
        $srcPath = str_replace('\\', '/', getcwd() . '/src/TypePHP.php');
        $testPath = str_replace('\\', '/', getcwd() . '/tests/Feature/ParamContractsTest.php');

        expect(FileFilter::isFileExcluded($srcPath))->toBeFalse()
            ->and(FileFilter::isFileExcluded($testPath))->toBeFalse()
        ;
    });

    test('allows specific vendor package when included with a more specific pattern', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/my-company/whitelisted-package/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        $whitelistedPath = str_replace('\\', '/', getcwd() . '/vendor/my-company/whitelisted-package/src/Service.php');
        $otherVendorPath = str_replace('\\', '/', getcwd() . '/vendor/guzzlehttp/guzzle/src/Client.php');

        expect(FileFilter::isFileExcluded($whitelistedPath))->toBeFalse()
            ->and(FileFilter::isFileExcluded($otherVendorPath))->toBeTrue()
        ;

        Config::reset();
    });

    test('allows including or excluding single specific files', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/monolog/monolog/src/Monolog/Logger.php',
            ],
            'exclude' => [
                'src/Legacy/UnsafeFile.php',
                'vendor/**',
            ],
        ]);

        $normalSrc = str_replace('\\', '/', getcwd() . '/src/TypePHP.php');
        $excludedSingleFile = str_replace('\\', '/', getcwd() . '/src/Legacy/UnsafeFile.php');
        $includedSingleVendorFile = str_replace('\\', '/', getcwd() . '/vendor/monolog/monolog/src/Monolog/Logger.php');
        $otherVendorFile = str_replace('\\', '/', getcwd() . '/vendor/monolog/monolog/src/Monolog/Formatter.php');

        expect(FileFilter::isFileExcluded($normalSrc))->toBeFalse()
            ->and(FileFilter::isFileExcluded($excludedSingleFile))->toBeTrue()
            ->and(FileFilter::isFileExcluded($includedSingleVendorFile))->toBeFalse()
            ->and(FileFilter::isFileExcluded($otherVendorFile))->toBeTrue()
        ;

        Config::reset();
    });

    test('excludes non-PHP files automatically', function () {
        expect(FileFilter::isFileExcluded('/var/www/project/composer.json'))->toBeTrue()
            ->and(FileFilter::isFileExcluded('/var/www/project/README.md'))->toBeTrue()
            ->and(FileFilter::isFileExcluded('/var/www/project/assets/style.css'))->toBeTrue()
        ;
    });

    test('excludes a specific single file inside an explicitly included directory', function () {
        Config::set([
            'include' => [
                'app/Services/**',
            ],
            'exclude' => [
                'app/Services/LegacyService.php',
            ],
        ]);

        $normalService = str_replace('\\', '/', getcwd() . '/app/Services/UserService.php');
        $excludedService = str_replace('\\', '/', getcwd() . '/app/Services/LegacyService.php');

        expect(FileFilter::isFileExcluded($normalService))->toBeFalse()
            ->and(FileFilter::isFileExcluded($excludedService))->toBeTrue()
        ;

        Config::reset();
    });

    test('allows including entire working directory using double asterisk glob', function () {
        Config::set([
            'include' => [
                '**',
            ],
            'exclude' => [
                'vendor/**',
                'storage/**',
            ],
        ]);

        $rootPhpFile = str_replace('\\', '/', getcwd() . '/index.php');
        $deepPhpFile = str_replace('\\', '/', getcwd() . '/app/Http/Controllers/UserController.php');
        $vendorPhpFile = str_replace('\\', '/', getcwd() . '/vendor/composer/autoload.php');

        expect(FileFilter::isFileExcluded($rootPhpFile))->toBeFalse()
            ->and(FileFilter::isFileExcluded($deepPhpFile))->toBeFalse()
            ->and(FileFilter::isFileExcluded($vendorPhpFile))->toBeTrue()
        ;

        Config::reset();
    });

    test('unconditionally excludes the configured cache directory even if include pattern is **', function () {
        $customCacheDir = getcwd() . '/storage/typephp-cache';

        Config::set([
            'cache_dir' => $customCacheDir,
            'include' => [
                '**',
            ],
            'exclude' => [],
        ]);

        $cachedFilePath = str_replace('\\', '/', $customCacheDir . '/v0.1_hash123.php');
        $normalFilePath = str_replace('\\', '/', getcwd() . '/app/Models/User.php');

        expect(FileFilter::isFileExcluded($cachedFilePath))->toBeTrue()
            ->and(FileFilter::isFileExcluded($normalFilePath))->toBeFalse()
        ;

        Config::reset();
    });
});
