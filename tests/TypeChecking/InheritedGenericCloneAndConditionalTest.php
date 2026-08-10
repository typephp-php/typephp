<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\ChildGenericClonable;
use TypePHP\Tests\Fixtures\Generics\DogConditionalBox;

describe('Inherited Generic Classes with Clone and Conditional Return Types', function () {

    describe('Clone Syntax in Parent Generic Methods (clone $this)', function () {

        test('preserves generic template bindings when cloned from a parent method in inherited generic class', function () {
            /** @var ChildGenericClonable<Dog> $child */
            $child = new ChildGenericClonable(new Dog());

            $clonedChild = $child->duplicate();

            expect($clonedChild)->toBeInstanceOf(ChildGenericClonable::class);

            $clonedChild->setItem(new Dog());
            expect($clonedChild->item)->toBeInstanceOf(Dog::class);

            expect(fn () => $clonedChild->setItem(new Car()))
                ->toThrow(TypeError::class, 'template T = TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });

    });

    describe('Conditional Return Types in Inherited Generic Methods', function () {

        test('evaluates conditional return types based on inherited template mappings', function () {
            $box = new DogConditionalBox();

            expect($box->processInput(42))->toBe(42);

            expect(fn () => $box->processInput(-5))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });
    });
});
