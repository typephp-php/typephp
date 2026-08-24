<?php

declare(strict_types=1);

describe('Bootstrap Tooling Detection', function () {
    test('does not falsely disable TypePHP when argument or filter contains substring of tooling names', function () {
        $argv = ['phpunit', 'tests', '--filter', 'testCreatesMissingDirectoryRecursively', '--blueprint=testPoint'];

        $candidate = $argv[0];
        $baseArg0 = strtolower(basename(str_replace('\\', '/', $candidate)));
        if (\in_array($baseArg0, ['php', 'php.exe'], true) && isset($argv[1])) {
            $candidate = $argv[1];
        }

        $rawBinary = strtolower(basename(str_replace('\\', '/', $candidate)));
        $binary = preg_replace('/\.(phar|bat|exe|cmd)$/i', '', $rawBinary) ?? $rawBinary;

        $toolingBinaries = [
            'phpstan' => true,
            'psalm' => true,
            'php-cs-fixer' => true,
            'pint' => true,
            'rector' => true,
            'mago' => true,
            'composer' => true,
        ];

        expect(isset($toolingBinaries[$binary]))->toBeFalse();
    });

    test('accurately detects actual tooling binary executions', function () {
        $tools = [
            ['vendor/bin/phpstan', 'analyse'],
            ['/usr/local/bin/composer', 'install'],
            ['php', 'vendor/bin/rector', 'process'],
            ['vendor/bin/pint.phar'],
            ['/usr/bin/psalm'],
            ['vendor/bin/php-cs-fixer.bat'],
        ];

        $toolingBinaries = [
            'phpstan' => true,
            'psalm' => true,
            'php-cs-fixer' => true,
            'pint' => true,
            'rector' => true,
            'mago' => true,
            'composer' => true,
        ];

        foreach ($tools as $argv) {
            $candidate = $argv[0];
            $baseArg0 = strtolower(basename(str_replace('\\', '/', $candidate)));
            if (\in_array($baseArg0, ['php', 'php.exe'], true) && isset($argv[1])) {
                $candidate = $argv[1];
            }

            $rawBinary = strtolower(basename(str_replace('\\', '/', $candidate)));
            $binary = preg_replace('/\.(phar|bat|exe|cmd)$/i', '', $rawBinary) ?? $rawBinary;

            expect(isset($toolingBinaries[$binary]))->toBeTrue();
        }
    });
});
