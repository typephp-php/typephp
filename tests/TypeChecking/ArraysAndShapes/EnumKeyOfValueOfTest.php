<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Enums\TransactionStatus;

/**
 * @param key-of<Suit> $suitName
 */
function testUnitEnumKeyOf(string $suitName): string
{
    return $suitName;
}

/**
 * @param value-of<Suit> $suitValue
 */
function testUnitEnumValueOf(mixed $suitValue): mixed
{
    return $suitValue;
}

/**
 * @param key-of<TransactionStatus> $statusName
 */
function testIntBackedEnumKeyOf(string $statusName): string
{
    return $statusName;
}

/**
 * @param value-of<TransactionStatus> $statusCode
 */
function testIntBackedEnumValueOf(int $statusCode): int
{
    return $statusCode;
}

describe('key-of and value-of with UnitEnums and Int BackedEnums', function () {
    describe('Pure UnitEnums (key-of<UnitEnum> vs value-of<UnitEnum>)', function () {
        test('key-of<UnitEnum> accepts exact case names', function () {
            expect(testUnitEnumKeyOf('Hearts'))->toBe('Hearts');
            expect(testUnitEnumKeyOf('Spades'))->toBe('Spades');
        });

        test('key-of<UnitEnum> rejects invalid case names and lowercase names', function () {
            expect(fn () => testUnitEnumKeyOf('hearts'))
                ->toThrow(TypeError::class, 'must be a key of enum TypePHP\Tests\Fixtures\Enums\Suit')
            ;

            expect(fn () => testUnitEnumKeyOf('InvalidSuit'))
                ->toThrow(TypeError::class, 'must be a key of enum TypePHP\Tests\Fixtures\Enums\Suit')
            ;
        });

        test('value-of<UnitEnum> throws TypeError because UnitEnums have no backing values', function () {
            expect(fn () => testUnitEnumValueOf('Hearts'))
                ->toThrow(TypeError::class, 'must be a value of enum TypePHP\Tests\Fixtures\Enums\Suit')
            ;

            expect(fn () => testUnitEnumValueOf(1))
                ->toThrow(TypeError::class, 'must be a value of enum TypePHP\Tests\Fixtures\Enums\Suit')
            ;
        });
    });

    describe('Integer BackedEnums (key-of<IntEnum> vs value-of<IntEnum>)', function () {
        test('key-of<IntEnum> accepts case names', function () {
            expect(testIntBackedEnumKeyOf('PENDING'))->toBe('PENDING');
            expect(testIntBackedEnumKeyOf('COMPLETED'))->toBe('COMPLETED');
        });

        test('key-of<IntEnum> rejects invalid case names', function () {
            expect(fn () => testIntBackedEnumKeyOf('pending'))
                ->toThrow(TypeError::class, 'must be a key of enum TypePHP\Tests\Fixtures\Enums\TransactionStatus')
            ;
        });

        test('value-of<IntEnum> accepts backing integers', function () {
            expect(testIntBackedEnumValueOf(1))->toBe(1);
            expect(testIntBackedEnumValueOf(2))->toBe(2);
        });

        test('value-of<IntEnum> rejects non-existent integers and string numbers', function () {
            expect(fn () => testIntBackedEnumValueOf(99))
                ->toThrow(TypeError::class, 'must be a value of enum TypePHP\Tests\Fixtures\Enums\TransactionStatus')
            ;
        });
    });
});
