<?php

declare(strict_types=1);

namespace TypePHP\Tests\Contract;

use TypePHP\Contract\FileFilter;
use TypePHP\Internal\Config;
use TypePHP\Internal\PathMatcher;

describe('Packages and Monorepo Path Inclusion (packages/**/src/**)', function () {
    beforeEach(function () {
        Config::reset();
        FileFilter::reset();
        PathMatcher::reset();
    });

    afterEach(function () {
        Config::reset();
        FileFilter::reset();
        PathMatcher::reset();
    });

    test('correctly includes package source files with packages/**/src/** glob', function () {
        Config::set([
            'include' => [
                'src/**',
                'packages/**/src/**',
            ],
            'exclude' => [
                'vendor/**',
                'storage/**',
                'packages/**/tests/**',
            ],
        ]);

        $projectRoot = Config::getProjectRoot();

        $coreSource = str_replace('\\', '/', $projectRoot . '/packages/core/src/Application.php');
        $supportSource = str_replace('\\', '/', $projectRoot . '/packages/support/src/Arr/functions.php');
        $consoleSource = str_replace('\\', '/', $projectRoot . '/packages/console/src/Input/ConsoleArgumentBag.php');

        expect(FileFilter::isFileExcluded($coreSource))->toBeFalse()
            ->and(FileFilter::isFileExcluded($supportSource))->toBeFalse()
            ->and(FileFilter::isFileExcluded($consoleSource))->toBeFalse();
    });

    test('correctly excludes package test files with packages/**/tests/** glob', function () {
        Config::set([
            'include' => [
                'src/**',
                'packages/**/src/**',
            ],
            'exclude' => [
                'vendor/**',
                'packages/**/tests/**',
            ],
        ]);

        $projectRoot = Config::getProjectRoot();

        $packageTest = str_replace('\\', '/', $projectRoot . '/packages/support/tests/Filesystem/UnixFunctionsTest.php');
        $coreTest = str_replace('\\', '/', $projectRoot . '/packages/core/tests/ApplicationTest.php');

        expect(FileFilter::isFileExcluded($packageTest))->toBeTrue()
            ->and(FileFilter::isFileExcluded($coreTest))->toBeTrue();
    });

    test('verifies PathMatcher includes deep package source files and excludes package test files', function () {
        $projectRoot = '/home/runner/work/tempest-framework/tempest-framework';
        $includes = [
            'src/**',
            'packages/**/src/**',
        ];
        $excludes = [
            'vendor/**',
            'storage/**',
            'var/**',
            'cache/**',
            'packages/**/tests/**',
        ];

        expect(PathMatcher::isPathIncluded($projectRoot . '/packages/core/src/Application.php', $includes, $excludes, '', $projectRoot))->toBeTrue()
            ->and(PathMatcher::isPathIncluded($projectRoot . '/packages/support/src/Arr/functions.php', $includes, $excludes, '', $projectRoot))->toBeTrue()
            ->and(PathMatcher::isPathIncluded($projectRoot . '/packages/console/src/Input/ConsoleArgumentBag.php', $includes, $excludes, '', $projectRoot))->toBeTrue()
            ->and(PathMatcher::isPathIncluded($projectRoot . '/packages/http/src/IsRequest.php', $includes, $excludes, '', $projectRoot))->toBeTrue();

        expect(PathMatcher::isPathIncluded($projectRoot . '/packages/support/tests/Filesystem/UnixFunctionsTest.php', $includes, $excludes, '', $projectRoot))->toBeFalse()
            ->and(PathMatcher::isPathIncluded($projectRoot . '/packages/core/tests/ApplicationTest.php', $includes, $excludes, '', $projectRoot))->toBeFalse()
            ->and(PathMatcher::isPathIncluded($projectRoot . '/packages/console/tests/ConsoleArgumentBagTest.php', $includes, $excludes, '', $projectRoot))->toBeFalse();
    });

    test('verifies isStaticSourcePath accurately classifies package source paths vs test fixture paths', function () {
        $pkgSourcePath = '/home/runner/work/tempest-framework/tempest-framework/packages/core/src/Application.php';
        $pkgTestFixturePath = '/home/runner/work/tempest-framework/tempest-framework/packages/support/tests/Filesystem/Fixtures/file.txt';
        $viteInstallFixturePath = '/home/runner/work/tempest-framework/tempest-framework/tests/Integration/Vite/install/app/main.entrypoint.ts';

        expect(PathMatcher::isStaticSourcePath($pkgSourcePath))->toBeTrue()
            ->and(PathMatcher::isStaticSourcePath($pkgTestFixturePath))->toBeFalse()
            ->and(PathMatcher::isStaticSourcePath($viteInstallFixturePath))->toBeFalse();
    });
});