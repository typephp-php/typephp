<?php

declare(strict_types=1);

namespace TypePHP;

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    \define('TYPEPHP_BOOTED', value: true);

    $isDisabledEnv = getenv('TYPEPHP_DISABLE') !== false && filter_var(getenv('TYPEPHP_DISABLE'), FILTER_VALIDATE_BOOLEAN);
    $isDisabledConst = \defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;
    $argv = $_SERVER['argv'] ?? null;
    $allArgs = \is_array($argv) ? implode(' ', array_map('strval', $argv)) : '';
    $script = (isset($_SERVER['SCRIPT_NAME']) && \is_string($_SERVER['SCRIPT_NAME'])) ? $_SERVER['SCRIPT_NAME'] : '';

    $normalizedLine = str_replace('\\', '/', strtolower($allArgs . ' ' . $script));

    $isStaticAnalysis = str_contains($normalizedLine, 'phpstan')
        || str_contains($normalizedLine, 'psalm')
        || str_contains($normalizedLine, 'mago')
        || str_contains($normalizedLine, 'rector');

    if (! $isDisabledEnv && ! $isDisabledConst && ! $isStaticAnalysis) {
        TypePHP::boot();
    }
}
