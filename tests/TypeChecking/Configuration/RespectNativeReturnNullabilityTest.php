<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Config;

/**
 * Function with native : ?array return, but non-nullable DocBlock
 *
 * @return list<positive-int>
 */
function testNativeNullableReturnFunction(bool $returnNull): ?array
{
    if ($returnNull) {
        return null;
    }

    return [10, 20, 30];
}

/**
 * Function with PHP 8.0+ native union return (array|null), but non-nullable DocBlock
 *
 * @return list<positive-int>
 */
function testNativeUnionReturnFunction(bool $returnNull): array|null
{
    if ($returnNull) {
        return null;
    }

    return [10, 20, 30];
}

class ReturnNullabilityService
{
    /**
     * Method with native : ?string, but non-nullable DocBlock
     *
     * @return non-empty-string
     */
    public function findUsername(bool $returnNull): ?string
    {
        if ($returnNull) {
            return null;
        }

        return 'Alice';
    }
}

describe('Respect Native Return Type Nullability', function () {
    afterEach(function () {
        Config::reset();
    });

    describe('When respect_native_nullability is true (Default Mode)', function () {
        test('allows null on function with native : ?array even when DocBlock omitted |null', function () {
            Config::set(['respect_native_nullability' => true]);

            expect(testNativeNullableReturnFunction(true))->toBeNull();
            expect(testNativeNullableReturnFunction(false))->toBe([10, 20, 30]);
        });

        test('allows null on method with native : ?string even when DocBlock omitted |null', function () {
            Config::set(['respect_native_nullability' => true]);
            $service = new ReturnNullabilityService();

            expect($service->findUsername(true))->toBeNull();
            expect($service->findUsername(false))->toBe('Alice');
        });

        test('allows null on function with native union return (array|null) even when DocBlock omitted |null', function () {
            Config::set(['respect_native_nullability' => true]);

            expect(testNativeUnionReturnFunction(true))->toBeNull();
            expect(testNativeUnionReturnFunction(false))->toBe([10, 20, 30]);
        });
    });

    describe('When respect_native_nullability is false (Strict Pedantic Mode)', function () {
        test('rejects null when DocBlock omitted |null even if native return has : ?array', function () {
            Config::set(['respect_native_nullability' => false]);

            expect(fn () => testNativeNullableReturnFunction(true))
                ->toThrow(TypeError::class, 'none returned')
            ;

            expect(testNativeNullableReturnFunction(false))->toBe([10, 20, 30]);
        });

        test('rejects null when DocBlock omitted |null even if native return has : ?string', function () {
            Config::set(['respect_native_nullability' => false]);
            $service = new ReturnNullabilityService();

            expect(fn () => $service->findUsername(true))
                ->toThrow(TypeError::class, 'none returned')
            ;

            expect($service->findUsername(false))->toBe('Alice');
        });
    });
});
