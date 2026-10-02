<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

/**
 * 1. Single composite global constant
 *
 * @param int-mask<E_ALL> $levels
 */
function testGlobalErrorLevelsMask(int $levels): int
{
    return $levels;
}

/**
 * 2. Specific individual global constants
 *
 * @param int-mask<E_ERROR, E_WARNING> $levels
 */
function testSpecificGlobalFlagsMask(int $levels): int
{
    return $levels;
}

/**
 * 3. Wildcard pattern of global constants
 *
 * @param int-mask-of<E_*> $levels
 */
function testGlobalWildcardErrorMask(int $levels): int
{
    return $levels;
}

/**
 * 4. Other extension global constants (JSON)
 *
 * @param int-mask<JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES> $flags
 */
function testJsonFlagsMask(int $flags): int
{
    return $flags;
}

/**
 * 5. Return type contract with global constant int-mask
 *
 * @return int-mask<E_ALL>
 */
function returnGlobalErrorMask(int $levels): int
{
    return $levels;
}

describe('Global Constant Bitmasks (int-mask<E_ALL> and int-mask-of<E_*>)', function () {
    describe('Single Global Composite Constant (int-mask<E_ALL>)', function () {
        test('accepts E_ALL constant itself', function () {
            expect(testGlobalErrorLevelsMask(E_ALL))->toBe(E_ALL);
        });

        test('accepts single error bit included in E_ALL', function () {
            expect(testGlobalErrorLevelsMask(E_ERROR))->toBe(E_ERROR);
            expect(testGlobalErrorLevelsMask(E_WARNING))->toBe(E_WARNING);
        });

        test('accepts combination of error bits included in E_ALL', function () {
            $mask = E_ERROR | E_WARNING | E_PARSE;
            expect(testGlobalErrorLevelsMask($mask))->toBe($mask);
        });

        test('accepts 0 as valid empty bitmask', function () {
            expect(testGlobalErrorLevelsMask(0))->toBe(0);
        });

        test('rejects bit outside of E_ALL', function () {
            expect(fn () => testGlobalErrorLevelsMask(1 << 30))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('Multiple Global Constants (int-mask<E_ERROR, E_WARNING>)', function () {
        test('accepts allowed flags and combination', function () {
            expect(testSpecificGlobalFlagsMask(E_ERROR))->toBe(E_ERROR);
            expect(testSpecificGlobalFlagsMask(E_WARNING))->toBe(E_WARNING);
            expect(testSpecificGlobalFlagsMask(E_ERROR | E_WARNING))->toBe(E_ERROR | E_WARNING);
            expect(testSpecificGlobalFlagsMask(0))->toBe(0);
        });

        test('rejects unlisted global constant flag (E_NOTICE)', function () {
            expect(fn () => testSpecificGlobalFlagsMask(E_NOTICE))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('Global Wildcard Constants (int-mask-of<E_*>)', function () {
        test('accepts any combination of E_* constants', function () {
            expect(testGlobalWildcardErrorMask(E_ERROR | E_WARNING))->toBe(E_ERROR | E_WARNING);
            expect(testGlobalWildcardErrorMask(E_ALL))->toBe(E_ALL);
        });

        test('rejects undeclared bits for global wildcard', function () {
            expect(fn () => testGlobalWildcardErrorMask(1 << 30))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('JSON Global Constants (int-mask<JSON_PRETTY_PRINT, JSON_UNESCAPED_SLASHES>)', function () {
        test('accepts valid JSON constants and combinations', function () {
            expect(testJsonFlagsMask(JSON_PRETTY_PRINT))->toBe(JSON_PRETTY_PRINT);
            expect(testJsonFlagsMask(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))->toBe(JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        });

        test('rejects unlisted JSON constant (JSON_FORCE_OBJECT)', function () {
            expect(fn () => testJsonFlagsMask(JSON_FORCE_OBJECT))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination of the allowed flags')
            ;
        });
    });

    describe('Return Types and Inline @var with Global Constants', function () {
        test('validates return type with global constant int-mask', function () {
            expect(returnGlobalErrorMask(E_ALL))->toBe(E_ALL);

            expect(fn () => returnGlobalErrorMask(1 << 30))
                ->toThrow(TypeError::class, 'Return value must be a valid bitmask combination of the allowed flags')
            ;
        });

        test('validates inline @var with global constant int-mask', function () {
            /** @var int-mask<E_ALL> $mask */
            $mask = E_ALL;
            expect($mask)->toBe(E_ALL);

            expect(function () {
                /** @var int-mask<E_ALL> $badMask */
                $badMask = 1 << 30;
            })->toThrow(TypeError::class, 'Variable $badMask must be a valid bitmask combination of the allowed flags');
        });
    });
});
