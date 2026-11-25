<?php

declare(strict_types=1);

use TypePHP\Internal\Io\CacheManager;
use TypePHP\Internal\Util\Config;

it('invalidates cache key when switching between throw and non-throw on_violation modes', function () {
    $file = __FILE__;

    Config::set(['on_violation' => 'throw']);
    $throwKey = CacheManager::getCacheKey($file);

    Config::set(['on_violation' => 'report']);
    $reportKey = CacheManager::getCacheKey($file);

    Config::set(['on_violation' => 'warn']);
    $warnKey = CacheManager::getCacheKey($file);

    expect($throwKey)->not->toBe($reportKey);
    expect($reportKey)->toBe($warnKey);
});

it('reflects TYPEPHP_ON_VIOLATION env override in cache key and shares key with config mode', function () {
    $file = __FILE__;

    Config::set(['on_violation' => 'report']);
    $configReportKey = CacheManager::getCacheKey($file);

    Config::set(['on_violation' => 'throw']);
    $throwKey = CacheManager::getCacheKey($file);

    $_ENV['TYPEPHP_ON_VIOLATION'] = 'report';
    CacheManager::reset();

    try {
        $envReportKey = CacheManager::getCacheKey($file);
    } finally {
        unset($_ENV['TYPEPHP_ON_VIOLATION']);
        CacheManager::reset();
    }

    expect($envReportKey)->not->toBe($throwKey);
    expect($envReportKey)->toBe($configReportKey);
});
