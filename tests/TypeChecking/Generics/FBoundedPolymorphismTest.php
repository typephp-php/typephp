<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

/**
 * @template T
 */
interface FBoundComparable
{
    /**
     * @param T $other
     */
    public function compareTo(mixed $other): int;
}

/**
 * @implements FBoundComparable<FBoundText>
 */
final class FBoundText implements FBoundComparable
{
    public function __construct(public string $value)
    {
    }

    public function compareTo(mixed $other): int
    {
        return strcmp($this->value, $other->value);
    }
}

/**
 * @implements FBoundComparable<FBoundNum>
 */
final class FBoundNum implements FBoundComparable
{
    public function __construct(public int $value)
    {
    }

    public function compareTo(mixed $other): int
    {
        return $this->value <=> $other->value;
    }
}

/**
 * Not Comparable at all
 */
final class FBoundPlain
{
    public function __construct(public int $v)
    {
    }
}

/**
 * Raw min without docblock contract
 */
function fBoundRawMin(FBoundComparable $a, FBoundComparable $b): FBoundComparable
{
    return $a->compareTo($b) <= 0 ? $a : $b;
}

/**
 * Java: <T extends Comparable<T>> T fMin(T a, T b)
 * C#:   T Max<T>(T a, T b) where T : IComparable<T>
 *
 * @template T of FBoundComparable<T>
 *
 * @param T $a
 * @param T $b
 *
 * @return T
 */
function fBoundMin(FBoundComparable $a, FBoundComparable $b): FBoundComparable
{
    return $a->compareTo($b) <= 0 ? $a : $b;
}

/**
 * C#: class AGenericClass<T> where T : IComparable<T>
 *
 * @template T of FBoundComparable<T>
 */
final class FBoundSortedList
{
    /**
     * @var list<T>
     */
    public array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }

    public function count(): int
    {
        return \count($this->items);
    }
}

/**
 * Java: abstract class Base<T extends Base<T>>
 *
 * @template T of FBoundBase<T>
 */
abstract class FBoundBase
{
    /**
     * @return T
     */
    public function copy(): static
    {
        return clone $this;
    }
}

/**
 * @extends FBoundBase<FBoundDerived>
 */
final class FBoundDerived extends FBoundBase
{
}

/** Claims T = FBoundDerived, but is FBoundImpostor */
/**
 * @extends FBoundBase<FBoundDerived>
 */
final class FBoundImpostor extends FBoundBase
{
}

describe('F-Bounded Polymorphism (@template T of Interface<T>)', function () {
    describe('1. Function-Level F-Bounds (fMin)', function () {
        test('fMin accepts homogeneous matching types (Text, Text)', function () {
            $a = new FBoundText('apple');
            $b = new FBoundText('banana');

            expect(fBoundMin($a, $b))->toBe($a);
        });

        test('fMin accepts homogeneous matching types (Num, Num)', function () {
            $a = new FBoundNum(3);
            $b = new FBoundNum(7);

            expect(fBoundMin($a, $b))->toBe($a);
        });

        test('fMin rejects heterogeneous types (Text, Num) on entry at parameter $b', function () {
            expect(fn () => fBoundMin(new FBoundText('cat'), new FBoundNum(3)))
                ->toThrow(TypeError::class, 'fBoundMin(): Argument $b')
            ;
        });

        test('rawMin intercepts and throws on compareTo() callee boundary', function () {
            expect(fn () => fBoundRawMin(new FBoundText('cat'), new FBoundNum(3)))
                ->toThrow(TypeError::class, 'FBoundText::compareTo(): Argument $other')
            ;
        });
    });

    describe('2. Class-Level F-Bounds (SortedList<T of Comparable<T>>)', function () {
        test('SortedList<FBoundNum> accepts FBoundNum items', function () {
            /** @var FBoundSortedList<FBoundNum> $list */
            $list = new FBoundSortedList();
            $list->add(new FBoundNum(5));
            $list->add(new FBoundNum(2));

            expect($list->count())->toBe(2);
        });

        test('SortedList<FBoundNum> rejects FBoundText items', function () {
            /** @var FBoundSortedList<FBoundNum> $list */
            $list = new FBoundSortedList();
            $list->add(new FBoundNum(5));

            expect(fn () => $list->add(new FBoundText('x')))
                ->toThrow(TypeError::class, 'Argument $item (template T = TypePHP\Tests\TypeChecking\Generics\FBoundNum) must be of type TypePHP\Tests\TypeChecking\Generics\FBoundNum')
            ;
        });

        test('SortedList rejects non-comparable class FBoundPlain on pre-binding', function () {
            expect(function () {
                /** @var FBoundSortedList<FBoundPlain> $list */
                $list = new FBoundSortedList();
            })->toThrow(TypeError::class, 'does not satisfy upper bound');
        });
    });

    describe('3. Self-Typed Hierarchy (Base<T of Base<T>>)', function () {
        test('Derived->copy() returns Derived', function () {
            $derived = new FBoundDerived();
            expect($derived->copy())->toBeInstanceOf(FBoundDerived::class);
        });

        test('Impostor->copy() violates @return T contract', function () {
            $impostor = new FBoundImpostor();

            expect(fn () => $impostor->copy())
                ->toThrow(TypeError::class, 'Return value must be of type TypePHP\Tests\TypeChecking\Generics\FBoundDerived, TypePHP\Tests\TypeChecking\Generics\FBoundImpostor returned')
            ;
        });

        test('rejects candidate when class does not satisfy self-bound Base<C>', function () {
            expect(function () {
                /** @var FBoundBase<FBoundImpostor> $base */
                $base = new FBoundDerived();
            })->toThrow(TypeError::class);
        });
    });
});
