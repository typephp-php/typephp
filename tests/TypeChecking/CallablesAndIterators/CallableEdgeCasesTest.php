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

/**
 * @phpstan-type LocalHandlerFn callable(int): string
 */
class TypeAliasHolderFixture
{
}

/**
 * Valid: Explicitly imports type alias per PHPStan specification
 *
 * @phpstan-import-type LocalHandlerFn from TypeAliasHolderFixture
 */
function testFunctionWithImportedAlias(int $x): string
{
    /** @var LocalHandlerFn $fn */
    $fn = fn (int $n): string => "aliased: {$n}";

    return $fn($x);
}

function testFunctionWithoutImportedAlias(): void
{
    /** @var LocalHandlerFn $fn */
    $fn = fn (int $n): string => "aliased: {$n}";
}

function testFunctionWithNonExistentClass(): void
{
    /** @var HandlerFnClassNotExist $fn */
    $fn = fn (int $n): string => "aliased: {$n}";
}

describe('Callable & Closure Edge Cases', function () {
    describe('Inline Variable Callable Validations', function () {
        test('rejects non-callable string assigned to @var callable variable', function () {
            expect(function () {
                /** @var callable(int): string $fn */
                $fn = 'not a callable at all';
            })->toThrow(TypeError::class, 'must be of type callable');
        });

        test('rejects integer assigned to @var callable variable', function () {
            expect(function () {
                /** @var callable(int): string $fn */
                $fn = 42;
            })->toThrow(TypeError::class, 'must be of type callable');
        });

        test('rejects non-closure string assigned to @var Closure variable', function () {
            expect(function () {
                /** @var Closure(int): string $fn */
                $fn = 'strlen';
            })->toThrow(TypeError::class, 'must be of type Closure');
        });

        test('wraps callable nested inside array shape and enforces return type on invocation', function () {
            /** @var array{handler: callable(int): string} $config */
            $config = ['handler' => fn (int $x) => 42];

            expect(fn () => $config['handler'](42))
                ->toThrow(TypeError::class, 'Callback return value must be of type string, int (42) given')
            ;
        });

        test('resolves and wraps callable type alias when explicitly imported via @phpstan-import-type', function () {
            $result = testFunctionWithImportedAlias(42);

            expect($result)->toBe('aliased: 42');
        });

        test('strictly rejects closure when type alias was NOT imported with @phpstan-import-type', function () {
            expect(fn () => testFunctionWithoutImportedAlias())
                ->toThrow(TypeError::class, 'must be of type LocalHandlerFn, Closure given')
            ;
        });

        test('strictly rejects closure when variable is typed with a non-existent class', function () {
            expect(fn () => testFunctionWithNonExistentClass())
                ->toThrow(TypeError::class, 'must be of type HandlerFnClassNotExist, Closure given')
            ;
        });
    });

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
