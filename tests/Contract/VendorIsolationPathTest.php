<?php

declare(strict_types=1);

namespace TypePHP\Tests\Contract;

use ReflectionMethod;
use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;
use TypePHP\Internal\StreamWrapper;

describe('Vendor Path Isolation & Whitelisting (Shopware Doctrine DBAL Reproduction)', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    test('strictly excludes un-whitelisted vendor files even if they contain nested src folders (Doctrine DBAL in vendor)', function () {
        Config::set([
            'include' => [
                'src/**',
                'app/**',
                'tests/**',
            ],
            'exclude' => [
                'vendor/**',
                'storage/**',
                'var/**',
                'cache/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();
        $vendorDoctrineFile = str_replace('\\', '/', $projectRoot . '/vendor/doctrine/dbal/src/Schema/AbstractNamedObject.php');
        $appFile = str_replace('\\', '/', $projectRoot . '/app/Services/UserService.php');

        expect(FileFilter::isFileExcluded($vendorDoctrineFile))->toBeTrue()
            ->and(FileFilter::isFileExcluded($appFile))->toBeFalse()
        ;

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $vendorDoctrineFile, $vendorDoctrineFile))->toBeFalse()
            ->and($refMethod->invoke(null, $appFile, $appFile))->toBeTrue()
        ;
    });

    test('allows explicitly whitelisted vendor packages while strictly excluding all other vendor files', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/my-org/whitelisted-package/**', 
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $whitelistedVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/my-org/whitelisted-package/src/Service.php');
        $unwhitelistedVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/doctrine/dbal/src/Schema/AbstractNamedObject.php');

        expect(FileFilter::isFileExcluded($whitelistedVendorFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($unwhitelistedVendorFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $whitelistedVendorFile, $whitelistedVendorFile))->toBeTrue()
            ->and($refMethod->invoke(null, $unwhitelistedVendorFile, $unwhitelistedVendorFile))->toBeFalse()
        ;
    });

    test('strictly isolates vendor files when specific nested application subpaths are included', function () {
        Config::set([
            'include' => [
                'src/**',
                'src/Core/Framework/**', 
                'src/Core/Content/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        $projectRoot = Config::getProjectRoot();
        $vendorFile = str_replace('\\', '/', $projectRoot . '/vendor/doctrine/dbal/src/Core/Table.php');

        expect(FileFilter::isFileExcluded($vendorFile))->toBeTrue();
    });

    test('differentiates application folders from identical vendor folder names (e.g. lib/** in app vs lib/** in vendor)', function () {
        Config::set([
            'include' => [
                'lib/**', 
                'modules/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();
        $projectRoot = Config::getProjectRoot();

        $appLibFile = str_replace('\\', '/', $projectRoot . '/lib/Services/PaymentProcessor.php');
        $vendorLibFile = str_replace('\\', '/', $projectRoot . '/vendor/dompdf/php-font-lib/lib/Font.php');
        expect(FileFilter::isFileExcluded($appLibFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($vendorLibFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $appLibFile, $appLibFile))->toBeTrue()
            ->and($refMethod->invoke(null, $vendorLibFile, $vendorLibFile))->toBeFalse()
        ;
    });
});