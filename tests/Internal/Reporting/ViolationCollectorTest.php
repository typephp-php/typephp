<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Reporting;

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Reporting\ViolationRecord;
use TypePHP\Internal\Util\Config;

describe('ViolationRecord Value Object', function () {
    test('instantiates with complete metadata including count, caller, and declared_in', function () {
        $record = new ViolationRecord(
            file: 'src/Services/PaymentService.php',
            line: 42,
            function: 'App\\Services\\PaymentService::charge',
            kind: 'parameter',
            target: '$amount',
            expected: 'positive-int',
            given: 'negative int (-50)',
            message: 'App\\Services\\PaymentService::charge(): Argument $amount must be of type positive-int, negative int (-50) given',
            count: 3,
            caller: 'tests/Feature/OrderTest.php:100 (Tests\\OrderTest::testCheckout)',
            declaredIn: 'src/Contracts/PaymentInterface.php:15'
        );

        expect($record->file)->toBe('src/Services/PaymentService.php')
            ->and($record->line)->toBe(42)
            ->and($record->function)->toBe('App\\Services\\PaymentService::charge')
            ->and($record->kind)->toBe('parameter')
            ->and($record->target)->toBe('$amount')
            ->and($record->expected)->toBe('positive-int')
            ->and($record->given)->toBe('negative int (-50)')
            ->and($record->count)->toBe(3)
            ->and($record->caller)->toBe('tests/Feature/OrderTest.php:100 (Tests\\OrderTest::testCheckout)')
            ->and($record->declaredIn)->toBe('src/Contracts/PaymentInterface.php:15')
            ->and(\strlen($record->getHash()))->toBe(32)
        ;
    });

    test('serializes to array and json matching new schema fields', function () {
        $record = new ViolationRecord(
            file: 'src/Models/User.php',
            line: 10,
            function: 'App\\Models\\User::setName',
            kind: 'parameter',
            target: '$name',
            expected: 'non-empty-string',
            given: "empty string ('')",
            message: "Argument \$name must be of type non-empty-string, empty string ('') given",
            count: 1,
            caller: 'src/Controllers/UserController.php:25',
            declaredIn: 'src/Traits/NameTrait.php:8'
        );

        $array = $record->toArray();
        expect($array)->toBe([
            'file' => 'src/Models/User.php',
            'line' => 10,
            'function' => 'App\\Models\\User::setName',
            'kind' => 'parameter',
            'target' => '$name',
            'expected' => 'non-empty-string',
            'given' => "empty string ('')",
            'count' => 1,
            'caller' => 'src/Controllers/UserController.php:25',
            'declared_in' => 'src/Traits/NameTrait.php:8',
            'message' => "Argument \$name must be of type non-empty-string, empty string ('') given",
        ]);

        $json = json_encode($record, JSON_UNESCAPED_SLASHES);
        expect($json)->toBeString()
            ->and($json)->toContain('"count":1')
            ->and($json)->toContain('"caller":"src/Controllers/UserController.php:25"')
            ->and($json)->toContain('"declared_in":"src/Traits/NameTrait.php:8"')
        ;
    });

    test('fromArray reconstitutes a ViolationRecord with count, caller, and declared_in', function () {
        $data = [
            'file' => 'src/Order.php',
            'line' => 25,
            'function' => 'App\\Order::process',
            'kind' => 'return',
            'target' => 'return',
            'expected' => 'bool',
            'given' => 'int (0)',
            'count' => 12,
            'caller' => 'src/Command/RunOrder.php:50',
            'declared_in' => 'src/Contracts/OrderInterface.php:12',
            'message' => 'Return value must be of type bool, int (0) returned',
        ];

        $record = ViolationRecord::fromArray($data);
        expect($record)->not()->toBeNull()
            ->and($record?->file)->toBe('src/Order.php')
            ->and($record?->count)->toBe(12)
            ->and($record?->caller)->toBe('src/Command/RunOrder.php:50')
            ->and($record?->declaredIn)->toBe('src/Contracts/OrderInterface.php:12')
        ;
    });

    test('withIncrementedCount creates a new immutable record with updated count', function () {
        $record = new ViolationRecord(
            file: 'src/File.php',
            line: 1,
            function: 'test',
            kind: 'parameter',
            target: '$x',
            expected: 'int',
            given: 'string',
            message: 'err',
            count: 1
        );

        $incremented = $record->withIncrementedCount(4);
        expect($incremented->count)->toBe(5)
            ->and($record->count)->toBe(1)
        ;
    });
});

