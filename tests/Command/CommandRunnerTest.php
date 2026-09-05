<?php

declare(strict_types=1);

namespace TypePHP\Tests\Command;

use TypePHP\Internal\Cli\CommandRunner;

describe('CommandRunner Unit Tests', function () {
    test('routes help command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['help'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('USAGE')
        ;
    });

    test('routes config:init command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['config:init'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Configuration')
        ;
    });

    test('routes cache:clear command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:clear'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('routes cache:warm command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:warm'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('routes cache:rebuild command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['cache:rebuild'], $stream, $stream);

        expect($exitCode)->toBe(0);
    });

    test('returns exit code 1 and detects unknown command for typo like helps', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['helps'], $stream, $stream);

        rewind($stream);
        $rawOutput = stream_get_contents($stream);
        fclose($stream);

        $output = preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Command "helps" is not defined')
            ->and($output)->toContain('Did you mean one of these?')
        ;
    });

    test('returns exit code 1 and warns when target file has non-PHP extension', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['index.js'], $stream, $stream);

        rewind($stream);
        $rawOutput = stream_get_contents($stream);
        fclose($stream);

        $output = preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Target file "index.js" is not a PHP script file')
        ;
    });

    test('returns exit code 1 when target script file ending in .php does not exist', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['non_existent_script_123.php'], $stream, $stream);

        rewind($stream);
        $rawOutput = stream_get_contents($stream);
        fclose($stream);

        $output = preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('Target script file "non_existent_script_123.php" does not exist or is not readable')
        ;
    });
});
