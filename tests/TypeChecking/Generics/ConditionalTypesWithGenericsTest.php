<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\DogConditionalBox;
use TypePHP\Tests\Fixtures\Generics\GenericConditionalFactory;

/**
 * Standalone function with generic conditional return type
 *
 * @template T
 *
 * @param T $typeSample
 * @param mixed $value
 *
 * @return (T is Dog ? positive-int : non-empty-string)
 */
function testStandaloneGenericConditional(mixed $typeSample, mixed $value): mixed
{
    return $value;
}

describe('Conditional Types with Generics (T is Target ? If : Else)', function () {

    describe('Standalone Generic Functions', function () {

        test('evaluates positive-int branch when template T is inferred as Dog', function () {
            $dog = new Dog();

            expect(testStandaloneGenericConditional($dog, 100))->toBe(100);

            expect(fn () => testStandaloneGenericConditional($dog, -50))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });

        test('evaluates non-empty-string branch when template T is inferred as Cat', function () {
            $cat = new Cat();

            expect(testStandaloneGenericConditional($cat, 'valid_string'))->toBe('valid_string');

            expect(fn () => testStandaloneGenericConditional($cat, ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string')
            ;
        });

    });

    describe('Negated Conditional Types (T is not Target ? If : Else)', function () {

        test('evaluates non-empty-string branch when T is not Dog', function () {
            $factory = new GenericConditionalFactory();
            $cat = new Cat();

            expect($factory->processNegated($cat, 'hello'))->toBe('hello');

            expect(fn () => $factory->processNegated($cat, ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string')
            ;
        });

        test('evaluates positive-int branch when T is Dog in negated conditional', function () {
            $factory = new GenericConditionalFactory();
            $dog = new Dog();

            expect($factory->processNegated($dog, 42))->toBe(42);

            expect(fn () => $factory->processNegated($dog, -10))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });

    });

    describe('class-string<T> Factories with Conditional Return Types', function () {

        test('evaluates list<positive-int> when class-string is Dog::class', function () {
            $factory = new GenericConditionalFactory();

            expect($factory->createPayload(Dog::class, [10, 20, 30]))->toBe([10, 20, 30]);

            expect(fn () => $factory->createPayload(Dog::class, [10, -5]))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

        test('evaluates list<non-empty-string> when class-string is Cat::class', function () {
            $factory = new GenericConditionalFactory();

            expect($factory->createPayload(Cat::class, ['a', 'b']))->toBe(['a', 'b']);

            expect(fn () => $factory->createPayload(Cat::class, ['a', '']))
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

    });

    describe('Inherited Generic Classes with Conditionals', function () {

        test('evaluates conditional return types inherited from abstract parent generic class', function () {
            $dogBox = new DogConditionalBox();

            expect($dogBox->processInput(100))->toBe(100);

            expect(fn () => $dogBox->processInput(-99))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });

    });

});
