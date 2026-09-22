<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

if (PHP_VERSION_ID < 80200) {
    return;
}

use SensitiveParameter;
use TypePHP\Exception\TypeError;

/**
 * Standalone function with sensitive password
 *
 * @param non-empty-string $username
 * @param 'correct_secret' $password
 */
function testSensitiveLogin(
    string $username,
    #[SensitiveParameter]
    string $password
): bool {
    return true;
}

/**
 * Standalone function with multiple sensitive types (PIN and Array)
 *
 * @param positive-int $pin
 * @param array{secret_key: non-empty-string} $payload
 */
function testSensitiveMultiType(
    #[SensitiveParameter]
    int $pin,
    #[SensitiveParameter]
    array $payload
): bool {
    return true;
}

class SensitiveServiceFixture
{
    /**
     * Instance method with sensitive API key
     *
     * @param positive-int $userId
     * @param non-empty-string $apiKey
     */
    public function authenticate(
        int $userId,
        #[SensitiveParameter]
        string $apiKey
    ): bool {
        return true;
    }
}

class SensitiveCredentialsFixture
{
    /**
     * Constructor with promoted sensitive properties
     *
     * @param non-empty-string $bearerToken
     * @param positive-int $secretCode
     */
    public function __construct(
        #[SensitiveParameter]
        public string $bearerToken,
        #[SensitiveParameter]
        public int $secretCode,
    ) {
    }
}

interface SensitiveServiceInterface
{
    /**
     * @param non-empty-string $secret
     */
    public function process(#[SensitiveParameter] string $secret): bool;
}

class InheritedSensitiveService implements SensitiveServiceInterface
{
    public function process(string $secret): bool
    {
        return true;
    }
}

describe('SensitiveParameter Redaction (PHP 8.2+ #[SensitiveParameter])', function () {
    describe('Standalone Functions', function () {
        test('falls back to bare type name (like native PHP) and never leaks secret string', function () {
            $secretPassword = 'super_secret_raw_password_123';

            try {
                testSensitiveLogin('admin', $secretPassword);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain("'correct_secret', string given");
                expect($e->getMessage())->not()->toContain($secretPassword);
                expect($e->getMessage())->not()->toContain('[redacted]');
            }

            expect($failed)->toBeTrue();
        });

        test('does NOT redact normal parameters without #[SensitiveParameter]', function () {
            expect(fn () => testSensitiveLogin('', 'correct_secret'))
                ->toThrow(TypeError::class, "Argument \$username must be of type non-empty-string, empty string ('') given")
            ;
        });

        test('falls back to bare int given without leaking negative numbers', function () {
            $secretPin = -9999;

            try {
                testSensitiveMultiType($secretPin, ['secret_key' => 'valid']);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain('Argument $pin must be of type positive-int, int given');
                expect($e->getMessage())->not()->toContain('-9999');
            }

            expect($failed)->toBeTrue();
        });

        test('falls back to bare type when inner item violates sensitive array shape', function () {
            try {
                testSensitiveMultiType(1234, ['secret_key' => '']);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain("Argument \$payload['secret_key'] must be of type non-empty-string, string given");
                expect($e->getMessage())->not()->toContain('[redacted]');
            }

            expect($failed)->toBeTrue();
        });
    });

    describe('Class Methods & Constructors', function () {
        test('falls back to bare type on sensitive class method parameters', function () {
            $service = new SensitiveServiceFixture();
            $secretKey = '';

            try {
                $service->authenticate(42, $secretKey);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain('Argument $apiKey must be of type non-empty-string, string given');
            }

            expect($failed)->toBeTrue();
        });

        test('falls back to bare type on sensitive promoted constructor properties', function () {
            $secretCode = -42;

            try {
                new SensitiveCredentialsFixture('valid_token', $secretCode);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain('Argument $secretCode must be of type positive-int, int given');
                expect($e->getMessage())->not()->toContain('-42');
            }

            expect($failed)->toBeTrue();
        });
    });

    describe('Inherited Interface Contracts', function () {
        test('inherits #[SensitiveParameter] redaction from interface contracts', function () {
            $service = new InheritedSensitiveService();
            $leakedSecret = '';

            try {
                $service->process($leakedSecret);
                $failed = false;
            } catch (TypeError $e) {
                $failed = true;

                expect($e->getMessage())->toContain('Argument $secret must be of type non-empty-string, string given');
            }

            expect($failed)->toBeTrue();
        });
    });
});
