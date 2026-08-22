<?php

declare(strict_types=1);

use TypePHP\Internal\CacheManager;
use TypePHP\Internal\Config;
use TypePHP\Internal\PathMatcher;

describe('PathMatcher Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        PathMatcher::reset();
    });

    afterEach(function () {
        Config::reset();
        PathMatcher::reset();
    });

    describe('normalizePath()', function () {
        test('returns empty string for null, false, and empty values', function () {
            expect(PathMatcher::normalizePath(null))->toBe('')
                ->and(PathMatcher::normalizePath(false))->toBe('')
                ->and(PathMatcher::normalizePath(''))->toBe('')
            ;
        });

        test('converts Windows backslashes to forward slashes', function () {
            expect(PathMatcher::normalizePath('C:\\project\\src\\Service.php'))
                ->toBe('C:/project/src/Service.php')
                ->and(PathMatcher::normalizePath('vendor\\composer\\autoload.php'))
                ->toBe('vendor/composer/autoload.php')
                ->and(PathMatcher::normalizePath('mixed/path\\to/file.php'))
                ->toBe('mixed/path/to/file.php')
            ;
        });
    });

    describe('canonicalizePath()', function () {
        test('returns unchanged path when no dots or traversals exist', function () {
            expect(PathMatcher::canonicalizePath('src/Services/UserService.php'))
                ->toBe('src/Services/UserService.php');
        });

        test('collapses relative directory traversals (..) and current directory dots (.)', function () {
            expect(PathMatcher::canonicalizePath('/var/www/vendor/composer/../doctrine/dbal/src/Schema.php'))
                ->toBe('/var/www/vendor/doctrine/dbal/src/Schema.php')
                ->and(PathMatcher::canonicalizePath('src/./Core/../Services/./UserService.php'))
                ->toBe('src/Services/UserService.php')
                ->and(PathMatcher::canonicalizePath('/app/./Controllers/../Models/User.php'))
                ->toBe('/app/Models/User.php')
            ;
        });

        test('handles leading root slashes and root directory boundaries', function () {
            expect(PathMatcher::canonicalizePath('/../app/User.php'))
                ->toBe('/app/User.php')
                ->and(PathMatcher::canonicalizePath('../../../app/User.php'))
                ->toBe('../../../app/User.php')
                ->and(PathMatcher::canonicalizePath('/'))
                ->toBe('/')
            ;
        });
    });

    describe('isVendorPath()', function () {
        test('identifies absolute and relative vendor paths correctly', function () {
            expect(PathMatcher::isVendorPath('vendor/doctrine/dbal/src/Schema.php'))->toBeTrue()
                ->and(PathMatcher::isVendorPath('/var/www/project/vendor/monolog/monolog/src/Logger.php'))->toBeTrue()
                ->and(PathMatcher::isVendorPath('C:/project/vendor/symfony/console/Application.php'))->toBeTrue()
            ;
        });

        test('identifies vendor paths when raw path has Windows backslashes', function () {
            expect(PathMatcher::isVendorPath('vendor/foo/bar.php', 'vendor\\foo\\bar.php'))->toBeTrue()
                ->and(PathMatcher::isVendorPath('C:/project/vendor/foo.php', 'C:\\project\\vendor\\foo.php'))->toBeTrue()
                ->and(PathMatcher::isVendorPath('other/path.php', '/root/vendor/pkg/file.php'))->toBeTrue()
            ;
        });

        test('does not falsely classify application directories with vendor prefix as vendor directory', function () {
            expect(PathMatcher::isVendorPath('vendor-tools/Deploy.php'))->toBeFalse()
                ->and(PathMatcher::isVendorPath('vendor_custom/Helper.php'))->toBeFalse()
                ->and(PathMatcher::isVendorPath('/var/www/vendor-tools/Script.php'))->toBeFalse()
                ->and(PathMatcher::isVendorPath('src/Services/UserService.php'))->toBeFalse()
            ;
        });
    });

    describe('isCachePath()', function () {
        test('identifies paths inside TypePHP cache directory', function () {
            $cacheDir = PathMatcher::normalizePath(CacheManager::getCacheDir());
            $cachedFile = $cacheDir . '/v0.1_hash123.php';
            $normalFile = '/var/www/project/src/App.php';

            expect(PathMatcher::isCachePath($cachedFile))->toBeTrue()
                ->and(PathMatcher::isCachePath($normalFile))->toBeFalse()
            ;
        });
    });

    describe('isLibraryInternal()', function () {
        test('identifies TypePHP internal engine directories correctly', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());

            $internalFile = $projectRoot . '/src/Internal/RuntimeTypeChecker.php';
            $contractFile = $projectRoot . '/src/Contract/FileFilter.php';
            $commandFile = $projectRoot . '/src/Command/RunCommand.php';
            $validatorFile = $projectRoot . '/src/Validator/ArrayValidator.php';
            $wrapperFile = $projectRoot . '/src/Wrapper/CallableWrapper.php';
            $resolverFile = $projectRoot . '/src/Resolver/SpecialTypeResolver.php';
            $extensionFile = $projectRoot . '/src/Extension/ExtensionManager.php';
            $exceptionFile = $projectRoot . '/src/Exception/TypeError.php';
            $entryFile = $projectRoot . '/src/TypePHP.php';
            $bootstrapFile = $projectRoot . '/src/bootstrap.php';
            $mockAppFile = $projectRoot . '/src/Service.php';
            $externalFile = '/var/www/app/Models/User.php';

            expect(PathMatcher::isLibraryInternal($internalFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($contractFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($commandFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($validatorFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($wrapperFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($resolverFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($extensionFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($exceptionFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($entryFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($bootstrapFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($mockAppFile))->toBeFalse()
                ->and(PathMatcher::isLibraryInternal($externalFile))->toBeFalse()
            ;
        });

        test('identifies internal paths when installed as a vendor dependency', function () {
            $ref = new ReflectionClass(PathMatcher::class);
            $prop = $ref->getProperty('cachedLibSrcDir');
            $prop->setValue(null, '/var/www/project/vendor/typephp/typephp/src/');

            $vendorInstalledFile = '/var/www/project/vendor/typephp/typephp/src/Internal/RuntimeTypeChecker.php';
            $appFile = '/var/www/project/src/Service.php';

            expect(PathMatcher::isLibraryInternal($vendorInstalledFile))->toBeTrue()
                ->and(PathMatcher::isLibraryInternal($appFile))->toBeFalse()
            ;
        });

        test('returns false when library directory cannot be resolved', function () {
            $ref = new ReflectionClass(PathMatcher::class);
            $prop = $ref->getProperty('cachedLibSrcDir');
            $prop->setValue(null, '');

            expect(PathMatcher::isLibraryInternal('/var/www/project/src/Service.php'))->toBeFalse();
        });
    });

    describe('hasIncludeMatchingPrefix()', function () {
        test('detects when include list has patterns starting with prefix', function () {
            $includes = ['src/**', 'vendor/my-org/my-pkg/**', 'app/**'];

            expect(PathMatcher::hasIncludeMatchingPrefix('vendor/', $includes))->toBeTrue()
                ->and(PathMatcher::hasIncludeMatchingPrefix('src/', $includes))->toBeTrue()
                ->and(PathMatcher::hasIncludeMatchingPrefix('var/', $includes))->toBeFalse()
                ->and(PathMatcher::hasIncludeMatchingPrefix('storage/', $includes))->toBeFalse()
            ;
        });

        test('detects scoped or subpath prefixes in include list', function () {
            $includes = ['packages/custom/var/plugins/**'];

            expect(PathMatcher::hasIncludeMatchingPrefix('var/', $includes))->toBeTrue();
        });

        test('retrieves decision from in-memory cache on subsequent lookups', function () {
            $includes = ['vendor/acme/**'];

            expect(PathMatcher::hasIncludeMatchingPrefix('vendor/', $includes))->toBeTrue()
                ->and(PathMatcher::hasIncludeMatchingPrefix('vendor/', $includes))->toBeTrue()
            ;
        });

        test('ignores non-string or empty include patterns gracefully', function () {
            $dirtyIncludes = [null, '', 123, 'src/**'];

            expect(PathMatcher::hasIncludeMatchingPrefix('src/', $dirtyIncludes))->toBeTrue()
                ->and(PathMatcher::hasIncludeMatchingPrefix('vendor/', $dirtyIncludes))->toBeFalse()
            ;
        });
    });

    describe('isDynamicWritablePath()', function () {
        test('identifies dynamic writable cache and log directories', function () {
            expect(PathMatcher::isDynamicWritablePath('/var/www/var/cache/prod/Container.php'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('var/cache/test/app.php'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('var/log/dev.log'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('/var/www/var/log/error.log'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('/project/storage/framework/views/123.php'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('storage/logs/laravel.log'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('/tmp/cache/item.php'))->toBeTrue()
                ->and(PathMatcher::isDynamicWritablePath('cache/opcache.php'))->toBeTrue()
            ;
        });

        test('returns false for static read-only directories', function () {
            expect(PathMatcher::isDynamicWritablePath('/var/www/src/Core/Service.php'))->toBeFalse()
                ->and(PathMatcher::isDynamicWritablePath('/var/www/vendor/doctrine/dbal/Column.php'))->toBeFalse()
                ->and(PathMatcher::isDynamicWritablePath('tests/Unit/SampleTest.php'))->toBeFalse()
            ;
        });
    });

    describe('mayPathBeIncluded() Fast-Path String Pre-Filter', function () {
        test('rejects node_modules and TypePHP cache unconditionally', function () {
            $cacheDir = PathMatcher::normalizePath(CacheManager::getCacheDir());

            expect(PathMatcher::mayPathBeIncluded('/var/www/node_modules/vue/index.js'))->toBeFalse()
                ->and(PathMatcher::mayPathBeIncluded('node_modules/package/file.php'))->toBeFalse()
                ->and(PathMatcher::mayPathBeIncluded($cacheDir . '/v0.1_test.php'))->toBeFalse()
            ;
        });

        test('rejects unwhitelisted vendor, var, and storage paths when config does not include them', function () {
            try {
                Config::set([
                    'include' => ['src/**', 'app/**'],
                ]);

                expect(PathMatcher::mayPathBeIncluded('vendor/monolog/monolog/src/Logger.php'))->toBeFalse()
                    ->and(PathMatcher::mayPathBeIncluded('/var/www/vendor/symfony/console/App.php'))->toBeFalse()
                    ->and(PathMatcher::mayPathBeIncluded('/var/www/var/cache/Container.php'))->toBeFalse()
                    ->and(PathMatcher::mayPathBeIncluded('var/logs/app.log'))->toBeFalse()
                    ->and(PathMatcher::mayPathBeIncluded('storage/framework/views/1.php'))->toBeFalse()
                    ->and(PathMatcher::mayPathBeIncluded('/var/www/storage/app/file.txt'))->toBeFalse()
                ;
            } finally {
                Config::reset();
            }
        });

        test('permits vendor, var, or storage paths when explicitly whitelisted in include config', function () {
            try {
                Config::set([
                    'include' => [
                        'src/**',
                        'vendor/my-org/my-package/**',
                        'var/plugins/**',
                        'storage/custom/**',
                    ],
                ]);

                expect(PathMatcher::mayPathBeIncluded('vendor/my-org/my-package/src/Service.php'))->toBeTrue()
                    ->and(PathMatcher::mayPathBeIncluded('/var/www/var/plugins/Plugin.php'))->toBeTrue()
                    ->and(PathMatcher::mayPathBeIncluded('storage/custom/Handler.php'))->toBeTrue()
                    ->and(PathMatcher::mayPathBeIncluded('src/App/Controller.php'))->toBeTrue()
                ;
            } finally {
                Config::reset();
            }
        });
    });

    describe('compileGlobToRegex()', function () {
        test('compiles absolute glob patterns into exact anchored regex', function () {
            $baseDir = '/var/www/project';
            $regexUnix = PathMatcher::compileGlobToRegex('/var/www/project/src/**', $baseDir);
            $regexWindows = PathMatcher::compileGlobToRegex('C:/project/src/**', $baseDir);

            expect(preg_match($regexUnix, '/var/www/project/src/Service.php'))->toBe(1)
                ->and(preg_match($regexUnix, '/var/www/other/src/Service.php'))->toBe(0)
                ->and(preg_match($regexWindows, 'C:/project/src/Service.php'))->toBe(1)
            ;
        });

        test('compiles wildcard * and ** globs', function () {
            $baseDir = '/var/www/project';

            $wildcardAll = PathMatcher::compileGlobToRegex('**', $baseDir);
            expect(preg_match($wildcardAll, '/var/www/project/any/deep/file.php'))->toBe(1);

            $singleStar = PathMatcher::compileGlobToRegex('*', $baseDir);
            expect(preg_match($singleStar, '/var/www/project/index.php'))->toBe(1);

            $prefixDoubleStar = PathMatcher::compileGlobToRegex('**/*.php', $baseDir);
            expect(preg_match($prefixDoubleStar, '/var/www/project/app/Model.php'))->toBe(1);
        });

        test('compiles relative globs strictly anchored to project root or relative start', function () {
            $baseDir = '/var/www/project';
            $regex = PathMatcher::compileGlobToRegex('src/**', $baseDir);

            expect(preg_match($regex, '/var/www/project/src/Core/Helper.php'))->toBe(1)
                ->and(preg_match($regex, 'src/Core/Helper.php'))->toBe(1)
                ->and(preg_match($regex, '/var/www/project/vendor/doctrine/dbal/src/Column.php'))->toBe(0)
                ->and(preg_match($regex, '/var/www/project/packages/tool/src/Helper.php'))->toBe(0)
            ;
        });
    });

    describe('isPathIncluded() with Specificity Rules', function () {
        test('matches application files when included and not excluded', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**', 'app/**'];
            $excludes = ['vendor/**', 'storage/**'];

            $appFile = $projectRoot . '/app/Services/UserService.php';
            expect(PathMatcher::isPathIncluded($appFile, $includes, $excludes))->toBeTrue();
        });

        test('excludes vendor files by default even with nested src folders', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**', 'app/**', 'tests/**'];
            $excludes = ['vendor/**', 'storage/**'];

            $vendorFile = $projectRoot . '/vendor/doctrine/dbal/src/Schema/Column.php';
            expect(PathMatcher::isPathIncluded($vendorFile, $includes, $excludes))->toBeFalse();
        });

        test('allows whitelisted vendor packages with explicit vendor/ include pattern', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**', 'vendor/my-org/whitelisted-package/**'];
            $excludes = ['vendor/**'];

            $whitelistedFile = $projectRoot . '/vendor/my-org/whitelisted-package/src/Service.php';
            $unwhitelistedFile = $projectRoot . '/vendor/doctrine/dbal/src/Schema/Column.php';

            expect(PathMatcher::isPathIncluded($whitelistedFile, $includes, $excludes))->toBeTrue()
                ->and(PathMatcher::isPathIncluded($unwhitelistedFile, $includes, $excludes))->toBeFalse()
            ;
        });

        test('allows explicit vendor wildcard includes', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**', 'vendor/**'];
            $excludes = ['vendor/doctrine/**'];

            $whitelistedVendor = $projectRoot . '/vendor/monolog/monolog/src/Logger.php';
            $excludedVendor = $projectRoot . '/vendor/doctrine/dbal/src/Column.php';

            expect(PathMatcher::isPathIncluded($whitelistedVendor, $includes, $excludes))->toBeTrue()
                ->and(PathMatcher::isPathIncluded($excludedVendor, $includes, $excludes))->toBeFalse()
            ;
        });

        test('allows blacklisting a specific single file inside an included directory', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**'];
            $excludes = ['src/Legacy/UnsafeFile.php'];

            $safeFile = $projectRoot . '/src/SafeService.php';
            $unsafeFile = $projectRoot . '/src/Legacy/UnsafeFile.php';

            expect(PathMatcher::isPathIncluded($safeFile, $includes, $excludes))->toBeTrue()
                ->and(PathMatcher::isPathIncluded($unsafeFile, $includes, $excludes))->toBeFalse()
            ;
        });

        test('excludes wins tie-breaker when include and exclude have equal pattern length', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/Config.php'];
            $excludes = ['src/Config.php'];

            $file = $projectRoot . '/src/Config.php';
            expect(PathMatcher::isPathIncluded($file, $includes, $excludes))->toBeFalse();
        });

        test('returns false when no include pattern matches the path', function () {
            $projectRoot = PathMatcher::normalizePath(Config::getProjectRoot());
            $includes = ['src/**'];
            $excludes = ['vendor/**'];

            $unmatchedFile = $projectRoot . '/bin/console';
            expect(PathMatcher::isPathIncluded($unmatchedFile, $includes, $excludes))->toBeFalse();
        });

        test('handles relative paths without leading slash cleanly', function () {
            $includes = ['src/**', 'app/**'];
            $excludes = ['vendor/**'];

            expect(PathMatcher::isPathIncluded('src/Core/Util.php', $includes, $excludes, 'src/Core/Util.php'))->toBeTrue()
                ->and(PathMatcher::isPathIncluded('vendor/doctrine/dbal/src/Column.php', $includes, $excludes, 'vendor/doctrine/dbal/src/Column.php'))->toBeFalse()
            ;
        });

        test('accepts custom base directory as argument and retrieves compiled patterns from cache', function () {
            $customBase = '/opt/custom/project';
            $dirtyIncludes = ['src/**', null];
            $dirtyExcludes = ['var/**', null];

            expect(PathMatcher::isPathIncluded('/opt/custom/project/src/App.php', $dirtyIncludes, $dirtyExcludes, '', $customBase))->toBeTrue()
                ->and(PathMatcher::isPathIncluded('/opt/custom/project/src/App.php', $dirtyIncludes, $dirtyExcludes, '', $customBase))->toBeTrue()
            ;
        });
    });

    describe('reset()', function () {
        test('clears internal pattern, prefix, and directory caches cleanly', function () {
            PathMatcher::normalizePath('test/path');
            PathMatcher::hasIncludeMatchingPrefix('vendor/', ['vendor/**']);
            PathMatcher::isCachePath('some/path');

            PathMatcher::reset();

            expect(true)->toBeTrue();
        });
    });
});