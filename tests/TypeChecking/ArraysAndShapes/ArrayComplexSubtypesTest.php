<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

class ArraySubtypeBird extends Animal
{
}

class ArrayTripleInterfaceObject implements Countable, ArrayAccess, Iterator
{
    private array $data = ['a' => 1];

    public function count(): int
    {
        return \count($this->data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }

    public function rewind(): void
    {
        reset($this->data);
    }

    public function current(): mixed
    {
        return current($this->data);
    }

    public function key(): mixed
    {
        return key($this->data);
    }

    public function next(): void
    {
        next($this->data);
    }

    public function valid(): bool
    {
        return key($this->data) !== null;
    }
}

class ArrayDoubleInterfaceObject implements Countable, ArrayAccess
{
    private array $data = ['a' => 1];

    public function count(): int
    {
        return \count($this->data);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}

class ArrayCountableOnly implements Countable
{
    public function count(): int
    {
        return 1;
    }
}

/**
 * @param list<Dog|Cat|ArraySubtypeBird> $animals
 *
 * @return list<Dog|Cat|ArraySubtypeBird>
 */
function acceptSupersetAnimalList(array $animals): array
{
    return $animals;
}

/**
 * @param list<Dog|Cat> $animals
 *
 * @return list<Dog|Cat>
 */
function acceptSubsetAnimalList(array $animals): array
{
    return $animals;
}

/**
 * @param list<'admin'|'editor'|'viewer'|'guest'> $roles
 */
function acceptSupersetRolesList(array $roles): array
{
    return $roles;
}

/**
 * @param list<1|2|3|4|5> $numbers
 */
function acceptSupersetNumbersList(array $numbers): array
{
    return $numbers;
}

/**
 * @param array<'alpha'|'beta'|'gamma', Dog|Cat|ArraySubtypeBird> $map
 */
function acceptSupersetMap(array $map): array
{
    return $map;
}

/**
 * @param list<Countable&ArrayAccess> $items
 */
function acceptIntersectionList(array $items): array
{
    return $items;
}

/**
 * @param list<(Countable&ArrayAccess)|(Iterator&Countable)> $items
 */
function acceptDnfList(array $items): array
{
    return $items;
}

/**
 * @param list<list<Dog|Cat|ArraySubtypeBird>> $nested
 */
function acceptNestedAnimalList(array $nested): array
{
    return $nested;
}

describe('Array Complex Subtypes (Unions, Intersections, DNF)', function () {
    describe('Union Subset Subtyping in Arrays and Lists', function () {
        test('allows array containing subset class union into function expecting superset union (Dog and Cat into Dog|Cat|Bird)', function () {
            $subset = [new Dog(), new Cat()];

            $result = acceptSupersetAnimalList($subset);
            expect($result)->toBe($subset);
        });

        test('allows inline @var assignment of subset union into superset union list', function () {
            /** @var list<Dog|Cat> $subsetList */
            $subsetList = [new Dog(), new Cat()];

            /** @var list<Dog|Cat|ArraySubtypeBird> $supersetList */
            $supersetList = $subsetList;

            expect($supersetList)->toBe($subsetList);
        });

        test('allows string literal subset array into superset string literal list', function () {
            $subsetRoles = ['admin', 'editor'];

            expect(acceptSupersetRolesList($subsetRoles))->toBe($subsetRoles);

            /** @var list<'admin'|'editor'|'viewer'|'guest'> $targetList */
            $targetList = $subsetRoles;
            expect($targetList)->toBe($subsetRoles);
        });

        test('allows integer literal subset array into superset integer literal list', function () {
            $subsetNumbers = [1, 2, 5];

            expect(acceptSupersetNumbersList($subsetNumbers))->toBe($subsetNumbers);

            /** @var list<1|2|3|4|5> $targetList */
            $targetList = $subsetNumbers;
            expect($targetList)->toBe($subsetNumbers);
        });

        test('allows associative array with subset union keys and subset union values', function () {
            $subsetMap = [
                'alpha' => new Dog(),
                'beta' => new Cat(),
            ];

            expect(acceptSupersetMap($subsetMap))->toBe($subsetMap);
        });

        test('strictly rejects array with broader elements passed to function expecting narrower subset union', function () {
            $broadArray = [new Dog(), new Cat(), new ArraySubtypeBird()];

            expect(fn () => acceptSubsetAnimalList($broadArray))
                ->toThrow(TypeError::class)
            ;
        });

        test('strictly rejects array containing incompatible element not in union', function () {
            $incompatibleArray = [new Dog(), new Car()];

            expect(fn () => acceptSupersetAnimalList($incompatibleArray))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Intersection Subtyping in Arrays and Lists', function () {
        test('allows array of triple-interface objects into parameter expecting double-interface intersection list', function () {
            $tripleList = [new ArrayTripleInterfaceObject()];

            expect(acceptIntersectionList($tripleList))->toBe($tripleList);
        });

        test('allows inline @var assignment of triple-interface list into double-interface intersection list', function () {
            $tripleList = [new ArrayTripleInterfaceObject()];

            /** @var list<Countable&ArrayAccess> $intersectionList */
            $intersectionList = $tripleList;

            expect($intersectionList)->toBe($tripleList);
        });

        test('strictly rejects array with object missing one required interface of the intersection', function () {
            $incompleteList = [new ArrayCountableOnly()];

            expect(fn () => acceptIntersectionList($incompleteList))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Disjunctive Normal Form (DNF) in Arrays and Lists', function () {
        test('allows array of triple-interface objects into list expecting DNF ((A&B)|(C&D))', function () {
            $tripleList = [new ArrayTripleInterfaceObject()];

            expect(acceptDnfList($tripleList))->toBe($tripleList);
        });

        test('allows array with heterogeneous items satisfying different branches of DNF', function () {
            $mixedDnfList = [
                new ArrayDoubleInterfaceObject(),
                new ArrayTripleInterfaceObject(),
            ];

            expect(acceptDnfList($mixedDnfList))->toBe($mixedDnfList);
        });

        test('strictly rejects array containing an object that fails all DNF branches', function () {
            $badList = [
                new ArrayDoubleInterfaceObject(),
                new ArrayCountableOnly(),
            ];

            expect(fn () => acceptDnfList($badList))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Nested Complex Array Subtypes', function () {
        test('allows nested list of subset union into nested list expecting superset union', function () {
            $nestedSubset = [
                [new Dog()],
                [new Cat(), new Dog()],
            ];

            expect(acceptNestedAnimalList($nestedSubset))->toBe($nestedSubset);
        });

        test('strictly rejects nested list when an inner element violates the union', function () {
            $nestedBad = [
                [new Dog()],
                [new Cat(), new Car()],
            ];

            expect(fn () => acceptNestedAnimalList($nestedBad))
                ->toThrow(TypeError::class)
            ;
        });
    });
});
