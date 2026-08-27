<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;

/**
 * Function with ONLY a parameter contract
 *
 * @param positive-int $id
 */
function testParamOnlyFunction(int $id): int
{
    return $id;
}

/**
 * Function with ONLY a return contract
 *
 * @return positive-int
 */
function testReturnOnlyFunction(int $id): int
{
    return $id;
}

/**
 * Function with native nullable parameter (?array) but non-nullable docblock
 *
 * @param string[] $tags
 */
function testNativeNullableWithNonNullableDocblock(?array $tags = null): ?array
{
    return $tags;
}

/**
 * Function with PHP 8.0+ native union parameters (int|null, array|null) but non-nullable docblocks
 *
 * @param positive-int $id
 * @param string[] $tags
 */
function testNativeUnionWithNull(int|null $id = null, array|null $tags = null): array
{
    return [$id, $tags];
}

describe('Function Boundary Config Toggles (params & returns)', function () {
    afterEach(function () {
        Config::reset();
    });

    test('ignores parameter contract checks when params config is set to false', function () {
        Config::set(['params' => false]);

        $result = testParamOnlyFunction(-50);
        expect($result)->toBe(-50);
    });

    test('ignores return contract checks when returns config is set to false', function () {
        Config::set(['returns' => false]);

        $result = testReturnOnlyFunction(-100);
        expect($result)->toBe(-100);
    });

    test('skips template parameter validation when params config is set to false even if pre-bound', function () {
        Config::set([
            'params' => false,
            'inline_vars' => ['generics' => true],
        ]);

        /** @var GenericCollection<Dog> $dogs */
        $dogs = new GenericCollection();

        $dogs->add(new Car());
        expect($dogs->count())->toBe(1);
    });
});

describe('Respect Native Nullability Configuration (respect_native_nullability)', function () {
    afterEach(function () {
        Config::reset();
    });

    describe('PHP 7.1+ Nullable Syntax (?Type $param = null)', function () {
        test('accepts null when respect_native_nullability is true (default)', function () {
            Config::set(['respect_native_nullability' => true]);

            expect(testNativeNullableWithNonNullableDocblock(null))->toBeNull();
            expect(testNativeNullableWithNonNullableDocblock(['tag1', 'tag2']))->toBe(['tag1', 'tag2']);

            expect(fn () => testNativeNullableWithNonNullableDocblock([123]))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });

        test('rejects null when respect_native_nullability is false (strict pedantic mode)', function () {
            Config::set(['respect_native_nullability' => false]);

            expect(fn () => testNativeNullableWithNonNullableDocblock(null))
                ->toThrow(TypeError::class, 'must be of type array, null given')
            ;

            expect(testNativeNullableWithNonNullableDocblock(['tag1']))->toBe(['tag1']);
        });
    });

    describe('PHP 8.0+ Native Union Syntax (Type|null $param = null)', function () {
        test('accepts null on PHP 8 native unions when respect_native_nullability is true (default)', function () {
            Config::set(['respect_native_nullability' => true]);

            expect(testNativeUnionWithNull())->toBe([null, null]);
            expect(testNativeUnionWithNull(null, null))->toBe([null, null]);
            expect(testNativeUnionWithNull(42, ['php', 'typephp']))->toBe([42, ['php', 'typephp']]);

            expect(fn () => testNativeUnionWithNull(-10, ['php']))
                ->toThrow(TypeError::class, 'must be of type positive-int')
            ;

            expect(fn () => testNativeUnionWithNull(42, [999]))
                ->toThrow(TypeError::class, 'must be of type string')
            ;
        });

        test('rejects null on PHP 8 native unions when respect_native_nullability is false (strict pedantic mode)', function () {
            Config::set(['respect_native_nullability' => false]);

            expect(fn () => testNativeUnionWithNull(null, ['tag1']))
                ->toThrow(TypeError::class, 'must be of type positive-int, null given')
            ;

            expect(fn () => testNativeUnionWithNull(42, null))
                ->toThrow(TypeError::class, 'must be of type array, null given')
            ;

            expect(testNativeUnionWithNull(42, ['tag1']))->toBe([42, ['tag1']]);
        });
    });
});
