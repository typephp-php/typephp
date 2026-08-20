<?php

declare(strict_types=1);

namespace TypePHP;

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    \define('TYPEPHP_BOOTED', true);

    $isDisabledEnv = getenv('TYPEPHP_DISABLE') !== false && filter_var(getenv('TYPEPHP_DISABLE'), FILTER_VALIDATE_BOOLEAN);
    $isDisabledConst = \defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;

    $argv = $_SERVER['argv'] ?? null;
    $stringArgs = \is_array($argv) ? array_filter($argv, 'is_string') : [];
    $allArgs = implode(' ', $stringArgs);
    $script = (isset($_SERVER['SCRIPT_NAME']) && \is_string($_SERVER['SCRIPT_NAME'])) ? $_SERVER['SCRIPT_NAME'] : '';

    $normalized = str_replace('\\', '/', strtolower($allArgs . ' ' . $script));

    $isTooling = str_contains($normalized, 'phpstan')
        || str_contains($normalized, 'psalm')
        || str_contains($normalized, 'php-cs-fixer')
        || str_contains($normalized, 'pint')
        || str_contains($normalized, 'rector')
        || str_contains($normalized, 'mago')
        || str_contains($normalized, 'composer');

    if (! $isDisabledEnv && ! $isDisabledConst && ! $isTooling) {
        TypePHP::boot();
    }
}
