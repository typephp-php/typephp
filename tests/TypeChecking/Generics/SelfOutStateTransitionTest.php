<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

/**
 * 1. State Machine: Transitions from 'unauthenticated' to 'authenticated'
 *
 * @template TState of 'unauthenticated'|'authenticated'
 */
class FixtureSession
{
    /**
     * @self-out self<'authenticated'>
     */
    public function login(): void
    {
        // Mutates session in place
    }

    /**
     * @self-out self<'unauthenticated'>
     */
    public function logout(): void
    {
        // Mutates session back to unauthenticated
    }
}

/**
 * Helper function demanding an authenticated session
 *
 * @param FixtureSession<'authenticated'> $session
 */
function tddRequireAuth(FixtureSession $session): bool
{
    return true;
}

/**
 * 2. Tooling Priority Test: @phpstan-self-out overrides @self-out
 *
 * @template TState of string
 */
class FixturePrioritySession
{
    /**
     * @self-out self<'standard_state'>
     *
     * @psalm-self-out self<'psalm_state'>
     *
     * @phpstan-self-out self<'phpstan_state'>
     */
    public function transition(): void
    {
    }
}

/**
 * 3. Mutable Collection Accumulating Generic Types
 *
 * @template T
 */
class FixtureMutableCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [];

    /**
     * Here, we accept an item of type T (so it guards against invalid additions!)
     *
     * @param T $item
     */
    public function addStrict(mixed $item): void
    {
        $this->items[] = $item;
    }

    /**
     * Here, we accumulate a new type TItem into T.
     *
     * @template TItem
     *
     * @param TItem $item
     *
     * @phpstan-self-out self<T|TItem>
     */
    public function addDynamic(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * 4. Fluent Builder chaining with @this-out
 *
 * @template TStep of 'init'|'configured'|'ready'
 */
class FixtureFluentBuilder
{
    /**
     * @this-out self<'configured'>
     */
    public function configure(): self
    {
        return $this;
    }

    /**
     * @this-out self<'ready'>
     */
    public function prepare(): self
    {
        return $this;
    }
}

/**
 * @param FixtureFluentBuilder<'ready'> $builder
 */
function tddRequireReadyBuilder(FixtureFluentBuilder $builder): bool
{
    return true;
}

describe('@self-out, @phpstan-self-out & @psalm-self-out Transitions (TDD Baseline)', function () {
    describe('1. State Machine Transition (@self-out)', function () {
        test('re-types generic template on $this in WeakMap after method execution', function () {
            /** @var FixtureSession<'unauthenticated'> $session */
            $session = new FixtureSession();

            expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

            expect(fn () => tddRequireAuth($session))
                ->toThrow(TypeError::class, "FixtureSession<invariant 'authenticated'>")
            ;
            $session->login();

            expect(TypePHP::getGenericType($session))->toBe("'authenticated'");

            expect(tddRequireAuth($session))->toBeTrue();
        });

        test('transitions state back upon calling logout()', function () {
            /** @var FixtureSession<'unauthenticated'> $session */
            $session = new FixtureSession();

            $session->login();
            expect(tddRequireAuth($session))->toBeTrue();

            $session->logout();
            expect(TypePHP::getGenericType($session))->toBe("'unauthenticated'");

            expect(fn () => tddRequireAuth($session))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('2. Tooling Priority Hierarchy (@phpstan-self-out > @psalm-self-out > @self-out)', function () {
        test('prioritizes @phpstan-self-out over psalm and standard tags', function () {
            /** @var FixturePrioritySession<'init'> $session */
            $session = new FixturePrioritySession();

            $session->transition();

            expect(TypePHP::getGenericType($session))->toBe("'phpstan_state'");
        });
    });

    describe('3. Dynamic Template Accumulation (self<T|TItem>)', function () {
        test('accumulates union types in WeakMap when method adds new type to generic container', function () {
            /** @var FixtureMutableCollection<Dog> $col */
            $col = new FixtureMutableCollection();
            expect(TypePHP::getGenericType($col))->toBe(Dog::class);

            $col->addDynamic(new Cat());

            expect(TypePHP::getGenericType($col))->toBe('(' . Dog::class . ' | ' . Cat::class . ')');

            $col->addStrict(new Dog());
            expect(\count($col->items))->toBe(2);

            expect(fn () => $col->addStrict(new Car()))
                ->toThrow(TypeError::class, 'must be of type (' . Dog::class . ' | ' . Cat::class . ')')
            ;
        });
    });

    describe('4. Fluent Builder Chaining with @this-out', function () {
        test('updates generic state across method chains returning $this', function () {
            /** @var FixtureFluentBuilder<'init'> $builder */
            $builder = new FixtureFluentBuilder();

            expect(fn () => tddRequireReadyBuilder($builder))
                ->toThrow(TypeError::class)
            ;

            $builder->configure()->prepare();

            expect(TypePHP::getGenericType($builder))->toBe("'ready'");
            expect(tddRequireReadyBuilder($builder))->toBeTrue();
        });
    });
});
