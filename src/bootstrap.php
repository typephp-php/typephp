<?php

declare(strict_types=1);

namespace TypePHP;

if (class_exists(TypePHP::class) && ! \defined('TYPEPHP_BOOTED')) {
    \define('TYPEPHP_BOOTED', true);

    $isDisabledEnv = getenv('TYPEPHP_DISABLE') !== false && filter_var(getenv('TYPEPHP_DISABLE'), FILTER_VALIDATE_BOOLEAN);
    $isDisabledConst = \defined('TYPEPHP_DISABLE') && TYPEPHP_DISABLE;

    $isTooling = false;

    if (isset($_SERVER['argv']) && \is_array($_SERVER['argv']) && \count($_SERVER['argv']) > 0) {
        $candidate = $_SERVER['argv'][0];

        if (\is_string($candidate)) {
            $baseArg0 = strtolower(basename(str_replace('\\', '/', $candidate)));
            if (\in_array($baseArg0, ['php', 'php.exe', 'php-cgi', 'php-fpm'], true) && isset($_SERVER['argv'][1]) && \is_string($_SERVER['argv'][1])) {
                $candidate = $_SERVER['argv'][1];
            }

            $rawBinary = strtolower(basename(str_replace('\\', '/', $candidate)));
            $binary = preg_replace('/\.(phar|bat|exe|cmd)$/i', '', $rawBinary) ?? $rawBinary;

            $toolingBinaries = [
                'phpstan' => true,
                'psalm' => true,
                'php-cs-fixer' => true,
                'phpcs' => true,
                'phpcbf' => true,
                'pint' => true,
                'rector' => true,
                'mago' => true,
                'composer' => true,
                'deptrac' => true,
                'phan' => true,
            ];

            $isTooling = isset($toolingBinaries[$binary]);
        }
    }

    if (! $isDisabledEnv && ! $isDisabledConst && ! $isTooling) {
        TypePHP::boot();
    }
}
