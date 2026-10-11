<?php

declare(strict_types=1);

namespace TypePHP\Tests\Feature;

use ReflectionClass;
use TypePHP\Internal\Cli\CommandRunner;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Util\Config;

describe('Zero-Files Detection, Root Resolution & Report Telemetry (Issue #114)', function () {
    beforeEach(function () {
        putenv('TYPEPHP_ON_VIOLATION=');
        putenv('TYPEPHP_REPORT_FILE=');
        putenv('TYPEPHP_FAIL_ON_REPORT=');
        putenv('TYPEPHP_FAIL_ON_ZERO_FILES=');
        unset(
            $_ENV['TYPEPHP_ON_VIOLATION'],
            $_SERVER['TYPEPHP_ON_VIOLATION'],
            $_ENV['TYPEPHP_REPORT_FILE'],
            $_SERVER['TYPEPHP_REPORT_FILE'],
            $_ENV['TYPEPHP_FAIL_ON_REPORT'],
            $_SERVER['TYPEPHP_FAIL_ON_REPORT'],
            $_ENV['TYPEPHP_FAIL_ON_ZERO_FILES'],
            $_SERVER['TYPEPHP_FAIL_ON_ZERO_FILES']
        );
        Config::reset();
        StreamWrapper::reset();
        ViolationCollector::reset();
    });

    afterEach(function () {
        putenv('TYPEPHP_ON_VIOLATION=');
        putenv('TYPEPHP_REPORT_FILE=');
        putenv('TYPEPHP_FAIL_ON_REPORT=');
        putenv('TYPEPHP_FAIL_ON_ZERO_FILES=');
        unset(
            $_ENV['TYPEPHP_ON_VIOLATION'],
            $_SERVER['TYPEPHP_ON_VIOLATION'],
            $_ENV['TYPEPHP_REPORT_FILE'],
            $_SERVER['TYPEPHP_REPORT_FILE'],
            $_ENV['TYPEPHP_FAIL_ON_REPORT'],
            $_SERVER['TYPEPHP_FAIL_ON_REPORT'],
            $_ENV['TYPEPHP_FAIL_ON_ZERO_FILES'],
            $_SERVER['TYPEPHP_FAIL_ON_ZERO_FILES']
        );
        StreamWrapper::reset();
        ViolationCollector::reset();
        Config::reset();
    });

    describe('1. Case 3 Reproduction: Subdirectory composer.json with Root typephp.php', function () {
        test('prioritizes typephp.php in cwd over subdirectory composer.json install path', function () {
            $tempRepo = sys_get_temp_dir() . '/typephp_repro_114_' . uniqid();
            mkdir($tempRepo . '/app/libs', 0777, true);

            $rootConfigContent = <<<'PHP'
<?php
return [
    'on_violation' => 'report',
    'include' => ['custom_modules/**'],
];
PHP;
            file_put_contents($tempRepo . '/typephp.php', $rootConfigContent);

            $subComposerJson = json_encode([
                'name' => 'acme/sub-app',
                'config' => ['vendor-dir' => 'libs'],
            ]);
            file_put_contents($tempRepo . '/app/composer.json', $subComposerJson);

            $oldCwd = getcwd();
            chdir($tempRepo);

            try {
                Config::reset();

                $projectRoot = Config::getProjectRoot();
                $normalizedRepo = rtrim(str_replace('\\', '/', (string) realpath($tempRepo)), '/');

                expect($projectRoot)->toBe($normalizedRepo);

                $config = Config::get();
                expect(Config::getResolvedConfigFile())->toBe($normalizedRepo . '/typephp.php')
                    ->and($config['include'])->toBe(['custom_modules/**'])
                    ->and(Config::getOnViolation())->toBe('report')
                ;
            } finally {
                chdir((string) $oldCwd);
                @unlink($tempRepo . '/typephp.php');
                @unlink($tempRepo . '/app/composer.json');
                @rmdir($tempRepo . '/app/libs');
                @rmdir($tempRepo . '/app');
                @rmdir($tempRepo);
                Config::reset();
            }
        });

        test('emits E_USER_WARNING when typephp.php exists in cwd but projectRoot resolved to a different directory without config', function () {
            $tempRepo = sys_get_temp_dir() . '/typephp_warn_cwd_' . uniqid();
            mkdir($tempRepo . '/subproject', 0777, true);

            file_put_contents($tempRepo . '/typephp.php', "<?php return ['enabled' => true];");

            $oldCwd = getcwd();
            chdir($tempRepo);

            $warningEmitted = false;
            $warningMessage = '';
            set_error_handler(function (int $errno, string $errstr) use (&$warningEmitted, &$warningMessage): bool {
                if ($errno === E_USER_WARNING && str_contains($errstr, '[TypePHP Warning]')) {
                    $warningEmitted = true;
                    $warningMessage = $errstr;

                    return true;
                }

                return false;
            });

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $normSubproject = rtrim(str_replace('\\', '/', (string) realpath($tempRepo . '/subproject')), '/');
                $prop->setValue(null, $normSubproject);

                Config::get();

                expect($warningEmitted)->toBeTrue()
                    ->and($warningMessage)->toContain('typephp.php')
                    ->and($warningMessage)->toContain($normSubproject)
                ;
            } finally {
                restore_error_handler();
                chdir((string) $oldCwd);
                @unlink($tempRepo . '/typephp.php');
                @rmdir($tempRepo . '/subproject');
                @rmdir($tempRepo);
                Config::reset();
            }
        });
    });

    describe('2. Telemetry Overhead Gating (throw mode vs report mode)', function () {
        test('allocates 0 file tracking in throw and warn modes (zero overhead)', function () {
            Config::set(['on_violation' => 'throw']);
            expect(StreamWrapper::shouldTrackTelemetry())->toBeFalse();

            $wrapper = new StreamWrapper();
            $openedPath = null;
            $thisFile = __FILE__;

            $wrapper->stream_open($thisFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
            $wrapper->stream_close();

            // In throw mode: 0 files tracked in memory!
            expect(StreamWrapper::getInstrumentedCount())->toBe(0)
                ->and(StreamWrapper::getInstrumentedFiles())->toBeEmpty()
            ;
        });

        test('tracks unique files in report mode and deduplicates repeated includes', function () {
            Config::set(['on_violation' => 'report']);
            expect(StreamWrapper::shouldTrackTelemetry())->toBeTrue();

            $wrapper = new StreamWrapper();
            $openedPath = null;
            $thisFile = __FILE__;

            $wrapper->stream_open($thisFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
            $wrapper->stream_close();

            expect(StreamWrapper::getInstrumentedCount())->toBe(1);

            // Repeated include of the same file should not duplicate the count
            $wrapper->stream_open($thisFile, 'r', StreamWrapper::STREAM_OPEN_FOR_INCLUDE, $openedPath);
            $wrapper->stream_close();

            expect(StreamWrapper::getInstrumentedCount())->toBe(1);
        });
    });

    describe('3. Report Schema Telemetry & 0-Files Warning', function () {
        test('includes context, config_file, and files_instrumented in report JSON document', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_report_telemetry_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFile = $tempDir . '/audit.json';

            Config::set([
                'on_violation' => 'report',
                'report_file' => $reportFile,
            ]);

            StreamWrapper::recordInstrumentedFile('/app/Services/OrderService.php');
            StreamWrapper::recordInstrumentedFile('/app/Services/UserService.php');

            ViolationCollector::exportReport($reportFile);

            expect(file_exists($reportFile))->toBeTrue();

            /** @var array{context: array<string, mixed>, summary: array<string, mixed>} $doc */
            $doc = json_decode((string) file_get_contents($reportFile), true);

            expect($doc)->toHaveKey('context')
                ->and($doc['context']['files_instrumented'])->toBe(2)
                ->and($doc['summary']['files_instrumented'])->toBe(2)
                ->and($doc['summary']['total_violations'])->toBe(0)
                ->and($doc['summary'])->not()->toHaveKey('warning')
            ;

            @unlink($reportFile);
            @rmdir($tempDir);
        });

        test('adds warning to report summary when files_instrumented is 0', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_zero_report_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFile = $tempDir . '/zero-audit.json';

            Config::set([
                'on_violation' => 'report',
                'report_file' => $reportFile,
            ]);

            ViolationCollector::exportReport($reportFile);

            /** @var array{summary: array<string, mixed>} $doc */
            $doc = json_decode((string) file_get_contents($reportFile), true);

            expect($doc['summary']['files_instrumented'])->toBe(0)
                ->and($doc['summary'])->toHaveKey('warning')
                ->and($doc['summary']['warning'])->toContain('No application files were instrumented')
            ;

            @unlink($reportFile);
            @rmdir($tempDir);
        });
    });

    describe('4. CLI Report Command: Distinguishing Empty Run from Clean Run', function () {
        test('returns exit code 1 when report file recorded 0 violations over 0 instrumented files', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_cli_zero_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFile = $tempDir . '/zero.json';

            $reportData = [
                'version' => '1.0',
                'context' => [
                    'project_root' => '/repo',
                    'config_file' => '/repo/typephp.php',
                    'files_instrumented' => 0,
                ],
                'summary' => [
                    'total_violations' => 0,
                    'files_affected' => 0,
                    'files_instrumented' => 0,
                    'warning' => 'No application files were instrumented',
                ],
                'violations' => [],
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
                    ->and($output)->toContain('Files Instrumented: 0')
                    ->and($output)->toContain('0 violations over 0 instrumented files')
                ;
            } finally {
                fclose($stream);
                @unlink($reportFile);
                @rmdir($tempDir);
            }
        });

        test('returns exit code 0 when report file recorded 0 violations across 15 instrumented files (Clean Run)', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_cli_clean_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFile = $tempDir . '/clean.json';

            $reportData = [
                'version' => '1.0',
                'context' => [
                    'project_root' => '/repo',
                    'config_file' => '/repo/typephp.php',
                    'files_instrumented' => 15,
                ],
                'summary' => [
                    'total_violations' => 0,
                    'files_affected' => 0,
                    'files_instrumented' => 15,
                ],
                'violations' => [],
            ];
            file_put_contents($reportFile, json_encode($reportData));

            Config::set(['report_file' => $reportFile]);

            $stream = fopen('php://memory', 'r+');

            try {
                $exitCode = CommandRunner::run(['report'], $stream, $stream);

                rewind($stream);
                $rawOutput = (string) stream_get_contents($stream);
                $output = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $rawOutput);

                expect($exitCode)->toBe(0)
                    ->and($output)->toContain('Clean run: No type violations found across 15 instrumented file(s)')
                    ->and($output)->toContain('Files Instrumented: 15')
                ;
            } finally {
                fclose($stream);
                @unlink($reportFile);
                @rmdir($tempDir);
            }
        });
    });

    describe('5. fail_on_zero_files Configuration Setting', function () {
        test('isFailOnZeroFilesEnabled respects config setting and environment variable', function () {
            expect(Config::isFailOnZeroFilesEnabled())->toBeFalse();

            Config::set(['fail_on_zero_files' => true]);
            expect(Config::isFailOnZeroFilesEnabled())->toBeTrue();

            Config::set(['fail_on_zero_files' => false]);
            expect(Config::isFailOnZeroFilesEnabled())->toBeFalse();

            putenv('TYPEPHP_FAIL_ON_ZERO_FILES=true');
            expect(Config::isFailOnZeroFilesEnabled())->toBeTrue();

            putenv('TYPEPHP_FAIL_ON_ZERO_FILES=false');
            expect(Config::isFailOnZeroFilesEnabled())->toBeFalse();
        });
    });
});
