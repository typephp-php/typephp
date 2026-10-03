<?php

declare(strict_types=1);

namespace TypePHP\Tests\Feature;

use Generator;
use SensitiveParameter;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\TypePHP;

if (PHP_VERSION_ID >= 80400) {
    require_once __DIR__ . '/../Fixtures/PropertyHooks/ReportingHookedFixture.php';
}

class ReportingModelFixture
{
    /**
     * @var positive-int
     */
    public int $score = 10;

    /**
     * @var non-empty-string
     */
    public static string $category = 'General';

    /**
     * @param positive-int $id
     * @param non-empty-string $name
     *
     * @return non-empty-string
     */
    public function formatUser(int $id, string $name): string
    {
        return "{$name}_{$id}";
    }

    /**
     * @return positive-int
     */
    public function invalidReturnMethod(): int
    {
        return -999;
    }
}

/**
 * @param positive-int $id
 * @param non-empty-string $label
 */
function testReportingParamFunc(int $id, string $label): string
{
    return "{$label}: {$id}";
}

/**
 * @return list<positive-int>
 */
function testReportingReturnFunc(bool $invalid = false): array
{
    if ($invalid) {
        return [10, -5, 30];
    }

    return [10, 20, 30];
}

/**
 * @param mixed &$code
 *
 * @param-out positive-int $code
 */
function testReportingParamOutFunc(mixed &$code): void
{
    $code = -50;
}

/**
 * @param callable(positive-int): non-empty-string $callback
 */
function testReportingCallableFunc(callable $callback): string
{
    return $callback(-10);
}

/**
 * @return Generator<string, positive-int>
 */
function testReportingGeneratorFunc(): Generator
{
    yield 'a' => 10;
    yield '' => -20;
    yield 'c' => 30;
}

/**
 * @param array{account: array{profile: array{score: positive-int}}} $payload
 */
function testReportingDeepShapeFunc(array $payload): int
{
    return $payload['account']['profile']['score'];
}

/**
 * @param non-empty-string $apiKey
 */
function testReportingSensitiveParamFunc(
    #[SensitiveParameter]
    string $apiKey
): string {
    return 'authenticated';
}

