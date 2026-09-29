<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

/**
 * @param Closure(int): string $fn
 */
function testRequiresClosureParam(mixed $fn): void
{
    $fn(42);
}

/**
 * @param callable(int): string $fn
 */
function testRequiresCallableParam(mixed $fn): void
{
    $fn(42);
}

/**
 * @param (static Closure)(int): int $fn
 */
function testParenStaticClosureParam(mixed $fn): void
{
    $fn(5);
}

/**
 * @param static Closure(int): int $fn
 */
function testBareStaticClosureParam(mixed $fn): void
{
    $fn(5);
}

class StaticClosureHost
{
    public function getBound(): Closure
    {
        return fn (int $x): int => $x + 1;
    }
}

describe('Callable & Closure Edge Cases', function () {
    describe('Bug 1: Non-Callable Values and Arrays against Closure/Callable constraints', function () {
        test('rejects non-callable array on parameter expecting Closure', function () {
            expect(fn () => testRequiresClosureParam([new stdClass(), 'nonexistentMethod']))
                ->toThrow(TypeError::class, 'must be of type Closure, list (2 items) given')
            ;
        });

        test('rejects non-callable array on parameter expecting callable', function () {
            expect(fn () => testRequiresCallableParam([new stdClass(), 'nonexistentMethod']))
                ->toThrow(TypeError::class, 'must be of type callable, list (2 items) given')
            ;
        });

        test('rejects non-callable scalar on parameter expecting callable', function () {
            expect(fn () => testRequiresCallableParam(12345))
                ->toThrow(TypeError::class, 'must be of type callable, int (12345) given')
            ;
        });
    });

    describe('Bug 2: (static Closure) syntax variants', function () {
        test('rejects bound closure when declared as (static Closure)', function () {
            $host = new StaticClosureHost();

            expect(fn () => testParenStaticClosureParam($host->getBound()))
                ->toThrow(TypeError::class, 'must be a static Closure (not bound to $this)')
            ;
        });

        test('rejects bound closure when declared as static Closure without parentheses', function () {
            $host = new StaticClosureHost();

            expect(fn () => testBareStaticClosureParam($host->getBound()))
                ->toThrow(TypeError::class, 'must be a static Closure (not bound to $this)')
            ;
        });

        test('accepts genuinely static closure on (static Closure)', function () {
            expect(testParenStaticClosureParam(static fn (int $x): int => $x * 2))->toBeNull();
            expect(testBareStaticClosureParam(static fn (int $x): int => $x * 2))->toBeNull();
        });
    });
});
