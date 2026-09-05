<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

class NormalBird extends Animal
{
}

/**
 * Quadruple-interface object (Countable & ArrayAccess & Iterator & Stringable)
 */
class NormalQuadObject implements Countable, ArrayAccess, Iterator, Stringable
{
    private array $data = ['key' => 'value'];

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

    public function __toString(): string
    {
        return 'quad_object';
    }
}

/**
 * Satisfies Branch A: (Countable & ArrayAccess)
 */
class NormalBranchAObject implements Countable, ArrayAccess
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
 * Satisfies Branch B: (Iterator & Stringable)
 */
class NormalBranchBObject implements Iterator, Stringable
{
    private array $data = ['b' => 2];

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

    public function __toString(): string
    {
        return 'branch_b_object';
    }
}

/**
 * Cross-Over Object:
 * Implements Countable (from A) and Stringable (from B),
 * but misses ArrayAccess (fails A) and misses Iterator (fails B).
 */
class NormalCrossOverObject implements Countable, Stringable
{
    public function count(): int
    {
        return 1;
    }

    public function __toString(): string
    {
        return 'crossover_object';
    }
}

class NormalSingleCountable implements Countable
{
    public function count(): int
    {
        return 1;
    }
}

/**
 * Class + Interface Intersection
 */
class NormalSpecialDog extends Dog implements Countable
{
    public function count(): int
    {
        return 5;
    }
}

/**
 * @param Dog|Cat|NormalBird $animal
 *
 * @return Dog|Cat|NormalBird
 */
function processNormalUnionAnimal(Animal $animal): Animal
{
    return $animal;
}

/**
 * @param 'admin'|'editor'|'viewer'|'guest' $role
 *
 * @return 'admin'|'editor'|'viewer'|'guest'
 */
function processNormalUnionLiteralRole(string $role): string
{
    return $role;
}

/**
 * @param 1|2|3|4|5 $number
 *
 * @return 1|2|3|4|5
 */
function processNormalUnionLiteralNumber(int $number): int
{
    return $number;
}

/**
 * @param positive-int|non-empty-string $idOrCode
 *
 * @return positive-int|non-empty-string
 */
function processNormalUnionRefinements(mixed $idOrCode): mixed
{
    return $idOrCode;
}

/**
 * @param Countable&ArrayAccess $collection
 *
 * @return Countable&ArrayAccess
 */
function processNormalIntersection(object $collection): object
{
    return $collection;
}

/**
 * @param Dog&Countable $pet
 *
 * @return Dog&Countable
 */
function processNormalClassInterfaceIntersection(object $pet): object
{
    return $pet;
}

/**
 * DNF: (Countable & ArrayAccess) | (Iterator & Stringable)
 *
 * @param (Countable&ArrayAccess)|(Iterator&Stringable) $dnf
 *
 * @return (Countable&ArrayAccess)|(Iterator&Stringable)
 */
function processNormalDnf(object $dnf): object
{
    return $dnf;
}

