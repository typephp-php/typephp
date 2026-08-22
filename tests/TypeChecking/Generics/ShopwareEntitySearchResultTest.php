<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Tests\Fixtures\Collections\ShopwareEntityCollection;
use TypePHP\Tests\Fixtures\Collections\ShopwareEntitySearchResult;
use TypePHP\Tests\Fixtures\Collections\SpecificDogSearchResult;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

describe('Shopware EntitySearchResult Hierarchy Binding Bug & Edge Cases', function () {
    describe('Simulated Unresolved Placeholder Injection', function () {
        test('reproduces EntitySearchResult validateType TElement placeholder bug', function () {
            $entities = new ShopwareEntityCollection();

            $typeNode = new GenericTypeNode(
                new IdentifierTypeNode(ShopwareEntityCollection::class),
                [new IdentifierTypeNode('TElement')]
            );

            RuntimeTypeChecker::bindInstanceFromNode($entities, $typeNode);

            $entities->setElementsDirectly(['dog_1' => new Dog()]);

            $searchResult = new ShopwareEntitySearchResult($entities);

            expect($searchResult)->toBeInstanceOf(ShopwareEntitySearchResult::class);
        });
    });

    describe('Multi-Tier 4-Level Generic Inheritance (Leaf -> Sub -> Mid -> Root)', function () {
        test('correctly binds concrete Dog template when leaf class declares @extends', function () {
            $dogEntities = new ShopwareEntityCollection();
            $dogEntities->setElementsDirectly(['dog_1' => new Dog(), 'dog_2' => new Dog()]);

            $specificResult = new SpecificDogSearchResult($dogEntities);

            expect(TypePHP::getGenericType($specificResult, 'TElement'))->toBe(Dog::class)
                ->and($specificResult->count())->toBe(2)
            ;
        });

        test('throws TypeError when concrete Leaf class receives invalid generic element', function () {
            $badEntities = new ShopwareEntityCollection();
            $badEntities->setElementsDirectly(['car_1' => new Car()]);

            expect(fn () => new SpecificDogSearchResult($badEntities))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Unparameterized SearchResult Iteration and Filtering', function () {
        test('yields items through getIterator() without throwing literal TElement errors on unparameterized collection', function () {
            $dogEntities = new ShopwareEntityCollection();
            $dogEntities->setElementsDirectly([
                'dog_1' => new Dog(),
                'dog_2' => new Dog(),
            ]);

            $searchResult = new ShopwareEntitySearchResult($dogEntities);

            $collected = [];
            foreach ($searchResult as $key => $item) {
                $collected[$key] = $item;
            }

            expect(\count($collected))->toBe(2)
                ->and($collected['dog_1'])->toBeInstanceOf(Dog::class)
                ->and($collected['dog_2'])->toBeInstanceOf(Dog::class)
            ;
        });

        test('executes filter() closures on unparameterized SearchResult without throwing literal TElement errors', function () {
            $dogEntities = new ShopwareEntityCollection();
            $dogEntities->setElementsDirectly([
                'dog_1' => new Dog(),
                'dog_2' => new Dog(),
            ]);

            $searchResult = new ShopwareEntitySearchResult($dogEntities);

            $filtered = $searchResult->filter(function ($item) {
                return $item instanceof Dog;
            });

            expect($filtered->count())->toBe(2);
        });

        test('executes filter() closures on concrete Leaf class verifying closure argument against bound type', function () {
            $dogEntities = new ShopwareEntityCollection();
            $dogEntities->setElementsDirectly(['dog_1' => new Dog()]);

            $specificResult = new SpecificDogSearchResult($dogEntities);

            $filtered = $specificResult->filter(function (Dog $dog) {
                return true;
            });

            expect($filtered->count())->toBe(1);
        });
    });
});