describe('ViolationCollector Count & Origin Tracking', function () {
    beforeEach(function () {
        Config::reset();
        ViolationCollector::reset();
    });

    afterEach(function () {
        Config::reset();
        ViolationCollector::reset();
    });

    test('increments violation count when identical violation occurs repeatedly in loops', function () {
        Config::set(['on_violation' => 'report']);
        $err = new ErrorMessage('Argument $id must be of type positive-int, negative int (-1) given');

        for ($i = 0; $i < 25; $i++) {
            ViolationCollector::handle($err, 'parameter', 42, 'src/Services/Item.php', 10);
        }

        $violations = ViolationCollector::getViolations();
        expect($violations)->toHaveCount(1)
            ->and($violations[0]->count)->toBe(25)
        ;
    });

    test('sums occurrence counts when merging parallel worker shards into master report', function () {
        $tempDir = sys_get_temp_dir() . '/typephp_shard_count_test_' . uniqid();
        $shardDir = $tempDir . '/.typephp-shards';
        mkdir($shardDir, 0777, true);
        $reportFile = $tempDir . '/typephp-report.json';

        $shard1 = [
            [
                'file' => 'src/Payment.php',
                'line' => 20,
                'function' => 'App\\Payment::charge',
                'kind' => 'parameter',
                'target' => '$amount',
                'expected' => 'positive-int',
                'given' => 'negative int (-10)',
                'count' => 10,
                'message' => 'Argument $amount must be of type positive-int, negative int (-10) given',
            ],
        ];

        $shard2 = [
            [
                'file' => 'src/Payment.php',
                'line' => 20,
                'function' => 'App\\Payment::charge',
                'kind' => 'parameter',
                'target' => '$amount',
                'expected' => 'positive-int',
                'given' => 'negative int (-10)',
                'count' => 15,
                'message' => 'Argument $amount must be of type positive-int, negative int (-10) given',
            ],
        ];

        file_put_contents($shardDir . '/shard_1.json', json_encode($shard1));
        file_put_contents($shardDir . '/shard_2.json', json_encode($shard2));

        try {
            ViolationCollector::exportReport($reportFile);

            $doc = json_decode((string) file_get_contents($reportFile), true);

            expect($doc['summary']['total_violations'])->toBe(1)
                ->and($doc['violations'][0]['count'])->toBe(25)
            ;
        } finally {
            if (file_exists($reportFile)) {
                @unlink($reportFile);
            }
            @rmdir($tempDir);
        }
    });
});

