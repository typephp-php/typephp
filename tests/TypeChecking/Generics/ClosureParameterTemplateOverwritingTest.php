<?php

declare(strict_types=1);

interface ReproStatement
{
}

final class ReproPrimaryKeyStatement implements ReproStatement
{
}

final class ReproTextStatement implements ReproStatement
{
}

final class ReproVarcharStatement implements ReproStatement
{
}

/**
 * Mirrors Tempest's collection with invariant class-level template.
 *
 * @template TValue
 */
final class ReproBoxCollection
{
    /**
     * @var array<int, TValue>
     */
    public array $items;

    /**
     * @param array<int, TValue> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Calling filter with a Closure that typehints a supertype (e.g. fn(ReproStatement $s))
     * must NOT overwrite this instance's reified TValue in WeakMap.
     *
     * @param null|Closure(TValue): bool $predicate
     *
     * @return self<TValue>
     */
    public function filter(?Closure $predicate = null): self
    {
        if ($predicate === null) {
            return new self(array_values(array_filter($this->items)));
        }

        $res = [];
        foreach ($this->items as $item) {
            if ($predicate($item)) {
                $res[] = $item;
            }
        }

        return new self($res);
    }

    /**
     * Method-level template @template R must still be inferred from closure return types.
     *
     * @template R
     *
     * @param Closure(TValue): R $mapper
     *
     * @return ReproBoxCollection<R>
     */
    public function map(Closure $mapper): ReproBoxCollection
    {
        $mapped = [];
        foreach ($this->items as $item) {
            $mapped[] = $mapper($item);
        }

        return new ReproBoxCollection($mapped);
    }
}

describe('Closure Parameter Class-Template Preservation', function () {
    describe('Bug: inferTemplatesFromClosures overwriting bound class templates', function () {
        test('does not overwrite union TValue when closure accepts base interface (multi-type)', function () {
            $collection = new ReproBoxCollection([
                new ReproPrimaryKeyStatement(),
                new ReproTextStatement(),
                new ReproVarcharStatement(),
            ]);

            $filtered = $collection->filter(function (ReproStatement $statement): bool {
                return true;
            });

            expect($filtered->items)->toHaveCount(3);
        });

        test('does not overwrite single concrete TValue when closure accepts base interface', function () {
            $collection = new ReproBoxCollection([
                new ReproPrimaryKeyStatement(),
            ]);

            $filtered = $collection->filter(function (ReproStatement $statement): bool {
                return true;
            });

            expect($filtered->items)->toHaveCount(1);
        });
    });

    describe('Regression Safety: method-level template inference & callback contracts', function () {
        test('still properly infers method-level template @template R from closure return type', function () {
            $collection = new ReproBoxCollection([
                new ReproPrimaryKeyStatement(),
            ]);

            $mapped = $collection->map(function (ReproStatement $statement): string {
                return 'mapped';
            });

            expect($mapped)->toBeInstanceOf(ReproBoxCollection::class)
                ->and($mapped->items)->toBe(['mapped'])
            ;
        });

        test('still rejects incompatible argument passed to closure parameter', function () {
            $collection = new ReproBoxCollection([
                new ReproTextStatement(),
            ]);

            expect(fn () => $collection->filter(function (ReproPrimaryKeyStatement $pk): bool {
                return true;
            }))->toThrow(\TypeError::class);
        });
    });
});
