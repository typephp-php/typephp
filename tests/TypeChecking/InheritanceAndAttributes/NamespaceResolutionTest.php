<?php

declare(strict_types=1);

namespace TypePHP\Tests\Feature;

use DateTime;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat as Feline;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * 1. Fully Qualified Name with leading backslash
 *
 * @param Dog $dog
 */
function testFullyQualifiedParam(object $dog): bool
{
    return true;
}

/**
 * 2. Short imported name without leading backslash
 *
 * @param Dog $dog
 */
function testShortImportedParam(object $dog): bool
{
    return true;
}

/**
 * 3. Aliased imported name (Cat as Feline)
 *
 * @param Feline $cat
 */
function testAliasedImportedParam(object $cat): bool
{
    return true;
}

/**
 * 4. Global native class without leading backslash
 *
 * @param DateTime $dt
 */
function testGlobalNativeClassParam(object $dt): bool
{
    return true;
}

describe('Namespace Resolution Strategies', function () {
    test('validates fully qualified class name with leading backslash (\Namespace\Class)', function () {
        expect(testFullyQualifiedParam(new Dog()))->toBeTrue();

        expect(fn () => testFullyQualifiedParam(new Car()))
            ->toThrow(\TypeError::class, 'TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('validates short imported class name without leading backslash (Dog)', function () {
        expect(testShortImportedParam(new Dog()))->toBeTrue();

        expect(fn () => testShortImportedParam(new Car()))
            ->toThrow(\TypeError::class, 'TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('validates aliased imported class name (Feline for Cat)', function () {
        expect(testAliasedImportedParam(new Feline()))->toBeTrue();

        expect(fn () => testAliasedImportedParam(new Dog()))
            ->toThrow(\TypeError::class, 'TypePHP\Tests\Fixtures\Domain\Cat')
        ;
    });

    test('validates global native class without leading backslash (DateTime)', function () {
        expect(testGlobalNativeClassParam(new DateTime()))->toBeTrue();

        expect(fn () => testGlobalNativeClassParam(new Dog()))
            ->toThrow(\TypeError::class, 'DateTime')
        ;
    });
});