describe('Violation Reporting & Audit Mode (on_violation => report)', function () {
    beforeEach(function () {
        Config::reset();
        TypePHP::clearViolations();
        putenv('TYPEPHP_ON_VIOLATION');
        putenv('TYPEPHP_REPORT_FILE');
        putenv('TYPEPHP_FAIL_ON_REPORT');
    });

    afterEach(function () {
        putenv('TYPEPHP_ON_VIOLATION');
        putenv('TYPEPHP_REPORT_FILE');
        putenv('TYPEPHP_FAIL_ON_REPORT');
        TypePHP::clearViolations();
        Config::reset();
    });

    describe('Parameter Contracts in Report Mode', function () {
        test('allows function to execute to completion without throwing when parameters violate contract', function () {
            Config::set(['on_violation' => 'report']);

            $result = testReportingParamFunc(-10, '');

            expect($result)->toBe(': -10');

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('parameter')
                ->and($violations[0]->expected)->toBe('positive-int')
                ->and($violations[0]->given)->toBe('negative int (-10)')
            ;
        });

        test('allows method to execute to completion without throwing on invalid arguments', function () {
            Config::set(['on_violation' => 'report']);
            $service = new ReportingModelFixture();

            $result = $service->formatUser(-42, 'Alice');
            expect($result)->toBe('Alice_-42');

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('parameter')
                ->and($violations[0]->function)->toContain('formatUser')
                ->and($violations[0]->target)->toBe('$id')
            ;
        });
    });

    describe('Return Contracts in Report Mode', function () {
        test('returns invalid value directly without throwing when function return contract fails', function () {
            Config::set(['on_violation' => 'report']);

            $result = testReportingReturnFunc(invalid: true);
            expect($result)->toBe([10, -5, 30]);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('return')
                ->and($violations[0]->target)->toBe('return')
                ->and($violations[0]->expected)->toBe('positive-int')
            ;
        });

        test('returns invalid value on method return contract failure without throwing', function () {
            Config::set(['on_violation' => 'report']);
            $service = new ReportingModelFixture();

            $result = $service->invalidReturnMethod();
            expect($result)->toBe(-999);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('return')
                ->and($violations[0]->expected)->toBe('positive-int')
                ->and($violations[0]->given)->toBe('negative int (-999)')
            ;
        });
    });

    describe('Property Assignments in Report Mode', function () {
        test('allows invalid property assignment on object instance and records violation', function () {
            Config::set(['on_violation' => 'report']);
            $model = new ReportingModelFixture();

            $model->score = -50;
            expect($model->score)->toBe(-50);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('property')
                ->and($violations[0]->target)->toBe('$score')
                ->and($violations[0]->expected)->toBe('positive-int')
            ;
        });

        test('allows invalid static property assignment and records violation', function () {
            Config::set(['on_violation' => 'report']);

            ReportingModelFixture::$category = '';

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('property')
                ->and($violations[0]->target)->toBe('$category')
                ->and($violations[0]->expected)->toBe('non-empty-string')
            ;
        });
    });

    describe('Inline @var Variable Assignments in Report Mode', function () {
        test('allows invalid variable assignment and records violation', function () {
            Config::set(['on_violation' => 'report']);

            /** @var positive-int $userCount */
            $userCount = -100;
            expect($userCount)->toBe(-100);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('variable')
                ->and($violations[0]->target)->toBe('$userCount')
                ->and($violations[0]->expected)->toBe('positive-int')
            ;
        });
    });

    describe('By-Reference @param-out Contracts in Report Mode', function () {
        test('allows invalid by-reference post-condition mutation without throwing', function () {
            Config::set(['on_violation' => 'report']);

            $code = 'init';
            testReportingParamOutFunc($code);

            expect($code)->toBe(-50);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('param-out')
                ->and($violations[0]->target)->toBe('$code')
                ->and($violations[0]->expected)->toBe('positive-int')
            ;
        });
    });

    describe('Wrapped Callables & Closures in Report Mode', function () {
        test('allows wrapped callback to receive invalid argument and return invalid value without throwing', function () {
            Config::set(['on_violation' => 'report']);

            $badCallback = fn (int $n): string => '';

            $result = testReportingCallableFunc($badCallback);
            expect($result)->toBe('');

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('callback')
            ;
        });
    });

    describe('Iterators & Generators in Report Mode', function () {
        test('iterates through entire generator with invalid keys/values without interrupting loop', function () {
            Config::set(['on_violation' => 'report']);

            $collected = [];
            foreach (testReportingGeneratorFunc() as $k => $v) {
                $collected[$k] = $v;
            }

            expect($collected)->toBe([
                'a' => 10,
                '' => -20,
                'c' => 30,
            ]);

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('yield')
            ;
        });
    });

    describe('Generics & Template Bounds in Report Mode', function () {
        test('records violation when adding incompatible item to prebound generic collection without throwing', function () {
            Config::set(['on_violation' => 'report']);

            /** @var GenericCollection<Dog> $dogs */
            $dogs = new GenericCollection();

            $dogs->add(new Car());

            expect($dogs->count())->toBe(1);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('parameter')
                ->and($violations[0]->expected)->toContain(Dog::class)
            ;
        });
    });

    describe('Deeply Nested Shapes in Report Mode', function () {
        test('records breadcrumb path on deep nested shape failure without throwing', function () {
            Config::set(['on_violation' => 'report']);

            $payload = [
                'account' => [
                    'profile' => [
                        'score' => -10,
                    ],
                ],
            ];

            $res = testReportingDeepShapeFunc($payload);
            expect($res)->toBe(-10);

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->kind)->toBe('parameter')
                ->and($violations[0]->expected)->toBe('positive-int')
                ->and($violations[0]->given)->toBe('negative int (-10)')
            ;
        });
    });

    describe('PHP 8.2+ #[SensitiveParameter] Redaction in Report Mode', function () {
        test('never leaks raw secret string in report record for sensitive parameters', function () {
            if (PHP_VERSION_ID < 80200) {
                expect(true)->toBeTrue();

                return;
            }

            Config::set(['on_violation' => 'report']);

            $rawSecretKey = '';
            $res = testReportingSensitiveParamFunc($rawSecretKey);
            expect($res)->toBe('authenticated');

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->given)->toBe('string')
                ->and($violations[0]->given)->not()->toContain('super_secret')
            ;
        });
    });

    describe('PHP 8.4 Property Hooks in Report Mode', function () {
        test('allows hook execution on invalid property hook value and records violation', function () {
            if (PHP_VERSION_ID < 80400) {
                expect(true)->toBeTrue();

                return;
            }

            Config::set(['on_violation' => 'report']);
            $fixture = new \TypePHP\Tests\Fixtures\PropertyHooks\ReportingHookedFixture();

            $fixture->hookedScore = -5;
            expect($fixture->hookedScore)->toBe(-99);

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('property')
            ;
        });
    });

    describe('High-Volume Loop Stress Testing & Deduplication', function () {
        test('records exactly 1 violation in memory when loop executes 5,000 failing calls', function () {
            Config::set(['on_violation' => 'report']);

            for ($i = 0; $i < 5000; $i++) {
                testReportingParamFunc(-5, 'StressTest');
            }

            $violations = TypePHP::getViolations();
            expect($violations)->toHaveCount(1)
                ->and($violations[0]->expected)->toBe('positive-int')
            ;
        });

        test('emits E_USER_WARNING exactly once when loop executes 5,000 failing calls in warn mode', function () {
            Config::set(['on_violation' => 'warn']);

            $warnCount = 0;
            set_error_handler(function () use (&$warnCount): bool {
                $warnCount++;

                return true;
            });

            try {
                for ($i = 0; $i < 5000; $i++) {
                    testReportingParamFunc(-5, 'StressWarn');
                }
            } finally {
                restore_error_handler();
            }

            expect($warnCount)->toBe(1);
        });
    });

    describe('JSON Report Export & Corrupted Shard Recovery', function () {
        test('exports structured JSON report matching document schema via TypePHP::exportReport', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_feature_report_' . uniqid();
            mkdir($tempDir, 0777, true);
            $reportFilePath = $tempDir . '/typephp-report.json';

            Config::set([
                'on_violation' => 'report',
                'report_file' => $reportFilePath,
            ]);

            testReportingParamFunc(-10, 'Alpha');
            testReportingReturnFunc(invalid: true);

            $model = new ReportingModelFixture();
            $model->score = -99;

            $exportedPath = TypePHP::exportReport($reportFilePath);

            expect($exportedPath)->toBe($reportFilePath)
                ->and(file_exists($reportFilePath))->toBeTrue()
            ;

            /** @var array{version: string, generated_at: string, summary: array{total_violations: int, files_affected: int}, violations: list<array<string, mixed>>} $doc */
            $doc = json_decode((string) file_get_contents($reportFilePath), true);

            expect($doc)->toHaveKey('version')
                ->and($doc['version'])->toBe('1.0')
                ->and($doc['summary']['total_violations'])->toBe(3)
                ->and($doc['summary']['files_affected'])->toBeGreaterThanOrEqual(1)
                ->and($doc['violations'])->toHaveCount(3)
            ;

            @unlink($reportFilePath);
            @rmdir($tempDir);
        });

        test('handles corrupted shard files gracefully during exportReport without crashing', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_corrupt_shard_' . uniqid();
            $shardDir = $tempDir . '/.typephp-shards';
            mkdir($shardDir, 0777, true);
            $reportFilePath = $tempDir . '/typephp-report.json';

            Config::set([
                'on_violation' => 'report',
                'report_file' => $reportFilePath,
            ]);

            testReportingParamFunc(-10, 'ValidRecord');

            // Intentionally write broken JSON into shard folder
            file_put_contents($shardDir . '/shard_broken_123.json', '{{{ corrupted invalid json');

            $exported = TypePHP::exportReport($reportFilePath);

            expect($exported)->toBe($reportFilePath)
                ->and(file_exists($reportFilePath))->toBeTrue()
            ;

            /** @var array{version: string, summary: array{total_violations: int}, violations: list<array<string, mixed>>} $doc */
            $doc = json_decode((string) file_get_contents($reportFilePath), true);
            expect($doc['summary']['total_violations'])->toBe(1);

            @unlink($reportFilePath);
            @rmdir($tempDir);
        });
    });

    describe('Mode: throw (Default Verification)', function () {
        test('throws TypeError immediately when on_violation is throw (default behavior)', function () {
            Config::set(['on_violation' => 'throw']);

            expect(fn () => testReportingParamFunc(-10, 'Alpha'))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(TypePHP::getViolations())->toBeEmpty();
        });
    });
});
