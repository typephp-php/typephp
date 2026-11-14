<?php

declare(strict_types=1);

use TypePHP\Internal\Io\CacheManager;
use TypePHP\Internal\Util\Config;

describe('CacheManager Unit Tests', function () {
    test('returns valid cache directory path', function () {
        $dir = CacheManager::getCacheDir();

        expect($dir)->toBeString()
            ->and($dir)->not()->toBeEmpty()
        ;
    });

    test('clears cached files from directory and returns count', function () {
        $tempCacheDir = sys_get_temp_dir() . '/typephp_unit_cache_' . uniqid();
        Config::set(['cache_dir' => $tempCacheDir]);

        try {
            $dir = CacheManager::getCacheDir();

            if (! is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $testFile = $dir . '/test_cache_unit.php';
            file_put_contents($testFile, '<?php // test');

            $count = CacheManager::clear();

            expect($count)->toBeGreaterThanOrEqual(1)
                ->and(file_exists($testFile))->toBeFalse()
            ;
        } finally {
            Config::reset();
            if (is_dir($tempCacheDir)) {
                @rmdir($tempCacheDir);
            }
        }
    });

    test('bypasses filemtime when cache_check_mtime is disabled', function () {
        try {
            $file = __FILE__;

            Config::set(['cache_check_mtime' => true]);
            $keyWithMtime = CacheManager::getCacheKey($file);

            Config::set(['cache_check_mtime' => false]);
            $keyWithoutMtime = CacheManager::getCacheKey($file);

            expect($keyWithMtime)->not()->toBe($keyWithoutMtime);
            expect(CacheManager::getCacheKey($file))->toBe($keyWithoutMtime);
        } finally {
            Config::reset();
        }
    });

    test('cache key follows settings that change the transformed source', function () {
        try {
            $file = __FILE__;

            Config::set(['respect_ignore_tags' => true, 'on_violation' => 'throw']);
            $key = CacheManager::getCacheKey($file);

            Config::set(['on_violation' => 'report', 'report_file' => '/tmp/report.json']);
            expect(CacheManager::getCacheKey($file))->toBe($key);

            Config::set(['respect_ignore_tags' => false]);
            expect(CacheManager::getCacheKey($file))->not()->toBe($key);

            Config::set(['respect_ignore_tags' => true]);
            expect(CacheManager::getCacheKey($file))->toBe($key);

            putenv('TYPEPHP_ELIMINATE_STATICALLY_PROVEN_CHECKS=false');
            CacheManager::reset();
            expect(CacheManager::getCacheKey($file))->not()->toBe($key);
        } finally {
            putenv('TYPEPHP_ELIMINATE_STATICALLY_PROVEN_CHECKS');
            Config::reset();
        }
    });

    test('cache key follows edits to configured stub files', function () {
        $stubFile = sys_get_temp_dir() . '/typephp_stub_' . uniqid() . '.stub';
        file_put_contents($stubFile, '<?php');
        touch($stubFile, 1_700_000_000);

        try {
            $file = __FILE__;

            Config::set(['stubs' => [$stubFile]]);
            $key = CacheManager::getCacheKey($file);

            touch($stubFile, 1_700_000_100);
            clearstatcache();
            CacheManager::reset();
            expect(CacheManager::getCacheKey($file))->not()->toBe($key);

            Config::set(['cache_check_mtime' => false]);
            $keyWithoutMtime = CacheManager::getCacheKey($file);

            touch($stubFile, 1_700_000_200);
            clearstatcache();
            CacheManager::reset();
            expect(CacheManager::getCacheKey($file))->toBe($keyWithoutMtime);
        } finally {
            Config::reset();
            @unlink($stubFile);
        }
    });
});
