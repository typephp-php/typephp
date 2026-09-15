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
});
