<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Types\BitmaskFlags;

enum TestPermissionBitmaskEnum: int
{
    case Read = 1;
    case Write = 2;
    case Delete = 4;
}

/**
 * 1. Wildcard on int-backed enum: Permission::*
 *
 * @param int-mask-of<TestPermissionBitmaskEnum::*> $mask
 */
function testEnumWildcardBitmaskParam(int $mask): int
{
    return $mask;
}

/**
 * 2. Prefix wildcard on int-backed enum: Permission::PERM_*
 */
enum TestPrefixedBitmaskEnum: int
{
    case PERM_VIEW = 1;
    case PERM_EDIT = 2;
    case OTHER_FLAG = 8;
}

/**
 * @param int-mask-of<TestPrefixedBitmaskEnum::PERM_*> $mask
 */
function testEnumPrefixWildcardBitmaskParam(int $mask): int
{
    return $mask;
}

/**
 * 3. Specific enum cases in int-mask<Enum::CaseA, Enum::CaseB>
 *
 * @param int-mask<TestPermissionBitmaskEnum::Read, TestPermissionBitmaskEnum::Write> $mask
 */
function testSpecificEnumCasesMaskParam(int $mask): int
{
    return $mask;
}

/**
 * 4. Shorthand bare enum in int-mask-of<Enum>
 *
 * @param int-mask-of<TestPermissionBitmaskEnum> $mask
 */
function testBareEnumBitmaskParam(int $mask): int
{
    return $mask;
}

/**
 * @param int-mask<1, 2, 4> $mask
 */
function testLiteralIntMask(int $mask): int
{
    return $mask;
}

describe('int-mask and int-mask-of Annotations', function () {

    describe('int-mask<1, 2, 4>', function () {

        test('accepts valid bitmask flag combinations and zero', function () {
            expect(testLiteralIntMask(0))->toBe(0);             // No flags
            expect(testLiteralIntMask(1))->toBe(1);             // READ
            expect(testLiteralIntMask(3))->toBe(3);             // READ | WRITE
            expect(testLiteralIntMask(7))->toBe(7);             // READ | WRITE | EXECUTE
            expect(BitmaskFlags::checkLiteralMask(5))->toBe(5); // READ | EXECUTE
        });

        test('throws TypeError when bitmask contains illegal bits', function () {
            expect(fn () => testLiteralIntMask(8)) // 8 (1000) is outside allowed mask 7 (0111)
                ->toThrow(TypeError::class, 'must be a valid bitmask combination')
            ;

            expect(fn () => BitmaskFlags::checkLiteralMask(10))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination')
            ;
        });
    });

    describe('int-mask-of<self::FLAG_*>', function () {

        test('accepts valid bitmask combinations matching wildcard constants', function () {
            expect(BitmaskFlags::checkWildcardMask(1))->toBe(1);
            expect(BitmaskFlags::checkWildcardMask(3))->toBe(3);
            expect(BitmaskFlags::checkWildcardMask(7))->toBe(7);
        });

        test('throws TypeError on invalid bitmask for wildcard constants', function () {
            expect(fn () => BitmaskFlags::checkWildcardMask(16))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination')
            ;
        });
    });

    describe('Inline @var Constant Bitmasks', function () {

        test('enforces int-mask with specific class constants on inline variables', function () {
            /** @var int-mask<BitmaskFlags::FLAG_READ, BitmaskFlags::FLAG_WRITE> $mask */
            $mask = 1;
            expect($mask)->toBe(1);

            $mask = 3;
            expect($mask)->toBe(3);

            expect(fn () => $mask = 4)
                ->toThrow(TypeError::class, 'Variable $mask must be a valid bitmask combination')
            ;
        });

        test('enforces int-mask-of with wildcard constant patterns on inline variables', function () {
            /** @var int-mask-of<BitmaskFlags::FLAG_*> $wildcardMask */
            $wildcardMask = 7;
            expect($wildcardMask)->toBe(7);

            expect(fn () => $wildcardMask = 16)
                ->toThrow(TypeError::class, 'Variable $wildcardMask must be a valid bitmask combination')
            ;
        });
    });
});

describe('Enum int-mask-of and int-mask Validation', function () {
    describe('int-mask-of<Enum::*>', function () {
        test('accepts 0 (empty bitmask)', function () {
            expect(testEnumWildcardBitmaskParam(0))->toBe(0);
        });

        test('accepts single valid enum case values', function () {
            expect(testEnumWildcardBitmaskParam(1))->toBe(1); // Read
            expect(testEnumWildcardBitmaskParam(2))->toBe(2); // Write
            expect(testEnumWildcardBitmaskParam(4))->toBe(4); // Delete
        });

        test('accepts combined valid bitmask values', function () {
            expect(testEnumWildcardBitmaskParam(3))->toBe(3); // Read | Write (1 | 2)
            expect(testEnumWildcardBitmaskParam(5))->toBe(5); // Read | Delete (1 | 4)
            expect(testEnumWildcardBitmaskParam(7))->toBe(7); // Read | Write | Delete (1 | 2 | 4)
        });

        test('strictly throws TypeError when given an undefined bit (e.g. 8)', function () {
            // This currently fails because 8 does not throw!
            expect(fn () => testEnumWildcardBitmaskParam(8))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });

        test('strictly throws TypeError when given combined bits containing an undefined flag (e.g. 1 | 8 = 9)', function () {
            expect(fn () => testEnumWildcardBitmaskParam(9))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('int-mask-of<Enum::PREFIX_*>', function () {
        test('only combines cases matching the prefix wildcard', function () {
            expect(testEnumPrefixWildcardBitmaskParam(1))->toBe(1);
            expect(testEnumPrefixWildcardBitmaskParam(3))->toBe(3); // PERM_VIEW | PERM_EDIT (1 | 2)

            // OTHER_FLAG (8) is in the enum, but does NOT match PERM_*
            expect(fn () => testEnumPrefixWildcardBitmaskParam(8))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('int-mask<Enum::CaseA, Enum::CaseB>', function () {
        test('accepts allowed enum cases and combinations', function () {
            expect(testSpecificEnumCasesMaskParam(1))->toBe(1); // Read
            expect(testSpecificEnumCasesMaskParam(2))->toBe(2); // Write
            expect(testSpecificEnumCasesMaskParam(3))->toBe(3); // Read | Write

            expect(fn () => testSpecificEnumCasesMaskParam(4))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('int-mask-of<Enum> (Shorthand syntax without ::*)', function () {
        test('accepts valid cases using bare enum shorthand', function () {
            expect(testBareEnumBitmaskParam(1))->toBe(1);
            expect(testBareEnumBitmaskParam(7))->toBe(7);

            expect(fn () => testBareEnumBitmaskParam(8))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });
});