describe('Normal Non-Generic Union, Intersection, and DNF Subtyping', function () {
    describe('Union Parameter and Return Subtyping', function () {
        test('accepts single subtype instance into union parameter (Dog into Dog|Cat|Bird)', function () {
            $dog = new Dog();
            $cat = new Cat();
            $bird = new NormalBird();

            expect(processNormalUnionAnimal($dog))->toBe($dog)
                ->and(processNormalUnionAnimal($cat))->toBe($cat)
                ->and(processNormalUnionAnimal($bird))->toBe($bird)
            ;
        });

        test('allows inline @var assignment of narrower union into broader union variable', function () {
            /** @var Dog|Cat $narrow */
            $narrow = new Dog();

            /** @var Dog|Cat|NormalBird $broad */
            $broad = $narrow;

            expect($broad)->toBe($narrow);
        });

        test('accepts string literal member into broader string literal union', function () {
            expect(processNormalUnionLiteralRole('admin'))->toBe('admin')
                ->and(processNormalUnionLiteralRole('editor'))->toBe('editor')
                ->and(processNormalUnionLiteralRole('guest'))->toBe('guest')
            ;

            /** @var 'admin'|'editor'|'viewer'|'guest' $role */
            $role = 'viewer';
            expect($role)->toBe('viewer');
        });

        test('accepts integer literal member into broader integer literal union', function () {
            expect(processNormalUnionLiteralNumber(1))->toBe(1)
                ->and(processNormalUnionLiteralNumber(5))->toBe(5)
            ;

            /** @var 1|2|3|4|5 $num */
            $num = 3;
            expect($num)->toBe(3);
        });

        test('accepts refined scalar values matching union of refinements (positive-int | non-empty-string)', function () {
            expect(processNormalUnionRefinements(42))->toBe(42)
                ->and(processNormalUnionRefinements('order_code_123'))->toBe('order_code_123')
            ;

            /** @var positive-int|non-empty-string $val */
            $val = 100;
            expect($val)->toBe(100);

            $val = 'SKU-99';
            expect($val)->toBe('SKU-99');
        });

        test('strictly rejects value not present in union', function () {
            expect(fn () => processNormalUnionAnimal(new Car()))
                ->toThrow(TypeError::class)
            ;

            expect(fn () => processNormalUnionLiteralRole('superadmin'))
                ->toThrow(TypeError::class)
            ;

            expect(fn () => processNormalUnionLiteralNumber(99))
                ->toThrow(TypeError::class)
            ;

            expect(fn () => processNormalUnionRefinements(-50))
                ->toThrow(TypeError::class)
            ;

            expect(fn () => processNormalUnionRefinements(''))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Intersection Subtyping', function () {
        test('accepts quadruple-interface object into parameter expecting double-interface intersection', function () {
            $quad = new NormalQuadObject();

            $result = processNormalIntersection($quad);
            expect($result)->toBe($quad);
        });

        test('allows inline @var assignment of quadruple-interface object to double-interface intersection', function () {
            /** @var Countable&ArrayAccess $intersection */
            $intersection = new NormalQuadObject();

            expect($intersection)->toBeInstanceOf(NormalQuadObject::class);
        });

        test('accepts class and interface intersection (Dog & Countable)', function () {
            $specialDog = new NormalSpecialDog();

            $result = processNormalClassInterfaceIntersection($specialDog);
            expect($result)->toBe($specialDog);
        });

        test('strictly rejects object missing one required interface of the intersection', function () {
            expect(fn () => processNormalIntersection(new NormalSingleCountable()))
                ->toThrow(TypeError::class)
            ;
        });

        test('strictly rejects object failing class or interface part of class-interface intersection', function () {
            expect(fn () => processNormalClassInterfaceIntersection(new Dog()))
                ->toThrow(TypeError::class)
            ;

            expect(fn () => processNormalClassInterfaceIntersection(new NormalSingleCountable()))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Disjunctive Normal Form (DNF) Subtyping ((A&B) | (C&D))', function () {
        test('accepts object satisfying Branch A (Countable & ArrayAccess)', function () {
            $branchA = new NormalBranchAObject();

            expect(processNormalDnf($branchA))->toBe($branchA);
        });

        test('accepts object satisfying Branch B (Iterator & Stringable)', function () {
            $branchB = new NormalBranchBObject();

            expect(processNormalDnf($branchB))->toBe($branchB);
        });

        test('accepts quadruple-interface object satisfying both DNF branches', function () {
            $quad = new NormalQuadObject();

            expect(processNormalDnf($quad))->toBe($quad);
        });

        test('allows inline @var assignment of objects matching either DNF branch', function () {
            /** @var (Countable&ArrayAccess)|(Iterator&Stringable) $dnfVar */
            $dnfVar = new NormalBranchAObject();
            expect($dnfVar)->toBeInstanceOf(NormalBranchAObject::class);

            $dnfVar = new NormalBranchBObject();
            expect($dnfVar)->toBeInstanceOf(NormalBranchBObject::class);
        });

        test('strictly rejects cross-over object that mixes interfaces from different branches without satisfying either complete branch', function () {
            $crossOver = new NormalCrossOverObject();

            expect(fn () => processNormalDnf($crossOver))
                ->toThrow(TypeError::class)
            ;
        });

        test('strictly rejects object satisfying only a single interface of one branch', function () {
            $single = new NormalSingleCountable();

            expect(fn () => processNormalDnf($single))
                ->toThrow(TypeError::class)
            ;
        });

        test('strictly rejects completely unrelated object', function () {
            expect(fn () => processNormalDnf(new Car()))
                ->toThrow(TypeError::class);
        });
    });
});
