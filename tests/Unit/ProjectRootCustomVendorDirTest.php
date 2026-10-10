<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use TypePHP\Internal\Util\Config;

describe('Project Root Resolution with Custom Composer vendor-dir (Bug Report Reproduction)', function () {
    afterEach(function () {
        Config::reset();
    });

    test('resolves project root correctly when vendor-dir is custom and cwd is not the root (e.g. public/)', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_custom_vendor_' . uniqid();
        mkdir($tempBase . '/public', 0777, true);
        mkdir($tempBase . '/libs/typephp/typephp/src/Internal/Util', 0777, true);

        $rootComposerJson = json_encode([
            'name' => 'acme/my-web-app',
            'config' => ['vendor-dir' => 'libs'],
            'extra' => [
                'typephp' => [
                    'auto-boot' => false,
                ],
            ],
        ]);
        file_put_contents($tempBase . '/composer.json', $rootComposerJson);

        $libComposerJson = json_encode([
            'name' => 'typephp/typephp',
        ]);
        file_put_contents($tempBase . '/libs/typephp/typephp/composer.json', $libComposerJson);

        $simulatedLibDir = $tempBase . '/libs/typephp/typephp/src/Internal/Util';

        try {
            Config::reset();

            $resolvedRoot = Config::getProjectRoot($simulatedLibDir);
            $normalizedExpected = rtrim(str_replace('\\', '/', (string) realpath($tempBase)), '/');

            expect($resolvedRoot)->toBe($normalizedExpected);
        } finally {
            @unlink($tempBase . '/libs/typephp/typephp/composer.json');
            @unlink($tempBase . '/composer.json');
            @rmdir($tempBase . '/libs/typephp/typephp/src/Internal/Util');
            @rmdir($tempBase . '/libs/typephp/typephp/src/Internal');
            @rmdir($tempBase . '/libs/typephp/typephp/src');
            @rmdir($tempBase . '/libs/typephp/typephp');
            @rmdir($tempBase . '/libs/typephp');
            @rmdir($tempBase . '/libs');
            @rmdir($tempBase . '/public');
            @rmdir($tempBase);
            Config::reset();
        }
    });

    test('uses Composer InstalledVersions root package install_path when available', function () {
        if (! class_exists(\Composer\InstalledVersions::class)) {
            expect(true)->toBeTrue();

            return;
        }

        Config::reset();

        $root = Config::getProjectRoot();
        $expected = rtrim(str_replace('\\', '/', (string) realpath(__DIR__ . '/../../')), '/');

        expect($root)->toBe($expected);
    });

    test('resolves project root from deep web subdirectory cwd (e.g. public/admin/dashboard)', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_deep_cwd_' . uniqid();
        mkdir($tempBase . '/public/admin/dashboard', 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/web-app']));

        $startingSubdir = $tempBase . '/public/admin/dashboard';

        try {
            Config::reset();

            $resolvedRoot = Config::getProjectRoot($startingSubdir);
            $normalizedExpected = rtrim(str_replace('\\', '/', (string) realpath($tempBase)), '/');

            expect($resolvedRoot)->toBe($normalizedExpected);
        } finally {
            @unlink($tempBase . '/composer.json');
            @rmdir($tempBase . '/public/admin/dashboard');
            @rmdir($tempBase . '/public/admin');
            @rmdir($tempBase . '/public');
            @rmdir($tempBase);
            Config::reset();
        }
    });

    test('resolves project root when root has typephp.php without composer.json', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_typephp_config_' . uniqid();
        mkdir($tempBase . '/public', 0777, true);

        file_put_contents($tempBase . '/typephp.php', "<?php return ['enabled' => true];");

        $startingSubdir = $tempBase . '/public';

        try {
            Config::reset();

            $resolvedRoot = Config::getProjectRoot($startingSubdir);
            $normalizedExpected = rtrim(str_replace('\\', '/', (string) realpath($tempBase)), '/');

            expect($resolvedRoot)->toBe($normalizedExpected);
        } finally {
            @unlink($tempBase . '/typephp.php');
            @rmdir($tempBase . '/public');
            @rmdir($tempBase);
            Config::reset();
        }
    });

    test('handles deeply nested custom vendor-dir (e.g. packages/third-party/deps/typephp/typephp)', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_deep_vendor_' . uniqid();
        mkdir($tempBase . '/packages/third-party/deps/typephp/typephp/src/Internal/Util', 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/monorepo']));
        file_put_contents($tempBase . '/packages/third-party/deps/typephp/typephp/composer.json', json_encode(['name' => 'typephp/typephp']));

        $simulatedLibDir = $tempBase . '/packages/third-party/deps/typephp/typephp/src/Internal/Util';

        try {
            Config::reset();

            $resolvedRoot = Config::getProjectRoot($simulatedLibDir);
            $normalizedExpected = rtrim(str_replace('\\', '/', (string) realpath($tempBase)), '/');

            expect($resolvedRoot)->toBe($normalizedExpected);
        } finally {
            @unlink($tempBase . '/packages/third-party/deps/typephp/typephp/composer.json');
            @unlink($tempBase . '/composer.json');
            @rmdir($tempBase . '/packages/third-party/deps/typephp/typephp/src/Internal/Util');
            @rmdir($tempBase . '/packages/third-party/deps/typephp/typephp/src/Internal');
            @rmdir($tempBase . '/packages/third-party/deps/typephp/typephp/src');
            @rmdir($tempBase . '/packages/third-party/deps/typephp/typephp');
            @rmdir($tempBase . '/packages/third-party/deps/typephp');
            @rmdir($tempBase . '/packages/third-party/deps');
            @rmdir($tempBase . '/packages/third-party');
            @rmdir($tempBase . '/packages');
            @rmdir($tempBase);
            Config::reset();
        }
    });

    test('handles Windows backslash startingDir paths cleanly', function () {
        $tempBase = sys_get_temp_dir() . '/typephp_win_path_' . uniqid();
        mkdir($tempBase . '/vendor/typephp/typephp/src/Internal/Util', 0777, true);

        file_put_contents($tempBase . '/composer.json', json_encode(['name' => 'acme/win-app']));
        file_put_contents($tempBase . '/vendor/typephp/typephp/composer.json', json_encode(['name' => 'typephp/typephp']));

        $windowsPath = str_replace('/', '\\', $tempBase . '/vendor/typephp/typephp/src/Internal/Util');

        try {
            Config::reset();

            $resolvedRoot = Config::getProjectRoot($windowsPath);
            $normalizedExpected = rtrim(str_replace('\\', '/', (string) realpath($tempBase)), '/');

            expect($resolvedRoot)->toBe($normalizedExpected);
        } finally {
            @unlink($tempBase . '/vendor/typephp/typephp/composer.json');
            @unlink($tempBase . '/composer.json');
            @rmdir($tempBase . '/vendor/typephp/typephp/src/Internal/Util');
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
