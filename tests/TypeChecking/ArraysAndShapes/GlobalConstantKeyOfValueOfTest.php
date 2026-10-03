<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

const TEST_GLOBAL_DEFAULTS = [
    'timeout' => 30,
    'retries' => 3,
    'verbose' => 0,
];

const TEST_GLOBAL_ROLES = [
    'admin' => 1,
    'user' => 2,
    'guest' => 3,
];

const TEST_GLOBAL_MAX_RETRIES = 5;

if (! \defined('TEST_GLOBAL_HTTP_CODES')) {
    \define('TEST_GLOBAL_HTTP_CODES', [
        'ok' => 200,
        'not_found' => 404,
        'server_error' => 500,
    ]);
}

/**
 * @param key-of<TEST_GLOBAL_DEFAULTS> $key
 */
function testGlobalConstKeyOf(string $key): int
{
    return TEST_GLOBAL_DEFAULTS[$key] ?? 0;
}

/**
 * @param key-of<TEST_GLOBAL_HTTP_CODES> $key
 */
function testGlobalDefineKeyOf(string $key): int
{
    return TEST_GLOBAL_HTTP_CODES[$key] ?? 0;
}

/**
 * @param value-of<TEST_GLOBAL_DEFAULTS> $value
 */
function testGlobalConstValueOf(int $value): string
{
    return "Value: {$value}";
}

/**
 * @param value-of<TEST_GLOBAL_HTTP_CODES> $code
 */
function testGlobalDefineValueOf(int $code): string
{
    return "HTTP: {$code}";
}

/**
 * @param array{key: key-of<TEST_GLOBAL_DEFAULTS>, override: int} $config
 */
function testGlobalKeyOfInShape(array $config): int
{
    return $config['override'] !== 0 ? $config['override'] : (TEST_GLOBAL_DEFAULTS[$config['key']] ?? 0);
}

/**
 * @param array{role: value-of<TEST_GLOBAL_ROLES>, user: string} $data
 */
function testGlobalValueOfInShape(array $data): string
{
    return "{$data['user']}:{$data['role']}";
}

/**
 * @param list<key-of<TEST_GLOBAL_ROLES>> $keys
 */
function testGlobalKeyOfInList(array $keys): int
{
    return \count($keys);
}

/**
 * @return key-of<TEST_GLOBAL_DEFAULTS>
 */
function testGlobalKeyOfReturn(string $key): string
{
    return $key;
}

/**
 * @param int<1, TEST_GLOBAL_MAX_RETRIES> $n
 */
function testGlobalConstantIntRange(int $n): int
{
    return $n;
}

