<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use Closure;
use TypePHP\Exception\TypeError;

interface ReadonlyAdapterInterface
{
}

final class ReadonlyAdapterA implements ReadonlyAdapterInterface
{
}

final class ReadonlyAdapterInvalid
{
}

final class PromotedReadonlyIterable
{
    /**
     * @param iterable<ReadonlyAdapterInterface> $adapters
     */
    public function __construct(public readonly iterable $adapters)
    {
    }
}

final class PromotedReadonlyClosure
{
    /**
     * @param Closure(int): int $factory
     */
    public function __construct(public readonly Closure $factory)
    {
    }
}

readonly class ReadonlyClassWithPromotedProperty
{
    /**
     * @param iterable<ReadonlyAdapterInterface> $adapters
     */
    public function __construct(public iterable $adapters)
    {
    }
}

describe('Promoted Readonly Property Contracts', function () {
    test('instantiates class with promoted readonly iterable without throwing Cannot modify readonly property error', function () {
        $instance = new PromotedReadonlyIterable([new ReadonlyAdapterA()]);

        expect($instance->adapters)->toHaveCount(1);
    });

    test('instantiates class with promoted readonly closure without throwing Cannot modify readonly property error', function () {
        $fn = static fn (int $i): int => $i + 1;
        $instance = new PromotedReadonlyClosure($fn);

        expect($instance->factory)->toBe($fn);
    });

    test('instantiates PHP 8.2 readonly class with promoted property without throwing Cannot modify readonly property error', function () {
        $instance = new ReadonlyClassWithPromotedProperty([new ReadonlyAdapterA()]);

        expect($instance->adapters)->toHaveCount(1);
    });

    test('still validates incoming constructor arguments on promoted readonly properties upon entry', function () {
        expect(fn () => new PromotedReadonlyIterable([new ReadonlyAdapterInvalid()]))
            ->toThrow(TypeError::class)
        ;
    });
});
