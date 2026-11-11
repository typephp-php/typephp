<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

/**
 * Fixture mimicking noctud's Collection/Set/List filterNotNull pattern.
 *
 * @template T
 */
class ConditionalFilterContainerFixture
{
    /**
     * @var list<T>
     */
    public array $items;

    /**
     * @param list<T> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Filters out null elements and narrows T. When T is pure null, the conditional resolves to never.
     *
     * @return self<(T is null ? never : T)>
     */
    public function filterNotNull(): self
    {
        $filtered = [];
        foreach ($this->items as $item) {
            if ($item !== null) {
                $filtered[] = $item;
            }
        }

        return new self($filtered);
    }
}

/**
 * Fixture mimicking noctud's Collection/Set/List flatten pattern with pseudo-types (list, array).
 *
 * @template T
 */
class ConditionalFlattenContainerFixture
{
    /**
     * @var list<T>
     */
    public array $items;

    /**
     * @param list<T> $items
     */
    public function __construct(array $items = [])
    {
        $this->items = $items;
    }

    /**
     * Flattens elements one level if iterable.
     *
     * @return self<(T is iterable<mixed> ? mixed : T)>
     */
    public function flatten(): self
    {
        $flat = [];
        foreach ($this->items as $item) {
            if (is_iterable($item)) {
                foreach ($item as $sub) {
                    $flat[] = $sub;
                }
            } else {
                $flat[] = $item;
            }
        }

        return new self($flat);
    }
}

/**
 * Factory mimicking noctud's setOf() / listOf() wrapping of a generator with conditional return.
 *
 * @template E
 *
 * @param iterable<E> $source
 *
 * @return ConditionalFilterContainerFixture<E>
 */
function createContainerFromIterable(iterable $source): ConditionalFilterContainerFixture
{
    $items = [];
    foreach ($source as $item) {
        $items[] = $item;
    }

    return new ConditionalFilterContainerFixture($items);
}

/**
 * Generator returning a conditional type element.
 *
 * @template V
 *
 * @param list<V> $items
 *
 * @return \Generator<int, (V is iterable<mixed> ? mixed : V)>
 */
function generateFlattenedStream(array $items): \Generator
{
    foreach ($items as $item) {
        if (is_iterable($item)) {
            foreach ($item as $sub) {
                yield $sub;
            }
        } else {
            yield $item;
        }
    }
}

describe('Conditional Return Types on Generic Collections (filterNotNull & flatten)', function () {
    test('filterNotNull() on all-null collection satisfies Container<(T is null ? never : T)> without invariance mismatch', function () {
        $container = new ConditionalFilterContainerFixture([null, null]);
        $nonNull = $container->filterNotNull();

        expect($nonNull->items)->toBe([]);
    });

    test('flatten() on collection of lists resolves (T is iterable<mixed>) when T is pseudo-type list', function () {
        $container = new ConditionalFlattenContainerFixture([[1, 2], [3]]);
        $flattened = $container->flatten();

        expect($flattened->items)->toBe([1, 2, 3]);
    });

    test('factory does not leak raw unevaluated conditional type from generator into container template', function () {
        $gen = generateFlattenedStream([[1, 2], [3, 4]]);
        $container = createContainerFromIterable($gen);

        expect($container->items)->toBe([1, 2, 3, 4]);
    });
});
