<?php

declare(strict_types=1);

/**
 * @param 12.34 $val
 */
function testSimpleFloatLiteralContract(float $val): float
{
    return $val;
}

/**
 * @param 0.3 $val
 */
function testPrecisionFloatLiteralContract(float $val): float
{
    return $val;
}

/**
 * @param 0.0 $val
 */
function testFloatZeroLiteralContract(float $val): float
{
    return $val;
}

/**
 * @param 10.0 $val
 */
function testFloatVsIntLiteralContract(mixed $val): mixed
{
    return $val;
}

describe('Float Literal Validation & IEEE 754 Precision', function () {
    test('accepts exact float literal values', function () {
        expect(testSimpleFloatLiteralContract(12.34))->toBe(12.34);

        expect(fn () => testSimpleFloatLiteralContract(12.35))
            ->toThrow(TypeError::class, 'must be literal 12.34')
        ;
    });

    test('handles IEEE 754 floating-point arithmetic precision (0.1 + 0.2 vs 0.3)', function () {
        $sum = 0.1 + 0.2; // Evaluates to 0.30000000000000004 in IEEE 754

        expect(testPrecisionFloatLiteralContract($sum))->toBe($sum);
    });

    test('handles float zero literals (0.0 vs -0.0)', function () {
        expect(testFloatZeroLiteralContract(0.0))->toBe(0.0);
        expect(testFloatZeroLiteralContract(-0.0))->toBe(-0.0);

        expect(fn () => testFloatZeroLiteralContract(1.5))
            ->toThrow(TypeError::class, 'must be literal 0.0')
        ;
    });

    test('accepts integer 10 for float literal 10.0 (integer coercion)', function () {
        expect(testFloatVsIntLiteralContract(10))->toBe(10);
        expect(testFloatVsIntLiteralContract(10.0))->toBe(10.0);

        expect(fn () => testFloatVsIntLiteralContract(10.5))
            ->toThrow(TypeError::class, 'must be literal 10.0')
        ;
    });
});
