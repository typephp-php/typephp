<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

use ReflectionFunction;
use TypePHP\Exception\TypeError;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * @param Dog $dog
 *
 * @return Dog
 */
function testSubNamespaceParam(object $dog): object
{
    return $dog;
}

/**
 * @param Car $car
 *
 * @return Car
 */
function testSubNamespaceCarParam(object $car): object
{
    return $car;
}

describe('Sub-Namespace Use Import Resolution (use ...\Domain; Domain\Dog)', function () {
    test('resolves sub-namespaced class names with namespace use imports in resolveFqcn', function () {
        $ref = new ReflectionFunction('TypePHP\Tests\TypeChecking\InheritanceAndAttributes\testSubNamespaceParam');

        expect(SpecialTypeResolver::resolveFqcn('Domain\Dog', $ref))->toBe(Dog::class);
    });

    test('resolves sub-namespaced class names using resolveFqcnForFile', function () {
        expect(SpecialTypeResolver::resolveFqcnForFile('Domain\Dog', __FILE__))->toBe(Dog::class);
    });

    test('validates sub-namespaced class parameters and returns at runtime', function () {
        $dog = new Dog();
        $car = new Car();

        expect(testSubNamespaceParam($dog))->toBe($dog);

        expect(fn () => testSubNamespaceParam($car))
            ->toThrow(TypeError::class, Dog::class)
        ;
        expect(testSubNamespaceCarParam($car))->toBe($car);
    });
});
