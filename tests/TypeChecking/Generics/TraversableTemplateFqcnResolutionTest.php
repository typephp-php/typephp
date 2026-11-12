<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ReflectionMethod;
use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Tests\Fixtures\Generics\ConcreteItem;
use TypePHP\Tests\Fixtures\Generics\GenericItemContainer;
use TypePHP\Tests\Fixtures\Generics\ItemContract;
use TypePHP\Tests\Fixtures\Generics\TraversableViaGetIteratorWithAlias;
use TypePHP\Tests\Fixtures\Generics\TraversableViaImplementsWithAlias;
use TypePHP\Tests\Fixtures\Generics\TraversableViaInheritedInterface;
use TypePHP\Tests\Fixtures\Generics\TraversableWithNestedGenericItem;

require_once __DIR__ . '/../../Fixtures/Generics/TraversableResolutionFixtures.php';

/**
 * @template T
 *
 * @param iterable<T> $collection
 *
 * @return list<T>
 */
function testConsumeExternalTraversable(iterable $collection): array
{
    $results = [];
    foreach ($collection as $item) {
        $results[] = $item;
    }

    return $results;
}

describe('ParamChecker::resolveTraversableItemTypes FQCN Resolution', function () {
    describe('A. Direct Inspection of Inferred TypeNode ASTs', function () {
        test('resolves aliased item types in @implements IteratorAggregate to FQCN', function () {
            $refMethod = new ReflectionMethod(ParamChecker::class, 'resolveTraversableItemTypes');
            $aggregate = new TraversableViaImplementsWithAlias([new ConcreteItem(1)]);

            $resolved = $refMethod->invoke(null, $aggregate);

            expect($resolved[1])->not->toBeNull();
            expect((string) $resolved[1])->toBe(ItemContract::class);
        });

        test('resolves aliased item types in getIterator() return tag to FQCN', function () {
            $refMethod = new ReflectionMethod(ParamChecker::class, 'resolveTraversableItemTypes');
            $aggregate = new TraversableViaGetIteratorWithAlias([new ConcreteItem(1)]);

            $resolved = $refMethod->invoke(null, $aggregate);

            expect($resolved[1])->not->toBeNull();
            expect((string) $resolved[1])->toBe(ItemContract::class);
        });

        test('resolves nested GenericTypeNode item types to FQCN base types', function () {
            $refMethod = new ReflectionMethod(ParamChecker::class, 'resolveTraversableItemTypes');
            $aggregate = new TraversableWithNestedGenericItem([new GenericItemContainer('payload')]);

            $resolved = $refMethod->invoke(null, $aggregate);

            expect($resolved[1])->not->toBeNull();
            expect((string) $resolved[1])->toBe(GenericItemContainer::class . '<string>');
        });

        test('resolves item types through intermediate inherited interfaces to FQCN', function () {
            $refMethod = new ReflectionMethod(ParamChecker::class, 'resolveTraversableItemTypes');
            $aggregate = new TraversableViaInheritedInterface([new ConcreteItem(1)]);

            $resolved = $refMethod->invoke(null, $aggregate);

            expect($resolved[1])->not->toBeNull();
            expect((string) $resolved[1])->toBe(ItemContract::class);
        });
    });

    describe('B. End-to-End Runtime Contract Validation', function () {
        test('successfully accepts items when traversable uses aliased @implements in another namespace', function () {
            $item = new ConcreteItem(42);
            $aggregate = new TraversableViaImplementsWithAlias([$item]);

            $consumed = testConsumeExternalTraversable($aggregate);

            expect($consumed)->toBe([$item]);
        });

        test('successfully accepts items when traversable uses aliased getIterator() DocBlock', function () {
            $item = new ConcreteItem(100);
            $aggregate = new TraversableViaGetIteratorWithAlias([$item]);

            $consumed = testConsumeExternalTraversable($aggregate);

            expect($consumed)->toBe([$item]);
        });

        test('successfully accepts items when traversable yields generic object items', function () {
            $container = new GenericItemContainer('alpha');
            $aggregate = new TraversableWithNestedGenericItem([$container]);

            $consumed = testConsumeExternalTraversable($aggregate);

            expect($consumed)->toBe([$container]);
        });

        test('successfully accepts items from inherited generic interface traversable', function () {
            $item = new ConcreteItem(7);
            $aggregate = new TraversableViaInheritedInterface([$item]);

            $consumed = testConsumeExternalTraversable($aggregate);

            expect($consumed)->toBe([$item]);
        });

        test('still enforces type safety by rejecting genuinely incompatible items', function () {
            $invalid = new stdClass();
            $aggregate = new TraversableViaImplementsWithAlias([$invalid]);

            expect(fn () => testConsumeExternalTraversable($aggregate))
                ->toThrow(TypeError::class, 'must be of type ' . ItemContract::class)
            ;
        });
    });
});
