<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\Tests\Fixtures\Generics\Producer;

describe('Inline @var Annotation Instance Pre-binding', function () {
    test('prebinds template T on empty collection via @var annotation', function () {
        /** @var GenericCollection<Dog> $dogs */
        $dogs = new GenericCollection();

        // Adding a Dog succeeds because T was pre-bound to Dog
        $dogs->add(new Dog());
        expect($dogs->count())->toBe(1);
    });

    test('throws TypeError on first method call when argument violates pre-bound @var template', function () {
        /** @var GenericCollection<Dog> $dogs */
        $dogs = new GenericCollection();

        // First call ever and it will fail immediately because @var pre-bound T = Dog
        expect(fn () => $dogs->add(new Car()))
            ->toThrow(TypeError::class, 'Argument $item (template T = TypePHP\Tests\Fixtures\Domain\Dog) must be of type TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('prebinds nested generic structures via @var annotation', function () {
        /** @var GenericCollection<Producer<Dog>> $producers */
        $producers = new GenericCollection();

        $producers->add(new Producer(new Dog()));
        expect($producers->count())->toBe(1);
    });

    test('throws TypeError on first call when nested generic item violates pre-bound @var template', function () {
        /** @var GenericCollection<Producer<Dog>> $producers */
        $producers = new GenericCollection();

        expect(fn () => $producers->add(new Producer(new Car())))
            ->toThrow(TypeError::class)
        ;
    });

    test('prebinds template T with a multi-line generic type definition', function () {
        /**
         * @var GenericCollection<
         *     Dog
         * > $dogs
         */
        $dogs = new GenericCollection();

        $dogs->add(new Dog());
        expect($dogs->count())->toBe(1);

        expect(fn () => $dogs->add(new Car()))
            ->toThrow(TypeError::class)
        ;
    });

    test('prebinds template T with weird spacing and missing variable name', function () {
        /** @var GenericCollection< Dog   > */
        $dogs = new GenericCollection();

        $dogs->add(new Dog());
        expect($dogs->count())->toBe(1);

        expect(fn () => $dogs->add(new Car()))
            ->toThrow(TypeError::class)
        ;
    });

    test('prebinds template T when variable name precedes the type', function () {
        /** @var $dogs GenericCollection<Dog> */
        $dogs = new GenericCollection();

        $dogs->add(new Dog());
        expect($dogs->count())->toBe(1);

        expect(fn () => $dogs->add(new Car()))
            ->toThrow(TypeError::class)
        ;
    });

    test('prebinds deeply nested multi-line generics with asterisks', function () {
        /**
         * @var GenericCollection<
         *   Producer<
         *     Dog
         *   >
         * > $producers
         */
        $producers = new GenericCollection();

        $producers->add(new Producer(new Dog()));
        expect($producers->count())->toBe(1);

        expect(fn () => $producers->add(new Producer(new Car())))
            ->toThrow(TypeError::class)
        ;
    });
});