describe('ViolationCollector', function () {
    beforeEach(function () {
        Config::reset();
        ViolationCollector::reset();
    });

    afterEach(function () {
        Config::reset();
        ViolationCollector::reset();
    });

    describe('Mode: throw', function () {
        test('returns ErrorMessage directly without recording or warning', function () {
            Config::set(['on_violation' => 'throw']);
            $err = new ErrorMessage('Argument $id must be of type positive-int, negative int (-1) given');

            $result = ViolationCollector::handle($err, 'parameter', 42);

            expect($result)->toBe($err)
                ->and(ViolationCollector::getViolations())->toBeEmpty()
            ;
        });
    });

    describe('Mode: warn', function () {
        test('emits PHP E_USER_WARNING and returns pass-through value', function () {
            Config::set(['on_violation' => 'warn']);
            $err = new ErrorMessage('Argument $code must be of type positive-int, int (-5) given');

            $warningCaught = '';
            set_error_handler(function (int $errno, string $errstr) use (&$warningCaught): bool {
                $warningCaught = $errstr;

                return true;
            });

            try {
                $result = ViolationCollector::handle($err, 'parameter', 'fallback_val', 'src/Action.php', 15);
            } finally {
                restore_error_handler();
            }

            expect($result)->toBe('fallback_val')
                ->and($warningCaught)->toContain('[TypePHP Violation]')
                ->and($warningCaught)->toContain('Argument $code must be of type positive-int')
            ;
        });

        test('deduplicates warnings so repeated violations in loops only trigger E_USER_WARNING once', function () {
            Config::set(['on_violation' => 'warn']);
            $err = new ErrorMessage('Argument $loop must be of type positive-int, negative int (-1) given');

            $warningCount = 0;
            set_error_handler(function () use (&$warningCount): bool {
                $warningCount++;

                return true;
            });

            try {
                for ($i = 0; $i < 50; $i++) {
                    ViolationCollector::handle($err, 'parameter', 100, 'src/Loop.php', 20);
                }
            } finally {
                restore_error_handler();
            }

            expect($warningCount)->toBe(1);
        });

        test('does not record violations in memory in warn mode even when report_file is configured', function () {
            Config::set([
                'on_violation' => 'warn',
                'report_file' => 'var/report.json',
            ]);
            $err = new ErrorMessage('Argument $id must be of type positive-int, int (-5) given');

            set_error_handler(static fn (): bool => true);

            try {
                ViolationCollector::handle($err, 'parameter', 42, 'src/Service.php', 10);
            } finally {
                restore_error_handler();
            }

            expect(ViolationCollector::getViolations())->toBeEmpty();
        });
    });

    describe('Mode: report', function () {
        test('emits PHP E_USER_WARNING and returns pass-through value with call site location', function () {
            Config::set(['on_violation' => 'warn']);
            $err = new ErrorMessage('Argument $code must be of type positive-int, int (-5) given');

            $warningCaught = '';
            set_error_handler(function (int $errno, string $errstr) use (&$warningCaught): bool {
                $warningCaught = $errstr;

                return true;
            });

            try {
                $result = ViolationCollector::handle($err, 'parameter', 'fallback_val', 'src/Action.php', 15);
            } finally {
                restore_error_handler();
            }

            expect($result)->toBe('fallback_val')
                ->and($warningCaught)->toContain('[TypePHP Violation]')
                ->and($warningCaught)->toContain('Argument $code must be of type positive-int')
                ->and($warningCaught)->toContain('src/Action.php on line 15')
            ;
        });

        test('records violation in memory, suppresses warnings, and returns pass-through value', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage('App\\Services\\UserService::find(): Argument $id must be of type positive-int, negative int (-10) given');

            $result = ViolationCollector::handle($err, 'parameter', 'original_val', 'src/Services/UserService.php', 42);

            expect($result)->toBe('original_val');

            $violations = ViolationCollector::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->file)->toBe('src/Services/UserService.php')
                ->and($violations[0]->line)->toBe(42)
                ->and($violations[0]->function)->toBe('App\\Services\\UserService::find')
                ->and($violations[0]->kind)->toBe('parameter')
                ->and($violations[0]->target)->toBe('$id')
                ->and($violations[0]->expected)->toBe('positive-int')
                ->and($violations[0]->given)->toBe('negative int (-10)')
            ;
        });

        test('deduplicates in-memory violations on identical occurrences', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage('Property App\\Model::$score must be of type positive-int, zero int (0) given');

            for ($i = 0; $i < 100; $i++) {
                ViolationCollector::handle($err, 'property', 0, 'src/Model.php', 5);
            }

            expect(ViolationCollector::getViolations())->toHaveCount(1);
        });

        test('clear resets all in-memory violations', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage('Argument $id must be of type positive-int, int (-1) given');

            ViolationCollector::handle($err, 'parameter', 1, 'src/File.php', 1);
            expect(ViolationCollector::getViolations())->toHaveCount(1);

            ViolationCollector::clear();
            expect(ViolationCollector::getViolations())->toBeEmpty();
        });
    });

    describe('Relative Path Normalization', function () {
        test('normalizes paths inside project root to relative forward-slash paths', function () {
            $root = Config::getProjectRoot();
            $absolutePath = $root . '/src/Internal/Util/Config.php';

            $normalized = ViolationCollector::normalizeRelativePath($absolutePath);
            expect($normalized)->toBe('src/Internal/Util/Config.php');
        });

        test('handles Windows backslashes in normalization', function () {
            $root = Config::getProjectRoot();
            $windowsPath = str_replace('/', '\\', $root . '/tests/Feature/Test.php');

            $normalized = ViolationCollector::normalizeRelativePath($windowsPath);
            expect($normalized)->toBe('tests/Feature/Test.php');
        });

        test('returns original path when outside project root or empty', function () {
            expect(ViolationCollector::normalizeRelativePath(''))->toBe('unknown');
            expect(ViolationCollector::normalizeRelativePath('/opt/external/Other.php'))->toBe('/opt/external/Other.php');
        });
    });

    describe('Parallel Testing Sharding & Report Export', function () {
        test('writes process-isolated shard without locking during parallel testing', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_shard_test_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFile = $tempDir . '/typephp-report.json';

            putenv('TEST_TOKEN=3');

            try {
                Config::set([
                    'on_violation' => 'report',
                    'report_file' => $reportFile,
                ]);

                $err = new ErrorMessage('Argument $id must be of type positive-int, negative int (-1) given');
                ViolationCollector::handle($err, 'parameter', null, 'src/Action.php', 10);

                ViolationCollector::writeWorkerShard($reportFile);

                $shardDir = $tempDir . '/.typephp-shards';
                expect(is_dir($shardDir))->toBeTrue();

                $shards = glob($shardDir . '/shard_3_*.json');
                expect($shards)->not()->toBeFalse()
                    ->and(\count($shards))->toBe(1)
                ;

                $content = file_get_contents($shards[0]);
                expect($content)->toContain('src/Action.php')
                    ->and($content)->toContain('positive-int')
                ;
            } finally {
                putenv('TEST_TOKEN');
                $files = glob($tempDir . '/.typephp-shards/*');
                if ($files !== false) {
                    foreach ($files as $f) {
                        @unlink($f);
                    }
                }
                @rmdir($tempDir . '/.typephp-shards');
                @rmdir($tempDir);
            }
        });

        test('exportReport merges and deduplicates worker shards into master JSON document', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_export_test_' . uniqid();
            $shardDir = $tempDir . '/.typephp-shards';
            mkdir($shardDir, 0777, true);
            $reportFile = $tempDir . '/typephp-report.json';

            $shard1 = [
                [
                    'file' => 'src/Payment.php',
                    'line' => 20,
                    'function' => 'App\\Payment::charge',
                    'kind' => 'parameter',
                    'target' => '$amount',
                    'expected' => 'positive-int',
                    'given' => 'negative int (-10)',
                    'message' => 'Argument $amount must be of type positive-int, negative int (-10) given',
                ],
            ];

            $shard2 = [
                [
                    'file' => 'src/Payment.php',
                    'line' => 20,
                    'function' => 'App\\Payment::charge',
                    'kind' => 'parameter',
                    'target' => '$amount',
                    'expected' => 'positive-int',
                    'given' => 'negative int (-10)',
                    'message' => 'Argument $amount must be of type positive-int, negative int (-10) given',
                ],
                [
                    'file' => 'src/User.php',
                    'line' => 45,
                    'function' => 'App\\User::setEmail',
                    'kind' => 'parameter',
                    'target' => '$email',
                    'expected' => 'non-empty-string',
                    'given' => "empty string ('')",
                    'message' => "Argument \$email must be of type non-empty-string, empty string ('') given",
                ],
            ];

            file_put_contents($shardDir . '/shard_1_abc.json', json_encode($shard1));
            file_put_contents($shardDir . '/shard_2_def.json', json_encode($shard2));

            try {
                $exportedPath = ViolationCollector::exportReport($reportFile);

                expect($exportedPath)->toBe($reportFile)
                    ->and(file_exists($reportFile))->toBeTrue()
                    ->and(is_dir($shardDir))->toBeFalse()
                ;

                /** @var array{version: string, generated_at: string, summary: array{total_violations: int, files_affected: int}, violations: list<array<string, mixed>>} $doc */
                $doc = json_decode((string) file_get_contents($reportFile), true);

                expect($doc['version'])->toBe('1.0')
                    ->and($doc['summary']['total_violations'])->toBe(2)
                    ->and($doc['summary']['files_affected'])->toBe(2)
                    ->and($doc['violations'])->toHaveCount(2)
                ;
            } finally {
                if (file_exists($reportFile)) {
                    @unlink($reportFile);
                }
                @rmdir($tempDir);
            }
        });
    });

    describe('Metadata Parser', function () {
        test('parses return value diagnostics', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage('App\\Service::execute(): Return value must be of type string, int (123) returned');

            ViolationCollector::handle($err, 'return', 123, 'src/Service.php', 30);

            $violations = ViolationCollector::getViolations();
            expect($violations[0]->kind)->toBe('return')
                ->and($violations[0]->target)->toBe('return')
                ->and($violations[0]->expected)->toBe('string')
                ->and($violations[0]->given)->toBe('int (123)')
            ;
        });

        test('parses missing required property diagnostics', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage("App\\Data::validate(): Argument \$data is missing required key 'id'");

            ViolationCollector::handle($err, 'parameter', [], 'src/Data.php', 12);

            $violations = ViolationCollector::getViolations();
            expect($violations[0]->expected)->toBe("required 'id'")
                ->and($violations[0]->given)->toBe('missing')
            ;
        });

        test('parses unsealed unexpected key diagnostics', function () {
            Config::set(['on_violation' => 'report']);
            $err = new ErrorMessage("Argument \$payload contains unsealed unexpected key 'forbidden'");

            ViolationCollector::handle($err, 'parameter', [], 'src/Payload.php', 10);

            $violations = ViolationCollector::getViolations();
            expect($violations[0]->expected)->toBe('sealed shape')
                ->and($violations[0]->given)->toBe("unexpected key 'forbidden'")
            ;
        });
    });
});
