<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Configuration;

use ReflectionClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\TypePHP;

class RedactValuesModelFixture
{
    /**
     * @var non-empty-string
     */
    public string $secretProperty = 'default';

    /**
     * @param non-empty-string $token
     * @param positive-int $pin
     *
     * @return non-empty-string
     */
    public function authenticate(string $token, int $pin): string
    {
        return "auth_{$token}_{$pin}";
    }

    /**
     * @return positive-int
     */
    public function getNegativeNumber(): int
    {
        return -9999;
    }
}

/**
 * @param non-empty-string $rawSecret
 * @param positive-int $secretCode
 * @param int<1, 100> $percentage
 */
function testRedactStandaloneFunction(string $rawSecret, int $secretCode, int $percentage): string
{
    return "{$rawSecret}:{$secretCode}:{$percentage}";
}

/**
 * @param class-string<\DateTimeInterface> $class
 */
function testRedactClassStringFunction(string $class): string
{
    return $class;
}

describe('Global Value Redaction (redact_values => true)', function () {
    beforeEach(function () {
        putenv('TYPEPHP_REDACT_VALUES=');
        unset($_ENV['TYPEPHP_REDACT_VALUES'], $_SERVER['TYPEPHP_REDACT_VALUES']);
        Config::reset();
        TypePHP::clearViolations();
    });

    afterEach(function () {
        putenv('TYPEPHP_REDACT_VALUES=');
        unset($_ENV['TYPEPHP_REDACT_VALUES'], $_SERVER['TYPEPHP_REDACT_VALUES']);
        TypePHP::clearViolations();
        Config::reset();
    });

    describe('1. Default Behavior (redact_values => false)', function () {
        test('shows raw value details in TypeError when redact_values is false (default)', function () {
            $rawSecret = 'my_super_secret_password_123';

            try {
                testRedactStandaloneFunction('', -42, 150);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain("empty string ('') given");
            }

            expect($failed)->toBeTrue();
        });
    });

    describe('2. Global Redaction Enabled (redact_values => true)', function () {
        test('redacts raw string parameters without #[SensitiveParameter] annotation', function () {
            Config::set(['redact_values' => true]);

            $rawSecret = 'super_confidential_credit_card_41111111';

            try {
                testRedactStandaloneFunction('wrong_token', -42, 50);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('int given')
                    ->and($e->getMessage())->not()->toContain('-42')
                    ->and($e->getMessage())->not()->toContain('negative int')
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts raw string values on empty string violations', function () {
            Config::set(['redact_values' => true]);

            try {
                testRedactStandaloneFunction('', 10, 50);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('string given')
                    ->and($e->getMessage())->not()->toContain("empty string ('')")
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts integer values in int<min, max> range violations', function () {
            Config::set(['redact_values' => true]);

            try {
                testRedactStandaloneFunction('valid_token', 10, 250);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('int given')
                    ->and($e->getMessage())->not()->toContain('250')
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts class-string values in class-string<T> violations', function () {
            Config::set(['redact_values' => true]);

            try {
                testRedactClassStringFunction(\stdClass::class);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('string given')
                    ->and($e->getMessage())->not()->toContain(\stdClass::class . "' given")
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts return value in return type contract violations', function () {
            Config::set(['redact_values' => true]);
            $model = new RedactValuesModelFixture();

            try {
                $model->getNegativeNumber();
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('int returned')
                    ->and($e->getMessage())->not()->toContain('-9999')
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts property values on property assignment violations', function () {
            Config::set(['redact_values' => true]);
            $model = new RedactValuesModelFixture();

            try {
                $model->secretProperty = '';
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('string given')
                    ->and($e->getMessage())->not()->toContain("empty string ('')")
                ;
            }

            expect($failed)->toBeTrue();
        });

        test('redacts local variable values on inline @var violations', function () {
            Config::set(['redact_values' => true]);

            try {
                /** @var positive-int $secretCode */
                $secretCode = -500;
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;
                expect($e->getMessage())->toContain('int given')
                    ->and($e->getMessage())->not()->toContain('-500')
                ;
            }

            expect($failed)->toBeTrue();
        });
    });

    describe('3. Audit Report Mode with Global Redaction (on_violation => report)', function () {
        test('redacts raw value from JSON violation record in report mode', function () {
            Config::set([
                'on_violation' => 'report',
                'redact_values' => true,
            ]);

            testRedactStandaloneFunction('', -999, 150);

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->given)->toBe('string')
                ->and($violations[0]->given)->not()->toContain("empty string ('')")
            ;
        });
    });

    describe('4. Environment Variable & Composer.json Configuration', function () {
        test('respects TYPEPHP_REDACT_VALUES environment variable', function () {
            putenv('TYPEPHP_REDACT_VALUES=true');

            expect(Config::isRedactValuesEnabled())->toBeTrue();

            putenv('TYPEPHP_REDACT_VALUES=false');
            expect(Config::isRedactValuesEnabled())->toBeFalse();
        });

        test('reads extra.typephp.redact-values from composer.json', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_redact_composer_' . uniqid();
            mkdir($tempDir, 0777, true);

            $composerJsonContent = json_encode([
                'name' => 'test/redact-values-config',
                'extra' => [
                    'typephp' => [
                        'redact-values' => true,
                    ],
                ],
            ]);
            file_put_contents($tempDir . '/composer.json', $composerJsonContent);

            try {
                Config::reset();

                $ref = new ReflectionClass(Config::class);
                $prop = $ref->getProperty('projectRoot');
                $prop->setValue(null, $tempDir);

                expect(Config::isRedactValuesEnabled())->toBeTrue();
            } finally {
                @unlink($tempDir . '/composer.json');
                @rmdir($tempDir);
                Config::reset();
            }
        });
    });
});
