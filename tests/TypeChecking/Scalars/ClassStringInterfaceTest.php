<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Types\ClassStringFactoryContainer;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;

/**
 * @param class-string<Dog|Cat> $petClass
 */
function testUnionClassStringFunction(string $petClass): string
{
    return $petClass;
}

/**
 * @template T of Dog|Cat
 *
 * @param class-string<T> $class
 * @param T $instance
 *
 * @return T
 */
function testTemplateWithUnionBoundClassString(string $class, object $instance): object
{
    return $instance;
}

/**
 * @template T of Countable&ArrayAccess
 *
 * @param class-string<T> $class
 */
function testTemplateWithIntersectionBoundClassString(string $class): string
{
    return $class;
}

describe('class-string<Interface> Subtype Validation', function () {

    test('accepts concrete class string implementing the target interface', function () {
        expect(ClassStringFactoryContainer::makeCountable(CountableArrayAccess::class))
            ->toBe(CountableArrayAccess::class)
        ;
    });

    test('accepts the interface string itself', function () {
        expect(ClassStringFactoryContainer::makeCountable(Countable::class))
            ->toBe(Countable::class)
        ;
    });

    test('throws TypeError when class string does not implement the target interface', function () {
        expect(fn () => ClassStringFactoryContainer::makeCountable(Car::class))
            ->toThrow(TypeError::class, 'must be a class-string of Countable')
        ;
    });

    test('accepts Dog or Cat class-string and rejects Car class-string in class-string<Dog|Cat>', function () {
        expect(testUnionClassStringFunction(Dog::class))->toBe(Dog::class);
        expect(testUnionClassStringFunction(Cat::class))->toBe(Cat::class);

        expect(fn () => testUnionClassStringFunction(Car::class))
            ->toThrow(TypeError::class, 'must be a class-string of')
        ;
    });

    test('accepts anonymous class implementing interface for class-string<Interface>', function () {
        $anonCountable = new class () implements Countable {
            public function count(): int
            {
                return 0;
            }
        };

        expect(ClassStringFactoryContainer::makeCountable($anonCountable::class))
            ->toBe($anonCountable::class)
        ;
    });

    test('accepts Dog::class or Cat::class for @template T of Dog|Cat in class-string<T>', function () {
        $dog = new Dog();
        $cat = new Cat();

        $res1 = testTemplateWithUnionBoundClassString(Dog::class, $dog);
        expect($res1)->toBe($dog);

        $res2 = testTemplateWithUnionBoundClassString(Cat::class, $cat);
        expect($res2)->toBe($cat);

        expect(fn () => testTemplateWithUnionBoundClassString(Car::class, new Car()))
            ->toThrow(TypeError::class, 'must be a class-string of')
        ;
    });

    test('accepts class implementing both Countable and ArrayAccess for @template T of Countable&ArrayAccess in class-string<T>', function () {
        expect(testTemplateWithIntersectionBoundClassString(ArrayObject::class))->toBe(ArrayObject::class);

        expect(fn () => testTemplateWithIntersectionBoundClassString(stdClass::class))
            ->toThrow(TypeError::class, 'must be a class-string of')
        ;
    });
});
