<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\StatusEnum;

/**
 * 1. Class-String Subtypes Contract
 *
 * @param interface-string $iface
 * @param enum-string $enum
 */
function testClassStringSubtypesContract(string $iface, string $enum): bool
{
    return true;
}

/**
 * 2. Float Refinements Contract
 *
 * @param positive-float $pos
 * @param negative-float $neg
 * @param non-zero-float $nonZero
 */
function testFloatRefiningContract(float $pos, float $neg, float $nonZero): bool
{
    return true;
}

/**
 * 3. Truthy Strings Contract
 *
 * @param truthy-string $str
 */
function testTruthyStringContract(string $str): bool
{
    return true;
}

/**
 * 4. Never Return Control Contract
 *
 * @return never
 */
function testNeverReturnContract(bool $valid): mixed
{
    if ($valid) {
        throw new RuntimeException('Function exited via exception as required by @return never');
    }

    return 'unexpected_return_value'; // Violates @return never!
}

describe('New Scalar and Pseudo-Type Contracts', function () {
    describe('Class-String Subtypes (interface-string, enum-string)', function () {
        test('accepts valid interface-string and enum-string parameters', function () {
            expect(testClassStringSubtypesContract(DateTimeInterface::class, StatusEnum::class))->toBeTrue();
        });

        test('throws TypeError when interface-string is a standard class', function () {
            expect(fn () => testClassStringSubtypesContract(stdClass::class, StatusEnum::class))
                ->toThrow(TypeError::class, 'interface-string')
            ;
        });

        test('throws TypeError when enum-string is a standard class', function () {
            expect(fn () => testClassStringSubtypesContract(DateTimeInterface::class, stdClass::class))
                ->toThrow(TypeError::class, 'enum-string')
            ;
        });
    });

    describe('Float Refinements (positive-float, negative-float, non-zero-float)', function () {
        test('accepts valid float refinement parameters', function () {
            expect(testFloatRefiningContract(12.34, -5.5, 99.9))->toBeTrue();
        });

        test('throws TypeError when positive-float is <= 0', function () {
            expect(fn () => testFloatRefiningContract(-1.0, -5.5, 99.9))
                ->toThrow(TypeError::class, 'positive-float')
            ;
        });

        test('throws TypeError when non-zero-float is 0.0', function () {
            expect(fn () => testFloatRefiningContract(12.34, -5.5, 0.0))
                ->toThrow(TypeError::class, 'non-zero-float')
            ;
        });
    });

    describe('Truthy Strings (truthy-string)', function () {
        test('accepts strings that evaluate to true in boolean context', function () {
            expect(testTruthyStringContract('hello'))->toBeTrue();
            expect(testTruthyStringContract('1'))->toBeTrue();
        });

        test('throws TypeError when string evaluates to false in boolean context', function () {
            expect(fn () => testTruthyStringContract('0'))
                ->toThrow(TypeError::class, 'truthy-string')
            ;

            expect(fn () => testTruthyStringContract(''))
                ->toThrow(TypeError::class, 'truthy-string')
            ;
        });
    });

    describe('Never Return Type (@return never)', function () {
        test('accepts execution that throws an exception instead of returning', function () {
            expect(fn () => testNeverReturnContract(true))
                ->toThrow(RuntimeException::class, 'Function exited via exception')
            ;
        });

        test('throws TypeError when @return never function returns a value instead of exiting', function () {
            expect(fn () => testNeverReturnContract(false))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });
    });
});