describe('Global Constant key-of, value-of, and int<min, max> Bounds', function () {
    describe('key-of with Global Constants', function () {
        test('accepts valid keys from global array constant declared via const', function () {
            expect(testGlobalConstKeyOf('timeout'))->toBe(30)
                ->and(testGlobalConstKeyOf('retries'))->toBe(3)
                ->and(testGlobalConstKeyOf('verbose'))->toBe(0)
            ;
        });

        test('rejects undeclared keys for global array constant declared via const', function () {
            expect(fn () => testGlobalConstKeyOf('nonexistent'))
                ->toThrow(TypeError::class, 'must be a key of TEST_GLOBAL_DEFAULTS, string \'nonexistent\' given')
            ;
        });

        test('accepts valid keys from global array constant defined via define()', function () {
            expect(testGlobalDefineKeyOf('ok'))->toBe(200)
                ->and(testGlobalDefineKeyOf('not_found'))->toBe(404)
                ->and(testGlobalDefineKeyOf('server_error'))->toBe(500)
            ;
        });

        test('rejects undeclared keys for global array constant defined via define()', function () {
            expect(fn () => testGlobalDefineKeyOf('invalid'))
                ->toThrow(TypeError::class, 'must be a key of TEST_GLOBAL_HTTP_CODES, string \'invalid\' given')
            ;
        });
    });

    describe('value-of with Global Constants', function () {
        test('accepts valid values from global array constant declared via const', function () {
            expect(testGlobalConstValueOf(30))->toBe('Value: 30')
                ->and(testGlobalConstValueOf(3))->toBe('Value: 3')
                ->and(testGlobalConstValueOf(0))->toBe('Value: 0')
            ;
        });

        test('rejects undeclared values for global array constant declared via const', function () {
            expect(fn () => testGlobalConstValueOf(999))
                ->toThrow(TypeError::class, 'must be a value of TEST_GLOBAL_DEFAULTS, int (999) given')
            ;
        });

        test('accepts valid values from global array constant defined via define()', function () {
            expect(testGlobalDefineValueOf(200))->toBe('HTTP: 200')
                ->and(testGlobalDefineValueOf(404))->toBe('HTTP: 404')
                ->and(testGlobalDefineValueOf(500))->toBe('HTTP: 500')
            ;
        });

        test('rejects undeclared values for global array constant defined via define()', function () {
            expect(fn () => testGlobalDefineValueOf(418))
                ->toThrow(TypeError::class, 'must be a value of TEST_GLOBAL_HTTP_CODES, int (418) given')
            ;
        });
    });

    describe('Nested Positions (Shapes, Lists, @var, @return)', function () {
        test('validates key-of inside array shape', function () {
            expect(testGlobalKeyOfInShape(['key' => 'timeout', 'override' => 0]))->toBe(30);

            expect(fn () => testGlobalKeyOfInShape(['key' => 'invalid', 'override' => 0]))
                ->toThrow(TypeError::class, "Argument \$config['key'] must be a key of TEST_GLOBAL_DEFAULTS, string 'invalid' given")
            ;
        });

        test('validates value-of inside array shape', function () {
            expect(testGlobalValueOfInShape(['role' => 1, 'user' => 'Alice']))->toBe('Alice:1');

            expect(fn () => testGlobalValueOfInShape(['role' => 99, 'user' => 'Alice']))
                ->toThrow(TypeError::class, "Argument \$data['role'] must be a value of TEST_GLOBAL_ROLES, int (99) given")
            ;
        });

        test('validates key-of inside generic list', function () {
            expect(testGlobalKeyOfInList(['admin', 'user']))->toBe(2);

            expect(fn () => testGlobalKeyOfInList(['admin', 'superuser']))
                ->toThrow(TypeError::class, "Argument \$keys[1] must be a key of TEST_GLOBAL_ROLES, string 'superuser' given")
            ;
        });

        test('validates key-of on return value', function () {
            expect(testGlobalKeyOfReturn('timeout'))->toBe('timeout');

            expect(fn () => testGlobalKeyOfReturn('invalid_key'))
                ->toThrow(TypeError::class, 'Return value must be a key of TEST_GLOBAL_DEFAULTS')
            ;
        });

        test('validates key-of on inline @var variable assignment', function () {
            /** @var key-of<TEST_GLOBAL_DEFAULTS> $valid */
            $valid = 'timeout';
            expect($valid)->toBe('timeout');

            expect(function () {
                /** @var key-of<TEST_GLOBAL_DEFAULTS> $bad */
                $bad = 'unknown_field';
            })->toThrow(TypeError::class, 'Variable $bad must be a key of TEST_GLOBAL_DEFAULTS, string \'unknown_field\' given');
        });
    });

    describe('Global Scalar Constants in int<min, max> Ranges', function () {
        test('resolves global constant as range bound (not evaluating to 0)', function () {
            expect(testGlobalConstantIntRange(1))->toBe(1)
                ->and(testGlobalConstantIntRange(3))->toBe(3)
                ->and(testGlobalConstantIntRange(5))->toBe(5)
            ;
        });

        test('rejects values exceeding global constant max bound', function () {
            expect(fn () => testGlobalConstantIntRange(10))
                ->toThrow(TypeError::class, 'Argument $n must be <= 5, 10 given')
            ;

            expect(fn () => testGlobalConstantIntRange(0))
                ->toThrow(TypeError::class, 'Argument $n must be >= 1, 0 given')
            ;
        });
    });
});
