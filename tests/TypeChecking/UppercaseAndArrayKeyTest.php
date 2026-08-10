<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\CurrencyFormatter;

/**
 * @param array-key $key
 */
function testArrayKeyParam(mixed $key): mixed
{
    return $key;
}

/**
 * @param uppercase-string $str
 */
function testUppercaseStringParam(string $str): string
{
    return $str;
}

/**
 * @param non-empty-uppercase-string $str
 */
function testNonEmptyUppercaseStringParam(string $str): string
{
    return $str;
}

describe('array-key, uppercase-string, and non-empty-uppercase-string Annotations', function () {

    describe('array-key (int|string)', function () {

        test('accepts integer and string keys', function () {
            expect(testArrayKeyParam(100))->toBe(100);
            expect(testArrayKeyParam('user_100'))->toBe('user_100');
        });

        test('throws TypeError when array-key is a boolean or array', function () {
            expect(fn () => testArrayKeyParam(true))
                ->toThrow(TypeError::class, 'must be of type array-key')
            ;

            expect(fn () => testArrayKeyParam([]))
                ->toThrow(TypeError::class, 'must be of type array-key')
            ;
        });

    });

    describe('uppercase-string & non-empty-uppercase-string', function () {

        test('accepts valid uppercase strings', function () {
            expect(testUppercaseStringParam('USD'))->toBe('USD');
            expect(testUppercaseStringParam(''))->toBe('');
            expect(testNonEmptyUppercaseStringParam('EUR'))->toBe('EUR');
        });

        test('throws TypeError on lowercase or mixed-case string for uppercase-string', function () {
            expect(fn () => testUppercaseStringParam('Usd'))
                ->toThrow(TypeError::class, 'must be of type uppercase-string')
            ;

            expect(fn () => testUppercaseStringParam('usd'))
                ->toThrow(TypeError::class, 'must be of type uppercase-string')
            ;
        });

        test('throws TypeError on empty string for non-empty-uppercase-string', function () {
            expect(fn () => testNonEmptyUppercaseStringParam(''))
                ->toThrow(TypeError::class, 'must be of type non-empty-uppercase-string')
            ;
        });

    });

    describe('Fixture Class Method Verification (CurrencyFormatter)', function () {

        test('validates parameters on CurrencyFormatter class methods', function () {
            $formatter = new CurrencyFormatter();

            expect($formatter->formatAccount('USD', 101))->toBe('USD_101');
            expect($formatter->formatAccount('GBP', 'acc_202'))->toBe('GBP_acc_202');

            expect(fn () => $formatter->formatAccount('usd', 101))
                ->toThrow(TypeError::class, 'must be of type non-empty-uppercase-string')
            ;

            expect(fn () => $formatter->formatAccount('USD', true))
                ->toThrow(TypeError::class, 'must be of type array-key')
            ;
        });

        test('validates static method returns on CurrencyFormatter', function () {
            expect(CurrencyFormatter::sanitizeCode('CAD'))->toBe('CAD');

            expect(fn () => CurrencyFormatter::sanitizeCode('cad'))
                ->toThrow(TypeError::class, 'Return value');
        });

    });

});
