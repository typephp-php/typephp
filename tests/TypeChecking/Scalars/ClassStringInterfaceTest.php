<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Types\ClassStringFactoryContainer;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;

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
});
