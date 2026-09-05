<?php

declare(strict_types=1);

use TypePHP\Internal\Io\CacheManager;

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
});
