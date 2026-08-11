<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\BitmaskFlags;

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
