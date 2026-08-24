<?php

declare(strict_types=1);

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

    test('excludes relative vendor paths starting with vendor/ (without leading slash)', function () {
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

        $relativeVendorFile = 'vendor/doctrine/dbal/src/Schema/AbstractNamedObject.php';
        $relativeAppFile = 'src/Core/Framework/Util.php';

        expect(FileFilter::isFileExcluded($relativeVendorFile))->toBeTrue()
            ->and(FileFilter::isFileExcluded($relativeAppFile))->toBeFalse()
        ;

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $relativeVendorFile, $relativeVendorFile))->toBeFalse()
            ->and($refMethod->invoke(null, $relativeAppFile, $relativeAppFile))->toBeTrue()
        ;
    });

    test('excludes composer relative traversal paths (vendor/composer/../doctrine/dbal/src/...)', function () {
        Config::set([
            'include' => [
                'src/**',
                'app/**',
                'tests/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $composerTraversalFile = 'vendor/composer/../doctrine/dbal/src/Schema/Column.php';

        expect(FileFilter::isFileExcluded($composerTraversalFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $composerTraversalFile, $composerTraversalFile))->toBeFalse();
    });

    test('excludes Windows relative paths starting with vendor\\', function () {
        Config::set([
            'include' => [
                'src/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $windowsRelativeVendor = 'vendor\\doctrine\\dbal\\src\\Schema\\Column.php';
        $windowsRelativeApp = 'src\\Core\\Framework\\Util.php';

        expect(FileFilter::isFileExcluded($windowsRelativeVendor))->toBeTrue()
            ->and(FileFilter::isFileExcluded($windowsRelativeApp))->toBeFalse()
        ;

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $windowsRelativeVendor, $windowsRelativeVendor))->toBeFalse()
            ->and($refMethod->invoke(null, $windowsRelativeApp, $windowsRelativeApp))->toBeTrue()
        ;
    });

    test('src/** include pattern strictly matches project root src/ and does not match arbitrary nested src folders', function () {
        Config::set([
            'include' => [
                'src/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();
        $rootSrcFile = str_replace('\\', '/', $projectRoot . '/src/Service.php');
        $nestedSrcFile = str_replace('\\', '/', $projectRoot . '/packages/custom-tool/src/Helper.php');

        expect(FileFilter::isFileExcluded($rootSrcFile))->toBeFalse()
            ->and(FileFilter::isFileExcluded($nestedSrcFile))->toBeTrue()
        ;

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $rootSrcFile, $rootSrcFile))->toBeTrue()
            ->and($refMethod->invoke(null, $nestedSrcFile, $nestedSrcFile))->toBeFalse()
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

    test('allows whitelisting a single specific file inside a vendor package while excluding its siblings', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/monolog/monolog/src/Monolog/Logger.php',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $whitelistedSingleFile = str_replace('\\', '/', $projectRoot . '/vendor/monolog/monolog/src/Monolog/Logger.php');
        $siblingVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/monolog/monolog/src/Monolog/Formatter/LineFormatter.php');

        expect(FileFilter::isFileExcluded($whitelistedSingleFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($siblingVendorFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $whitelistedSingleFile, $whitelistedSingleFile))->toBeTrue()
            ->and($refMethod->invoke(null, $siblingVendorFile, $siblingVendorFile))->toBeFalse()
        ;
    });

    test('allows blacklisting a specific legacy file inside an otherwise whitelisted vendor package', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/acme/custom-package/**',
            ],
            'exclude' => [
                'vendor/**',
                'vendor/acme/custom-package/src/Legacy/UnsafeFile.php',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $safeFile = str_replace('\\', '/', $projectRoot . '/vendor/acme/custom-package/src/SafeService.php');
        $unsafeFile = str_replace('\\', '/', $projectRoot . '/vendor/acme/custom-package/src/Legacy/UnsafeFile.php');

        expect(FileFilter::isFileExcluded($safeFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($unsafeFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $safeFile, $safeFile))->toBeTrue()
            ->and($refMethod->invoke(null, $unsafeFile, $unsafeFile))->toBeFalse()
        ;
    });

    test('does not falsely classify application directories like vendor-tools/ or vendor_custom/ as vendor directories', function () {
        Config::set([
            'include' => [
                'vendor-tools/**',
                'vendor_custom/**',
                'src/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $appToolsFile = str_replace('\\', '/', $projectRoot . '/vendor-tools/DeployScript.php');
        $appCustomFile = str_replace('\\', '/', $projectRoot . '/vendor_custom/Helper.php');
        $realVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/symfony/console/Application.php');

        expect(FileFilter::isFileExcluded($appToolsFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($appCustomFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($realVendorFile))->toBeTrue();

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $appToolsFile, $appToolsFile))->toBeTrue()
            ->and($refMethod->invoke(null, $appCustomFile, $appCustomFile))->toBeTrue()
            ->and($refMethod->invoke(null, $realVendorFile, $realVendorFile))->toBeFalse()
        ;
    });

    test('handles vendor package names containing hyphens, dots, numbers, and scoped prefixes', function () {
        Config::set([
            'include' => [
                'src/**',
                'vendor/symfony/polyfill-php83/**',
                'vendor/2amigos/qrcode-library/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $scopedVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/symfony/polyfill-php83/bootstrap.php');
        $numericVendorFile = str_replace('\\', '/', $projectRoot . '/vendor/2amigos/qrcode-library/src/QrCode.php');
        $unwhitelistedVendor = str_replace('\\', '/', $projectRoot . '/vendor/guzzlehttp/guzzle/src/Client.php');

        expect(FileFilter::isFileExcluded($scopedVendorFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($numericVendorFile))->toBeFalse();
        expect(FileFilter::isFileExcluded($unwhitelistedVendor))->toBeTrue();
    });

    test('handles mixed Windows backslashes and Unix forward slashes in vendor paths seamlessly', function () {
        Config::set([
            'include' => [
                'src/**',
                'app/**',
            ],
            'exclude' => [
                'vendor/**',
            ],
        ]);

        StreamWrapper::register();

        $projectRoot = Config::getProjectRoot();

        $windowsVendorPath = $projectRoot . '\\vendor\\doctrine\\dbal\\src\\Schema\\Column.php';
        $windowsAppPath = $projectRoot . '\\app\\Services\\OrderService.php';

        expect(FileFilter::isFileExcluded($windowsVendorPath))->toBeTrue()
            ->and(FileFilter::isFileExcluded($windowsAppPath))->toBeFalse()
        ;

        $refMethod = new ReflectionMethod(StreamWrapper::class, 'isApplicationFile');
        expect($refMethod->invoke(null, $windowsVendorPath, $windowsVendorPath))->toBeFalse()
            ->and($refMethod->invoke(null, $windowsAppPath, $windowsAppPath))->toBeTrue()
        ;
    });
});
