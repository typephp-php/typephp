<?php

declare(strict_types=1);

namespace TypePHP\Tests\Extension;

use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\ExtensionManager;
use TypePHP\Internal\Util\FileFilter;
use TypePHP\Tests\Fixtures\Extensions\SampleRegisteredExtension;

describe('ExtensionManager Unit Tests', function () {
    test('loads include whitelist paths from explicitly registered extension classes', function () {
        $includes = ExtensionManager::loadExtensionIncludes([
            SampleRegisteredExtension::class,
        ]);

        expect($includes)->toContain('vendor/acme/sample-package/**');
    });

    test('structurally ignores any exclude attempts from third-party extensions', function () {
        $includes = ExtensionManager::loadExtensionIncludes([
            SampleRegisteredExtension::class,
        ]);

        // Extension attempted to exclude 'src/**', but ExtensionManager only extracts 'include'
        expect($includes)->not()->toContain('src/**');
    });

    test('ignores non-existent extension classes gracefully', function () {
        /** @var array<int, string> $badExtensions */
        $badExtensions = ['NonExistentExtensionClass12345'];

        $includes = ExtensionManager::loadExtensionIncludes($badExtensions);

        expect($includes)->toBeEmpty();
    });

    test('allows user to override extension whitelist by blacklisting in typephp.php', function () {
        Config::set([
            'extensions' => [
                SampleRegisteredExtension::class, // Whitelists 'vendor/acme/sample-package/**'
            ],
            'exclude' => [
                'vendor/**',
                'vendor/acme/sample-package/**', // User explicitly blacklists extension package!
            ],
        ]);

        $extensionPath = str_replace('\\', '/', getcwd() . '/vendor/acme/sample-package/src/Service.php');

        // User's explicit blacklist wins equal-specificity tie-breaker!
        expect(FileFilter::isFileExcluded($extensionPath))->toBeTrue();

        Config::reset();
    });
});
