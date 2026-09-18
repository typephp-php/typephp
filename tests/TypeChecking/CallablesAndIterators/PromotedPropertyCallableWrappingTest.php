<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\CallablesAndIterators;

use Closure;
use TypePHP\Exception\TypeError;

interface PromotedBase
{
}

final class PromotedA implements PromotedBase
{
    public function __construct(public string $name = 'A')
    {
    }
}

final class PromotedUnrelated
{
}

final class PromotedHelper
{
    public function format(?PromotedA $a = null): PromotedBase
    {
        return new PromotedA();
    }
}

final class StoredClosureClass
{
    public Closure $factory;

    /**
     * @param Closure(?PromotedA=): PromotedBase $factory
     */
    public function __construct(Closure $factory)
    {
        $this->factory = $factory;
    }
}

final class PromotedClosureClass
{
    /**
     * @param Closure(?PromotedA=): PromotedBase $factory
     */
    public function __construct(public Closure $factory)
    {
    }
}

final class PromotedCallableClass
{
    /**
     * @param callable(?PromotedA=): PromotedBase $formatter
     */
    public function __construct(public mixed $formatter)
    {
    }
}

describe('Promoted Property Callable Contracts & Consistency', function () {
    test('enforces parameter type validation when invoking closure on promoted constructor property', function () {
        $closure = static fn (?PromotedA $a = null): PromotedBase => new PromotedA();

        $promoted = new PromotedClosureClass($closure);

        expect(($promoted->factory)(new PromotedA()))->toBeInstanceOf(PromotedBase::class);

        expect(($promoted->factory)())->toBeInstanceOf(PromotedBase::class);

        expect(fn () => ($promoted->factory)(new PromotedUnrelated()))
            ->toThrow(TypeError::class)
        ;
    });

    test('enforces return type validation when invoking closure on promoted constructor property', function () {
        $badClosure = static fn (?PromotedA $a = null): mixed => new PromotedUnrelated();

        $promoted = new PromotedClosureClass($badClosure);

        expect(fn () => ($promoted->factory)(new PromotedA()))
            ->toThrow(TypeError::class)
        ;
    });

    test('behaves consistently between standard parameter assignment and promoted property', function () {
        $closure = static fn (?PromotedA $a = null): PromotedBase => new PromotedA();

        $stored = new StoredClosureClass($closure);
        $promoted = new PromotedClosureClass($closure);

        expect(fn () => ($stored->factory)(new PromotedUnrelated()))
            ->toThrow(TypeError::class)
        ;

        expect(fn () => ($promoted->factory)(new PromotedUnrelated()))
            ->toThrow(TypeError::class)
        ;
    });

    test('enforces contracts when callable is promoted in constructor (Closure, Array Callable, Invokable)', function () {
        $helper = new PromotedHelper();

        $promotedArrayCallable = new PromotedCallableClass([$helper, 'format']);

        expect(($promotedArrayCallable->formatter)(new PromotedA()))->toBeInstanceOf(PromotedBase::class);

        expect(fn () => ($promotedArrayCallable->formatter)(new PromotedUnrelated()))
            ->toThrow(TypeError::class)
        ;

        $closure = static fn (?PromotedA $a = null): PromotedBase => new PromotedA();
        $promotedClosure = new PromotedCallableClass($closure);

        expect(($promotedClosure->formatter)(new PromotedA()))->toBeInstanceOf(PromotedBase::class);

        expect(fn () => ($promotedClosure->formatter)(new PromotedUnrelated()))
            ->toThrow(TypeError::class)
        ;
    });
});
