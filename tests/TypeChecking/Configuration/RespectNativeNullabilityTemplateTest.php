<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Function with native nullable parameter (?object $pet = null)
 * but non-nullable DocBlock template (@param T $pet)
 *
 * @template T of Animal
 *
 * @param T $pet
 */
function testNativeNullableWithTemplateDocblock(?object $pet = null): ?object
{
    return $pet;
}

/**
 * Function with explicit DocBlock nullable template (@param T|null $pet)
 *
 * @template T of Animal
 *
 * @param T|null $pet
 */
function testExplicitDocblockNullableTemplateParam(?object $pet = null): ?object
{
    return $pet;
}

/**
 * Generic class with constructor property promotion and non-nullable DocBlock template
 *
 * @template T of Animal
 */
class BoundedNullabilityContainer
{
    /**
     * @param T $pet
     */
    public function __construct(public ?object $pet = null)
    {
    }
}

describe('Respect Native Nullability with Generic Templates', function () {
    afterEach(function () {
        Config::reset();
    });

    describe('When respect_native_nullability is true (Default Pragmatic Mode)', function () {
        test('accepts null for native nullable parameter even when DocBlock template omitted |null', function () {
            Config::set(['respect_native_nullability' => true]);

            expect(testNativeNullableWithTemplateDocblock(null))->toBeNull();

            expect(testNativeNullableWithTemplateDocblock(new Dog()))->toBeInstanceOf(Dog::class);

            expect(fn () => testNativeNullableWithTemplateDocblock(new Car()))
                ->toThrow(TypeError::class)
            ;
        });

        test('accepts null in constructor property promotion for generic class when respect_native_nullability is true', function () {
            Config::set(['respect_native_nullability' => true]);

            $container = new BoundedNullabilityContainer(null);
            expect($container->pet)->toBeNull();

            $containerWithDog = new BoundedNullabilityContainer(new Dog());
            expect($containerWithDog->pet)->toBeInstanceOf(Dog::class);
        });
    });

    describe('When respect_native_nullability is false (Strict Pedantic Mode)', function () {
        test('rejects null when DocBlock template omitted |null even if native PHP allows null', function () {
            Config::set(['respect_native_nullability' => false]);

            expect(fn () => testNativeNullableWithTemplateDocblock(null))
                ->toThrow(TypeError::class, 'must be of type')
            ;

            expect(testNativeNullableWithTemplateDocblock(new Dog()))->toBeInstanceOf(Dog::class);
        });

        test('accepts null when DocBlock explicitly wrote T|null even when respect_native_nullability is false', function () {
            Config::set(['respect_native_nullability' => false]);

            expect(testExplicitDocblockNullableTemplateParam(null))->toBeNull();
            expect(testExplicitDocblockNullableTemplateParam(new Dog()))->toBeInstanceOf(Dog::class);
        });
    });
});
