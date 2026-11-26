<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Reporting;

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Io\StreamWrapper;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Util\Config;

describe('ViolationCollector Multi-Process Report Merge Safety (Red Phase)', function () {
    beforeEach(function () {
        Config::reset();
        ViolationCollector::reset();
        StreamWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        ViolationCollector::reset();
        StreamWrapper::reset();
    });

    test('does not wipe existing violations or files_instrumented when a subsequent process exits with 0 violations', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_report_clobber_' . uniqid();
        mkdir($tempDir, 0777, true);
        $reportFile = $tempDir . '/typephp_audit.json';

        try {
            Config::set(['on_violation' => 'report', 'report_file' => $reportFile]);
            for ($i = 0; $i < 216; $i++) {
                StreamWrapper::recordInstrumentedFile("app/Services/Service{$i}.php");
            }
            $err = new ErrorMessage("App\\Services\\PatientService::formatPatientDetails(): Return value key must be of type float, string 'id' returned");
            for ($i = 0; $i < 6; $i++) {
                ViolationCollector::handle($err, 'return', [], 'app/Services/PatientService.php', 206);
            }
            ViolationCollector::exportReport($reportFile);

            ViolationCollector::reset();
            StreamWrapper::reset();
            for ($i = 0; $i < 14; $i++) {
                StreamWrapper::recordInstrumentedFile("app/Providers/Provider{$i}.php");
            }
            ViolationCollector::exportReport($reportFile);

            $doc = json_decode((string) file_get_contents($reportFile), true);

            expect($doc['summary']['total_violations'])->toBe(1)
                ->and($doc['summary']['files_affected'])->toBe(1)
                ->and($doc['summary']['files_instrumented'])->toBe(216)
                ->and($doc['context']['files_instrumented'])->toBe(216)
                ->and($doc['violations'])->toHaveCount(1)
                ->and($doc['violations'][0]['count'])->toBe(6)
            ;
        } finally {
            @unlink($reportFile);
            @rmdir($tempDir);
        }
    });

    test('merges and aggregates violation counts across sequential processes writing to the same report file', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_report_merge_' . uniqid();
        mkdir($tempDir, 0777, true);
        $reportFile = $tempDir . '/typephp_audit.json';

        try {
            Config::set(['on_violation' => 'report', 'report_file' => $reportFile]);

            $errA = new ErrorMessage('App\\Payment::charge(): Argument $amount must be of type positive-int, negative int (-10) given');
            for ($i = 0; $i < 3; $i++) {
                ViolationCollector::handle($errA, 'parameter', -10, 'app/Payment.php', 20);
            }
            ViolationCollector::exportReport($reportFile);

            ViolationCollector::reset();
            for ($i = 0; $i < 2; $i++) {
                ViolationCollector::handle($errA, 'parameter', -10, 'app/Payment.php', 20);
            }
            $errB = new ErrorMessage("App\\User::setName(): Argument \$name must be of type non-empty-string, empty string ('') given");
            ViolationCollector::handle($errB, 'parameter', '', 'app/User.php', 45);
            ViolationCollector::exportReport($reportFile);

            $doc = json_decode((string) file_get_contents($reportFile), true);

            expect($doc['summary']['total_violations'])->toBe(2)
                ->and($doc['summary']['files_affected'])->toBe(2)
            ;

            $byFile = [];
            foreach ($doc['violations'] as $v) {
                $byFile[$v['file']] = $v;
            }

            expect($byFile['app/Payment.php']['count'])->toBe(5)
                ->and($byFile['app/User.php']['count'])->toBe(1)
            ;
        } finally {
            @unlink($reportFile);
            @rmdir($tempDir);
        }
    });

    test('does not double-count violations when exportReport() is called multiple times within the same process', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_report_idempotent_' . uniqid();
        mkdir($tempDir, 0777, true);
        $reportFile = $tempDir . '/typephp_audit.json';

        try {
            Config::set(['on_violation' => 'report', 'report_file' => $reportFile]);
            $err = new ErrorMessage('App\\Order::pay(): Argument $id must be of type positive-int, negative int (-1) given');

            for ($i = 0; $i < 4; $i++) {
                ViolationCollector::handle($err, 'parameter', -1, 'app/Order.php', 10);
            }

            ViolationCollector::exportReport($reportFile);
            ViolationCollector::exportReport($reportFile);

            $doc1 = json_decode((string) file_get_contents($reportFile), true);
            expect($doc1['violations'][0]['count'])->toBe(4);

            ViolationCollector::handle($err, 'parameter', -1, 'app/Order.php', 10);
            ViolationCollector::handle($err, 'parameter', -1, 'app/Order.php', 10);
            ViolationCollector::exportReport($reportFile);

            $doc2 = json_decode((string) file_get_contents($reportFile), true);
            expect($doc2['violations'][0]['count'])->toBe(6);
        } finally {
            @unlink($reportFile);
            @rmdir($tempDir);
        }
    });
});
