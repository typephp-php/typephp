<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

class Bird extends Animal
{
}
class Fish extends Animal
{
}

/**
 * Fixture representing an object that implements THREE interfaces
 */
class TripleInterfaceObject implements Countable, ArrayAccess, Iterator
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

class DoubleInterfaceObject implements Countable, ArrayAccess
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

/**
 * @template T
 */
class TypeSetHolder
{
    /**
     * @var array<int, T>
     */
    public array $items = [];
}

describe('Complex Union, Intersection, and DNF Subtyping Guarantees', function () {
    describe('Union Subset Assignability', function () {
        test('allows assigning subset class union into superset class union (Dog|Cat into Dog|Cat|Bird)', function () {
            /** @var TypeSetHolder<Dog|Cat|Bird> $broadContainer */
            $broadContainer = new TypeSetHolder();

            /** @var TypeSetHolder<Dog|Cat> $narrowContainer */
            $narrowContainer = new TypeSetHolder();

            $broadContainer = $narrowContainer;
            expect($broadContainer)->toBe($narrowContainer);
        });

        test('allows assigning subset string literal union into superset string literal union', function () {
            /** @var TypeSetHolder<'admin'|'editor'|'viewer'|'guest'> $broadRoles */
            $broadRoles = new TypeSetHolder();

            /** @var TypeSetHolder<'admin'|'editor'> $narrowRoles */
            $narrowRoles = new TypeSetHolder();

            $broadRoles = $narrowRoles;
            expect($broadRoles)->toBe($narrowRoles);
        });

        test('allows assigning subset integer literal union into superset integer union', function () {
            /** @var TypeSetHolder<1|2|3|4|5> $broadNumbers */
            $broadNumbers = new TypeSetHolder();

            /** @var TypeSetHolder<1|2> $narrowNumbers */
            $narrowNumbers = new TypeSetHolder();

            $broadNumbers = $narrowNumbers;
            expect($broadNumbers)->toBe($narrowNumbers);
        });

        test('strictly rejects assigning broader union into narrower subset union', function () {
            /** @var TypeSetHolder<Dog|Cat> $narrowContainer */
            $narrowContainer = new TypeSetHolder();

            /** @var TypeSetHolder<Dog|Cat|Bird> $broadContainer */
            $broadContainer = new TypeSetHolder();

            // Assigning broader (Dog|Cat|Bird) into narrower (Dog|Cat) must fail!
            expect(function () use (&$narrowContainer, $broadContainer) {
                $narrowContainer = $broadContainer;
            })->toThrow(TypeError::class);
        });
    });

    describe('Intersection Subtyping (More Specific Intersection into Broader Intersection)', function () {
        test('allows triple-interface object into variable expecting double-interface intersection', function () {
            /** @var Countable&ArrayAccess $expected */
            $expected = new TripleInterfaceObject();

            expect($expected)->toBeInstanceOf(TripleInterfaceObject::class);
        });

        test('allows generic container of triple-interface objects into container expecting double-interface intersection', function () {
            /** @var TypeSetHolder<Countable&ArrayAccess> $container */
            $container = new TypeSetHolder();

            /** @var TypeSetHolder<Countable&ArrayAccess&Iterator> $tripleContainer */
            $tripleContainer = new TypeSetHolder();

            $container = $tripleContainer;
            expect($container)->toBe($tripleContainer);
        });

        test('strictly rejects object missing one required interface of the intersection', function () {
            expect(function () {
                /** @var Countable&ArrayAccess&Iterator $strictContainer */
                $strictContainer = new DoubleInterfaceObject();
            })->toThrow(TypeError::class);
        });
    });

    describe('Disjunctive Normal Form (DNF) Subtyping ((A&B) | (C&D))', function () {
        test('allows triple-interface object into DNF union of intersections', function () {
            /** @var (Countable&ArrayAccess)|(Iterator&Countable) $dnfTarget */
            $dnfTarget = new TripleInterfaceObject();

            expect($dnfTarget)->toBeInstanceOf(TripleInterfaceObject::class);
        });

        test('allows generic container of triple-interface objects into container expecting DNF union', function () {
            /** @var TypeSetHolder<(Countable&ArrayAccess)|(Iterator&Countable)> $dnfContainer */
            $dnfContainer = new TypeSetHolder();

            /** @var TypeSetHolder<Countable&ArrayAccess&Iterator> $tripleContainer */
            $tripleContainer = new TypeSetHolder();

            $dnfContainer = $tripleContainer;
            expect($dnfContainer)->toBe($tripleContainer);
        });

        test('strictly rejects object failing all branches of the DNF union', function () {
            expect(function () {
                /** @var (Countable&ArrayAccess)|(Iterator&Countable) $dnfTarget */
                $dnfTarget = new Car();
            })->toThrow(TypeError::class);
        });
    });
});
