<?php

declare(strict_types=1);

/**
 * 1. Integer Extended Types
 *
 * @param negative-int $neg
 * @param non-zero-int $nonZero
 * @param int<1, 100> $range
 */
function testIntegerConstraintsParam(int $neg, int $nonZero, int $range): bool
{
    return true;
}

/**
 * 2. String Extended Types
 *
 * @param numeric-string $num
 * @param lowercase-string $lower
 * @param callable-string $callableStr
 */
function testStringConstraintsParam(string $num, string $lower, string $callableStr): bool
{
    return true;
}

/**
 * 3. Truthiness & Numeric Types
 *
 * @param truthy $truthyVal
 * @param falsy $falsyVal
 * @param numeric $numVal
 */
function testTruthinessAndNumericParam(mixed $truthyVal, mixed $falsyVal, mixed $numVal): bool
{
    return true;
}

/**
 * 4. Literal Const / Enum Unions
 *
 * @param 'active'|'pending'|'closed' $status
 * @param 200|404|500 $code
 */
function testLiteralUnionsParam(string $status, int $code): bool
{
    return true;
}

describe('Extended Integer Types (negative-int, non-zero-int, int<1, 100>)', function () {
    test('accepts valid integer constraint parameters', function () {
        expect(testIntegerConstraintsParam(-10, 5, 50))->toBeTrue();
    });

    test('throws TypeError when negative-int parameter is >= 0', function () {
        expect(fn () => testIntegerConstraintsParam(0, 5, 50))
            ->toThrow(TypeError::class, 'negative-int')
        ;
    });

    test('throws TypeError when non-zero-int parameter is 0', function () {
        expect(fn () => testIntegerConstraintsParam(-10, 0, 50))
            ->toThrow(TypeError::class, 'non-zero-int')
        ;
    });

    test('throws TypeError when int range parameter violates bounds', function () {
        // 101 > max 100
        expect(fn () => testIntegerConstraintsParam(-10, 5, 101))
            ->toThrow(TypeError::class, 'int')
        ;
    });
});

describe('Extended String Types (numeric-string, lowercase-string, callable-string)', function () {
    test('accepts valid extended string parameters', function () {
        expect(testStringConstraintsParam('123.45', 'hello_world', 'strlen'))->toBeTrue();
    });

    test('throws TypeError when numeric-string is not numeric', function () {
        expect(fn () => testStringConstraintsParam('not_numeric', 'hello', 'strlen'))
            ->toThrow(TypeError::class, 'numeric-string')
        ;
    });

    test('throws TypeError when lowercase-string contains uppercase characters', function () {
        expect(fn () => testStringConstraintsParam('123', 'Hello', 'strlen'))
            ->toThrow(TypeError::class, 'lowercase-string')
        ;
    });

    test('throws TypeError when callable-string is not a callable function', function () {
        expect(fn () => testStringConstraintsParam('123', 'hello', 'invalid_func_12345'))
            ->toThrow(TypeError::class, 'callable-string')
        ;
    });
});

describe('Truthiness & Numeric Types (truthy, falsy, numeric)', function () {
    test('accepts valid truthy, falsy, and numeric values', function () {
        expect(testTruthinessAndNumericParam('valid_truthy', 0, '42.5'))->toBeTrue();
    });

    test('throws TypeError when truthy parameter evaluates to false', function () {
        expect(fn () => testTruthinessAndNumericParam(false, 0, '42.5'))
            ->toThrow(TypeError::class, 'truthy')
        ;
    });

    test('throws TypeError when falsy parameter evaluates to true', function () {
        expect(fn () => testTruthinessAndNumericParam('truthy', 'not_falsy', '42.5'))
            ->toThrow(TypeError::class, 'falsy')
        ;
    });

    test('throws TypeError when numeric parameter is not numeric', function () {
        expect(fn () => testTruthinessAndNumericParam('truthy', 0, 'not_a_number'))
            ->toThrow(TypeError::class, 'numeric')
        ;
    });
});

describe('Literal Value Enums (\'active\'|\'pending\', 200|404|500)', function () {
    test('accepts valid literal union values', function () {
        expect(testLiteralUnionsParam('active', 200))->toBeTrue();
        expect(testLiteralUnionsParam('pending', 404))->toBeTrue();
    });

    test('throws TypeError when string literal is not in union options', function () {
        expect(fn () => testLiteralUnionsParam('archived', 200))
            ->toThrow(TypeError::class)
        ;
    });

    test('throws TypeError when integer literal is not in union options', function () {
        expect(fn () => testLiteralUnionsParam('active', 301))
            ->toThrow(TypeError::class)
        ;
    });
});
