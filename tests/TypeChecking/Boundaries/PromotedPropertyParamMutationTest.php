<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

class PromotedParamAnimal
{
    /**
     * @param string $name
     * @param positive-int $age
     */
    public function __construct(
        public $name = 'hello',
        public int $age = 1,
    ) {
    }
}

class PromotedParamWithClassType
{
    /**
     * @param Dog $pet
     */
    public function __construct(
        public object $pet,
    ) {
    }
}

/**
 * @template T
 */
class PromotedGenericBox
{
    /**
     * @param T $item
     */
    public function __construct(
        public mixed $item
    ) {
    }
}

/**
 * @template K of array-key
 * @template V
 */
class PromotedGenericMap
{
    /**
     * @param K $key
     * @param V $val
     */
    public function __construct(
        public mixed $key,
        public mixed $val
    ) {
    }
}

describe('Promoted Property Mutation with Constructor @param Contracts', function () {
    describe('Standard Scalars and Classes', function () {
        test('validates property assignment when promoted property is typed via constructor @param', function () {
            $animal = new PromotedParamAnimal();

            $animal->name = 'Rex';
            expect($animal->name)->toBe('Rex');

            expect(fn () => $animal->name = 123)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedParamAnimal::$name must be of type string, int (123) given')
            ;
        });

        test('validates scalar refinement on promoted property mutation', function () {
            $animal = new PromotedParamAnimal();

            $animal->age = 5;
            expect($animal->age)->toBe(5);

            expect(fn () => $animal->age = -1)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedParamAnimal::$age must be of type positive-int, negative int (-1) given')
            ;
        });

        test('validates class object on promoted property mutation', function () {
            $dog = new Dog();
            $owner = new PromotedParamWithClassType($dog);

            $newDog = new Dog();
            $owner->pet = $newDog;
            expect($owner->pet)->toBe($newDog);

            expect(fn () => $owner->pet = new Car())
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedParamWithClassType::$pet must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });
    });

    describe('Generic Promoted Properties (@template T)', function () {
        test('validates mutation on promoted property bound to generic template T', function () {
            /** @var PromotedGenericBox<Dog> $box */
            $box = new PromotedGenericBox(new Dog());

            $newDog = new Dog();
            $box->item = $newDog;
            expect($box->item)->toBe($newDog);

            expect(fn () => $box->item = new Car())
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedGenericBox::$item must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });

        test('validates multiple promoted generic properties on mutation (Map<K, V>)', function () {
            /** @var PromotedGenericMap<string, positive-int> $map */
            $map = new PromotedGenericMap('alpha', 10);

            $map->key = 'beta';
            $map->val = 20;
            expect($map->key)->toBe('beta')->and($map->val)->toBe(20);

            expect(fn () => $map->key = 123)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedGenericMap::$key must be of type string')
            ;

            expect(fn () => $map->val = -5)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\TypeChecking\Boundaries\PromotedGenericMap::$val must be of type positive-int')
            ;
        });
    });
});
