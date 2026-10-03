<?php

declare(strict_types=1);

namespace TypePHP\Tests\Command;

use TypePHP\Internal\Cli\CommandRunner;
use TypePHP\Internal\Util\Config;

describe('CommandRunner Unit Tests', function () {
    test('routes help command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['help'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('USAGE')
            ->and($output)->toContain('report')
        ;
    });

    test('routes report command when report file is missing', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['report'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Violation Audit Report')
        ;
    });

    test('routes report:clear command successfully', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['report:clear'], $stream, $stream);

        rewind($stream);
        $output = stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('Report Clear')
        ;
    });

    test('routes report command and displays formatted violations with exit code 1', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_cli_report_' . uniqid();
        mkdir($tempDir, 0777, true);
        $reportFile = $tempDir . '/typephp-report.json';

        $reportData = [
            'version' => '1.0',
            'generated_at' => gmdate('c'),
            'summary' => ['total_violations' => 1, 'files_affected' => 1],
            'violations' => [
                [
                    'file' => 'src/Services/OrderService.php',
                    'line' => 42,
                    'function' => 'App\\Services\\OrderService::checkout',
                    'kind' => 'parameter',
                    'target' => '$total',
                    'expected' => 'positive-int',
                    'given' => 'negative int (-50)',
                    'message' => 'Argument $total must be of type positive-int, negative int (-50) given',
                ],
            ],
        ];

        file_put_contents($reportFile, json_encode($reportData));
        Config::set(['report_file' => $reportFile]);

        $stream = fopen('php://memory', 'r+');

        try {
            $exitCode = CommandRunner::run(['report'], $stream, $stream);

            rewind($stream);
            $rawOutput = (string) stream_get_contents($stream);
            $output = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

            expect($exitCode)->toBe(1)
                ->and($output)->toContain('Total Violations: 1')
                ->and($output)->toContain('src/Services/OrderService.php')
                ->and($output)->toContain('Line 42')
                ->and($output)->toContain('$total')
                ->and($output)->toContain('positive-int')
            ;
        } finally {
            fclose($stream);
            @unlink($reportFile);
            @rmdir($tempDir);
        }
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
