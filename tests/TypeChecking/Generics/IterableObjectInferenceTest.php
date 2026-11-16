<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ArrayIterator;
use Generator;
use IteratorAggregate;
use Traversable;

/**
 * Generic object implementing IteratorAggregate with key and value templates.
 *
 * @template TKey of string|int
 * @template TValue
 *
 * @implements IteratorAggregate<TKey, TValue>
 */
class GenericKeyValueSource implements IteratorAggregate
{
    /**
     * @param array<TKey, TValue> $data
     */
    public function __construct(private array $data)
    {
    }

    /**
     * @return Traversable<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->data);
    }
}

/**
 * Generic container storing an iterable source.
 *
 * @template K of string|int
 * @template V
 */
class GenericBoxedMap
{
    /**
     * @param iterable<K, V> $source
     */
    public function __construct(public iterable $source)
    {
    }
}

/**
 * Factory function that accepts iterable<K, V> and returns GenericBoxedMap<K, V>.
 *
 * (Mirrors mapOf(iterable<K, V> $data): ImmutableMap<K, V>)
 *
 * @template K of string|int
 * @template V
 *
 * @param iterable<K, V> $source
 *
 * @return GenericBoxedMap<K, V>
 */
function createBoxedMap(iterable $source): GenericBoxedMap
{
    return new GenericBoxedMap($source);
}

/**
 * Consumer function requiring GenericBoxedMap with exact concrete types.
 *
 * @param GenericBoxedMap<string, int> $map
 *
 * @return bool
 */
function consumeBoxedMap(GenericBoxedMap $map): bool
{
    return true;
}

/**
 * Generator function producing string keys and int values.
 *
 * @return Generator<string, int>
 */
function generateKeyValues(): Generator
{
    yield 'apples' => 10;
    yield 'oranges' => 20;
}

/**
 * Generic single-template container.
 *
 * @template T
 */
class GenericBoxedList
{
    /**
     * @param iterable<T> $source
     */
    public function __construct(public iterable $source)
    {
    }
}

/**
 * Factory function accepting iterable<T> and returning GenericBoxedList<T>.
 *
 * (Mirrors listOf(iterable<E> $data): ImmutableList<E>)
 *
 * @template T
 *
 * @param iterable<T> $source
 *
 * @return GenericBoxedList<T>
 */
function createBoxedList(iterable $source): GenericBoxedList
{
    return new GenericBoxedList($source);
}

/**
 * Consumer function requiring GenericBoxedList with concrete type string.
 *
 * @param GenericBoxedList<string> $list
 *
 * @return bool
 */
function consumeBoxedList(GenericBoxedList $list): bool
{
    return true;
}

/**
 * Generator producing string values.
 *
 * @return Generator<int, string>
 */
function generateStringList(): Generator
{
    yield 'hello';
    yield 'world';
}

describe('Generic Template Inference from Traversable & Generator Objects', function () {
    test('infers key and value template arguments from a generic IteratorAggregate object passed to iterable<K, V>', function () {
        $source = new GenericKeyValueSource(['apples' => 10, 'oranges' => 20]);
        $boxed = createBoxedMap($source);

        expect(consumeBoxedMap($boxed))->toBeTrue();
    });

    test('infers key and value template arguments from a typed Generator passed to iterable<K, V>', function () {
        $gen = generateKeyValues();

        $boxed = createBoxedMap($gen);

        expect(consumeBoxedMap($boxed))->toBeTrue();
    });

    test('infers single template argument from a typed Generator passed to iterable<T>', function () {
        $gen = generateStringList();

        $boxed = createBoxedList($gen);

        expect(consumeBoxedList($boxed))->toBeTrue();
    });
});
