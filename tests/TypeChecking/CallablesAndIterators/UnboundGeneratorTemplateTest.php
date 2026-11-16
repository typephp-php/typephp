<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\CallablesAndIterators;

use Closure;
use Generator;

/**
 * Fixture: A pipeline operation whose method declares a template R on the method itself,
 * returning Generator<int, R>.
 */
class TransformPipelineFixture
{
    /**
     * @template R
     *
     * @param list<int> $items
     * @param Closure(int): R $transform
     *
     * @return Generator<int, R>
     */
    public function map(array $items, Closure $transform): Generator
    {
        foreach ($items as $k => $v) {
            yield $k => $transform($v);
        }
    }

    /**
     * @template U
     *
     * @param list<int> $left
     * @param iterable<U> $right
     *
     * @return Generator<int, array{int, U}>
     */
    public function zip(array $left, iterable $right): Generator
    {
        $i = 0;
        foreach ($right as $val) {
            if (! isset($left[$i])) {
                break;
            }
            yield $i => [$left[$i], $val];
            $i++;
        }
    }
}

/**
 * Fixture: A generic container class accepting iterable<E> in its constructor.
 *
 * @template E
 */
class GenericCollectionFixture
{
    /**
     * @var list<E>
     */
    public array $items = [];

    /**
     * @param iterable<E> $source
     */
    public function __construct(iterable $source)
    {
        foreach ($source as $item) {
            $this->items[] = $item;
        }
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->items;
    }
}

/**
 * Standalone consumer function taking iterable<T> and returning list<T>.
 *
 * @template T
 *
 * @param iterable<T> $source
 *
 * @return list<T>
 */
function collectStream(iterable $source): array
{
    $result = [];
    foreach ($source as $item) {
        $result[] = $item;
    }

    return $result;
}

/**
 * Standalone function with a concrete return contract expecting string elements.
 *
 * @return GenericCollectionFixture<string>
 */
function produceStringCollection(): GenericCollectionFixture
{
    $pipeline = new TransformPipelineFixture();
    $gen = $pipeline->map([1, 2, 3], static fn (int $x): string => "val_{$x}");

    return new GenericCollectionFixture($gen);
}

/**
 * Standalone function expecting a zipped collection of integer and string pairs.
 *
 * @return GenericCollectionFixture<array{int, string}>
 */
function produceZippedCollection(): GenericCollectionFixture
{
    $pipeline = new TransformPipelineFixture();
    $gen = $pipeline->zip([1, 2], ['a', 'b']);

    return new GenericCollectionFixture($gen);
}

describe('Unbound Method Template Leaks into Generators (NV, R, U)', function () {
    test('does not leak method template R into consumer function collectStream(iterable<T>)', function () {
        $pipeline = new TransformPipelineFixture();
        $gen = $pipeline->map([1, 2, 3], static fn (int $x): string => "item_{$x}");

        $result = collectStream($gen);

        expect($result)->toBe(['item_1', 'item_2', 'item_3']);
    });

    test('does not leak method template R into GenericCollectionFixture<E> via constructor', function () {
        $pipeline = new TransformPipelineFixture();
        $gen = $pipeline->map([10, 20], static fn (int $x): string => "number_{$x}");

        $collection = new GenericCollectionFixture($gen);

        expect($collection->toArray())->toBe(['number_10', 'number_20']);
    });

    test('satisfies concrete return contract GenericCollectionFixture<string> without invariance mismatch', function () {
        $collection = produceStringCollection();

        expect($collection)->toBeInstanceOf(GenericCollectionFixture::class)
            ->and($collection->toArray())->toBe(['val_1', 'val_2', 'val_3'])
        ;
    });

    test('does not leak method template U from zip operation into tuples', function () {
        $pipeline = new TransformPipelineFixture();
        $gen = $pipeline->zip([1, 2], ['first', 'second']);

        $collection = new GenericCollectionFixture($gen);

        expect($collection->toArray())->toBe([
            [1, 'first'],
            [2, 'second'],
        ]);
    });

    test('satisfies concrete return contract GenericCollectionFixture<array{int, string}> without U template leak', function () {
        $collection = produceZippedCollection();

        expect($collection)->toBeInstanceOf(GenericCollectionFixture::class)
            ->and($collection->toArray())->toBe([
                [1, 'a'],
                [2, 'b'],
            ])
        ;
    });
});
