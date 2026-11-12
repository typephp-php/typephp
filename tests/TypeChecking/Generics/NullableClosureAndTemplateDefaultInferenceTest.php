<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Closure;
use TypePHP\Exception\TypeError;

/**
 * @template K of array-key
 * @template V
 */
final class ClosureInferredMap
{
    /**
     * @param array<K, V> $entries
     */
    public function __construct(private array $entries = [])
    {
    }

    /**
     * @param K $key
     * @return V
     */
    public function get(int|string $key): mixed
    {
        return $this->entries[$key];
    }

    /**
     * @template NV
     * @param Closure(V, K): (NV|null) $transform
     * @return self<K, NV>
     */
    public function mapValuesNotNull(Closure $transform): self
    {
        $out = [];
        foreach ($this->entries as $k => $v) {
            $mapped = $transform($v, $k);
            if ($mapped !== null) {
                $out[$k] = $mapped;
            }
        }

        return new self($out);
    }
}

/**
 * @template E
 */
final class ClosureInferredList
{
    /**
     * @param list<E> $items
     */
    public function __construct(private array $items = [])
    {
    }

    /**
     * @template K of array-key
     * @template V = E
     * @param Closure(E, int): K $keySelector
     * @param ?Closure(E, int): V $valueTransform
     * @return ClosureInferredMap<K, V>
     */
    public function toMap(Closure $keySelector, ?Closure $valueTransform = null): ClosureInferredMap
    {
        $out = [];
        foreach ($this->items as $i => $item) {
            $k = $keySelector($item, $i);
            $out[$k] = $valueTransform === null ? $item : $valueTransform($item, $i);
        }

        return new ClosureInferredMap($out);
    }

    /**
     * @template V = E
     * @param ?Closure(E, int): V $valueTransform
     * @return ClosureInferredMap<int, V>
     */
    public function toIndexedMap(?Closure $valueTransform = null): ClosureInferredMap
    {
        if ($valueTransform === null) {
            return new ClosureInferredMap($this->items);
        }

        $out = [];
        foreach ($this->items as $i => $item) {
            $out[$i] = $valueTransform($item, $i);
        }

        return new ClosureInferredMap($out);
    }
}

/**
 * Creates a ClosureInferredMap lazily via a factory closure (simulating lazy proxy without pre-bound V).
 *
 * @template K of array-key
 * @template V
 * @param Closure(): array<K, V> $producer
 * @return ClosureInferredMap<K, V>
 */
function lazyClosureInferredMapOf(Closure $producer): ClosureInferredMap
{
    return new ClosureInferredMap($producer());
}

describe('Nullable Closure Parameters & Template Default Inference', function () {
    describe('1. Nullable Closure Parameter (?Closure(E, int): V) with Template Default (@template V = E)', function () {
        test('uses template default V = E when optional ?Closure is omitted (null)', function () {
            $list = new ClosureInferredList(['apple', 'banana']);

            $map = $list->toIndexedMap();

            expect($map->get(0))->toBe('apple')
                ->and($map->get(1))->toBe('banana')
            ;
        });

        test('infers V = int from ?Closure with explicit return typehint overriding default V = E', function () {
            $list = new ClosureInferredList(['apple', 'banana']);

            $map = $list->toIndexedMap(fn (string $v): int => \strlen($v));

            expect($map->get(0))->toBe(5)
                ->and($map->get(1))->toBe(6)
            ;
        });

        test('does not falsely lock V to default E when untyped ?Closure is provided to toIndexedMap()', function () {
            $list = new ClosureInferredList(['apple', 'banana']);

            $map = $list->toIndexedMap(fn ($v) => \strlen($v));

            expect($map->get(0))->toBe(5)
                ->and($map->get(1))->toBe(6)
            ;
        });

        test('does not falsely lock V to default E when untyped ?Closure is provided to toMap()', function () {
            $list = new ClosureInferredList(['apple', 'banana', 'cherry']);

            $map = $list->toMap(fn ($v) => $v[0], fn ($v) => \strlen($v));

            expect($map->get('a'))->toBe(5)
                ->and($map->get('b'))->toBe(6)
                ->and($map->get('c'))->toBe(6)
            ;
        });
    });

    describe('2. Closure Parameter Nullability Inference (fn (?int $v) => ...)', function () {
        test('preserves nullable ?int parameter typehint when inferring template V from closure', function () {
            $map = lazyClosureInferredMapOf(fn () => [1 => 10, 2 => null, 3 => 30]);

            $mapped = $map->mapValuesNotNull(fn (?int $v) => $v === null ? null : $v * 2);

            expect($mapped->get(1))->toBe(20)
                ->and($mapped->get(3))->toBe(60)
            ;
        });

        test('rejects invalid non-int value when closure parameter is typed ?int', function () {
            $map = lazyClosureInferredMapOf(fn () => [1 => 10, 2 => 'invalid_string']);

            expect(fn () => $map->mapValuesNotNull(fn (?int $v) => $v === null ? null : $v * 2))
                ->toThrow(TypeError::class)
            ;
        });
    });
});