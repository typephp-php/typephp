<?php

declare(strict_types=1);

namespace TypePHP;

require_once __DIR__ . '/Internal/PathMatcher.php';
require_once __DIR__ . '/Internal/Config.php';
require_once __DIR__ . '/Internal/CacheManager.php';

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    \define('TYPEPHP_BOOTED', true);

    $isDisabledEnv = getenv('TYPEPHP_DISABLE') !== false && filter_var(getenv('TYPEPHP_DISABLE'), FILTER_VALIDATE_BOOLEAN);
    $isDisabledConst = \defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;

    $argv = $_SERVER['argv'] ?? null;
    $script = '';
    if (\is_array($argv) && isset($argv[0]) && \is_string($argv[0])) {
        $script = $argv[0];
    } elseif (isset($_SERVER['SCRIPT_NAME']) && \is_string($_SERVER['SCRIPT_NAME'])) {
        $script = $_SERVER['SCRIPT_NAME'];
    }

    $normalizedScript = str_replace('\\', '/', strtolower($script));
    $isStaticAnalysis = str_contains($normalizedScript, 'phpstan') || str_contains($normalizedScript, 'psalm');

    if (! $isDisabledEnv && ! $isDisabledConst && ! $isStaticAnalysis) {
        TypePHP::boot();
    }
}