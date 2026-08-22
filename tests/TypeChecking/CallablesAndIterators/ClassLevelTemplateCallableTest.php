<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Collections\AnimalElementCollection;
use TypePHP\Tests\Fixtures\Collections\UnparameterizedElementCollection;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

describe('Class-Level Template Resolution in Callable & Iterator Parameters', function () {
    describe('Generic Subclasses (@extends Collection<Animal>)', function () {
        test('resolves class-level template TElement when filtering populated collection', function () {
            $collection = new AnimalElementCollection();
            $collection->setElementsDirectly([new Dog()]);

            $result = $collection->filter(function (Animal $animal) {
                return true;
            });

            expect($result->count())->toBe(1);
        });

        test('resolves class-level template TElement when iterating through getIterator()', function () {
            $collection = new AnimalElementCollection();
            $collection->setElementsDirectly([new Dog(), new Dog()]);

            $collected = [];
            foreach ($collection as $item) {
                $collected[] = $item;
            }

            expect(\count($collected))->toBe(2)
                ->and($collected[0])->toBeInstanceOf(Dog::class)
            ;
        });

        test('validates iterable parameter against class-level template TElement', function () {
            $collection = new AnimalElementCollection();
            $validIterator = new ArrayIterator([new Dog()]);

            $collection->mergeItems($validIterator);
            expect($collection->count())->toBe(1);

            $badIterator = new ArrayIterator([new Car()]);
            expect(fn () => $collection->mergeItems($badIterator))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Animal')
            ;
        });
    });

    describe('Unparameterized Collections (Unbound TElement -> mixed)', function () {
        test('falls back TElement to mixed on filter() closures without throwing literal TElement errors', function () {
            $collection = new UnparameterizedElementCollection();
            $collection->setElementsDirectly([new Dog(), 'string_val', new stdClass()]);

            $result = $collection->filter(function ($item) {
                return true;
            });

            expect($result->count())->toBe(3);
        });

        test('falls back TElement to mixed on getIterator() yields without throwing literal TElement errors', function () {
            $collection = new UnparameterizedElementCollection();
            $collection->setElementsDirectly([new Dog(), 42, ['array_val']]);

            $yielded = [];
            foreach ($collection as $item) {
                $yielded[] = $item;
            }

            expect(\count($yielded))->toBe(3);
        });

        test('accepts mixed items on mergeItems() iterable parameter when collection is unparameterized', function () {
            $collection = new UnparameterizedElementCollection();
            $mixedIterator = new ArrayIterator([new Dog(), new Car(), 100]);

            $collection->mergeItems($mixedIterator);
            expect($collection->count())->toBe(3);
        });
    });
});