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
            ->and($output)->toContain('report:merge')
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

    test('routes report:merge command and writes merged output file with aggregated counts', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_cli_merge_' . uniqid();
        mkdir($tempDir, 0777, true);

        $file1 = $tempDir . '/mod1.json';
        $file2 = $tempDir . '/mod2.json';
        $outFile = $tempDir . '/merged.json';

        $data1 = [
            'version' => '1.0',
            'summary' => ['total_violations' => 1],
            'violations' => [
                [
                    'file' => 'src/Order.php',
                    'line' => 10,
                    'function' => 'App\\Order::pay',
                    'kind' => 'parameter',
                    'target' => '$id',
                    'expected' => 'positive-int',
                    'given' => 'int (-1)',
                    'count' => 5,
                    'caller' => 'tests/OrderTest.php:20',
                    'message' => 'err',
                ],
            ],
        ];

        $data2 = [
            'version' => '1.0',
            'summary' => ['total_violations' => 1],
            'violations' => [
                [
                    'file' => 'src/Order.php',
                    'line' => 10,
                    'function' => 'App\\Order::pay',
                    'kind' => 'parameter',
                    'target' => '$id',
                    'expected' => 'positive-int',
                    'given' => 'int (-1)',
                    'count' => 15,
                    'caller' => 'tests/OrderTest.php:20',
                    'message' => 'err',
                ],
            ],
        ];

        file_put_contents($file1, json_encode($data1));
        file_put_contents($file2, json_encode($data2));

        $stream = fopen('php://memory', 'r+');

        try {
            $exitCode = CommandRunner::run(['report:merge', $file1, $file2, "--output={$outFile}"], $stream, $stream);

            rewind($stream);
            $rawOutput = (string) stream_get_contents($stream);
            $output = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('Merged 2 report file(s)')
                ->and(file_exists($outFile))->toBeTrue()
            ;

            $mergedData = json_decode((string) file_get_contents($outFile), true);

            expect($mergedData['summary']['total_violations'])->toBe(1)
                ->and($mergedData['violations'][0]['count'])->toBe(20)
            ;
        } finally {
            fclose($stream);
            @unlink($file1);
            @unlink($file2);
            @unlink($outFile);
            @rmdir($tempDir);
        }
    });

    test('routes report:merge command without output option and streams JSON to output stream', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_cli_merge_stdout_' . uniqid();
        mkdir($tempDir, 0777, true);

        $file1 = $tempDir . '/mod1.json';

        $data = [
            'version' => '1.0',
            'summary' => ['total_violations' => 1],
            'violations' => [
                [
                    'file' => 'src/User.php',
                    'line' => 12,
                    'function' => 'App\\User::find',
                    'kind' => 'parameter',
                    'target' => '$id',
                    'expected' => 'positive-int',
                    'given' => 'int (0)',
                    'count' => 2,
                    'message' => 'err',
                ],
            ],
        ];

        file_put_contents($file1, json_encode($data));

        $stream = fopen('php://memory', 'r+');

        try {
            $exitCode = CommandRunner::run(['report:merge', $file1], $stream, $stream);

            rewind($stream);
            $output = (string) stream_get_contents($stream);

            expect($exitCode)->toBe(0)
                ->and($output)->toContain('"version": "1.0"')
                ->and($output)->toContain('"file": "src/User.php"')
            ;
        } finally {
            fclose($stream);
            @unlink($file1);
            @rmdir($tempDir);
        }
    });

    test('routes report:merge command error when no files provided', function () {
        $stream = fopen('php://memory', 'r+');
        $exitCode = CommandRunner::run(['report:merge'], $stream, $stream);

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(1)
            ->and($output)->toContain('No input report files specified')
        ;
    });

    test('routes report:merge command warning when file does not exist', function () {
        $stream = fopen('php://memory', 'r+');
        $missingFile = sys_get_temp_dir() . '/missing_report_' . uniqid() . '.json';

        $exitCode = CommandRunner::run(['report:merge', $missingFile], $stream, $stream);

        rewind($stream);
        $output = (string) stream_get_contents($stream);
        fclose($stream);

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('not found, skipping')
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
                    'count' => 1,
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

    test('routes report command and displays occurrence count and caller breadcrumbs', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_cli_report_caller_' . uniqid();
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
                    'count' => 412,
                    'caller' => 'tests/Feature/OrderTest.php:100 (Tests\\OrderTest::testCheckout)',
                    'declared_in' => 'src/Contracts/OrderInterface.php:15',
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
                ->and($output)->toContain('(x412)')
                ->and($output)->toContain('↳ caller:')
                ->and($output)->toContain('tests/Feature/OrderTest.php:100')
                ->and($output)->toContain('↳ declared in:')
                ->and($output)->toContain('src/Contracts/OrderInterface.php:15')
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
