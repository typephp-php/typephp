<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

describe('Native Stream Error Reporting Passthrough (Shopware Migration Test)', function () {
    test('file_get_contents on non-existent file produces native No such file or directory warning message', function () {
        $nonExistentPath = '/non/existent/path/template_' . uniqid() . '.html.twig';

        $caughtWarning = '';
        set_error_handler(function (int $errno, string $errstr) use (&$caughtWarning) {
            $caughtWarning .= $errstr . "\n";

            return true;
        });

        try {
            file_get_contents($nonExistentPath);
        } finally {
            restore_error_handler();
        }

        expect($caughtWarning)->toContain('No such file or directory');
    });
});
