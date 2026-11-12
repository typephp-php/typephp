<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use stdClass;
use TypePHP\Exception\TypeError;

/**
 * @template E
 */
final class WideningElementStore
{
    /**
     * @var list<E>
     */
    public array $elements = [];

    /**
     * @param iterable<E> $source
     */
    public function __construct(iterable $source = [])
    {
        foreach ($source as $item) {
            $this->elements[] = $item;
        }
    }

    /**
     * @param E $element
     */
    public function add(mixed $element): void
    {
        $this->elements[] = $element;
    }
}

/**
 * @template E
 */
final class WideningImmutableSet
{
    /**
     * @var WideningElementStore<E>
     */
    private WideningElementStore $store;

    /**
     * @param iterable<E>|WideningElementStore<E> $data
     */
    public function __construct(iterable|WideningElementStore $data = [])
    {
        $this->store = $data instanceof WideningElementStore
            ? clone $data
            : new WideningElementStore($data);
    }

    /**
     * @template NE
     * @param NE $element
     * @return self<E|NE>
     */
    public function add(mixed $element): self
    {
        $store = clone $this->store;
        $store->add($element);

        return new self($store);
    }

    /**
     * @param E $element
     */
    public function contains(mixed $element): bool
    {
        return \in_array($element, $this->store->elements, true);
    }

    /**
     * @return list<E>
     */
    public function toArray(): array
    {
        return $this->store->elements;
    }
}

/**
 * @template K of string|int|object
 * @template V
 */
final class WideningKeyValueStore
{
    /**
     * @var array<string, K>
     */
    private array $keys = [];

    /**
     * @var array<string, V>
     */
    private array $values = [];

    /**
     * @param iterable<K, V> $source
     */
    public function __construct(iterable $source = [])
    {
        foreach ($source as $k => $v) {
            $this->put($k, $v);
        }
    }

    /**
     * @param K $key
     * @param V $value
     */
    public function put(string|int|object $key, mixed $value): void
    {
        $hash = \is_object($key) ? 'o:' . spl_object_id($key) : (\is_int($key) ? 'i:' . $key : 's:' . $key);
        $this->keys[$hash] = $key;
        $this->values[$hash] = $value;
    }

    /**
     * @template NK of string|int|object
     * @template NV
     * @param iterable<NK, NV> $source
     */
    public function putAllFromAssoc(iterable $source): void
    {
        foreach ($source as $k => $v) {
            $hash = \is_object($key = $k) ? 'o:' . spl_object_id($key) : (\is_int($key) ? 'i:' . $key : 's:' . $key);
            $this->keys[$hash] = $k;
            $this->values[$hash] = $v;
        }
    }

    /**
     * @param K $key
     * @return V|null
     */
    public function get(string|int|object $key): mixed
    {
        $hash = \is_object($key) ? 'o:' . spl_object_id($key) : (\is_int($key) ? 'i:' . $key : 's:' . $key);

        return $this->values[$hash] ?? null;
    }
}

/**
 * @template K of string|int|object
 * @template V
 */
final class WideningImmutableMap
{
    /**
     * @var WideningKeyValueStore<K, V>
     */
    private WideningKeyValueStore $store;

    /**
     * @param iterable<K, V>|WideningKeyValueStore<K, V> $data
     */
    public function __construct(iterable|WideningKeyValueStore $data = [])
    {
        $this->store = $data instanceof WideningKeyValueStore
            ? clone $data
            : new WideningKeyValueStore($data);
    }

    /**
     * @template NK of string|int|object
     * @template NV
     * @param NK $key
     * @param NV $value
     * @return self<K|NK, V|NV>
     */
    public function put(string|int|object $key, mixed $value): self
    {
        $entries = clone $this->store;
        $entries->put($key, $value);

        return new self($entries);
    }

    /**
     * @template NK of string|int|object
     * @template NV
     * @param iterable<NK, NV> $data
     * @return self<K|NK, V|NV>
     */
    public function putAll(iterable $data): self
    {
        $entries = clone $this->store;
        $entries->putAllFromAssoc($data);

        return new self($entries);
    }

    /**
     * @param K $key
     * @return V|null
     */
    public function get(string|int|object $key): mixed
    {
        return $this->store->get($key);
    }
}

describe('Cloned Store Template Widening in Immutable Collections & Maps', function () {
    describe('1. Immutable Set Element Type Widening via Cloned Store', function () {
        test('widens element type E to E|NE when cloning internal store in add()', function () {
            $set = new WideningImmutableSet([1, 2]);
            $obj = new stdClass();

            $widened = $set->add($obj);

            expect($widened->toArray())->toBe([1, 2, $obj])
                ->and($widened->contains(1))->toBeTrue()
                ->and($widened->contains($obj))->toBeTrue()
            ;
        });

        test('preserves narrow type E on the original immutable set after widening clone', function () {
            $set = new WideningImmutableSet([1, 2]);
            $widened = $set->add('widened_string');

            expect($widened->contains('widened_string'))->toBeTrue();

            expect(fn () => $set->contains('widened_string'))->toThrow(TypeError::class);
        });
    });

    describe('2. Immutable Map Key and Value Type Widening via Cloned Store', function () {
        test('widens key type K from int to int|string and allows subsequent get() with string key', function () {
            $map = new WideningImmutableMap([1 => 'a', 2 => 'b']);

            $widened = $map->put('foo', 'c');

            expect($widened->get(1))->toBe('a')
                ->and($widened->get(2))->toBe('b')
                ->and($widened->get('foo'))->toBe('c')
            ;
        });

        test('widens key and value types via putAll() on cloned store', function () {
            $map = new WideningImmutableMap([1 => 'a']);

            $widened = $map->putAll(['foo' => 100, 'bar' => 200]);

            expect($widened->get(1))->toBe('a')
                ->and($widened->get('foo'))->toBe(100)
                ->and($widened->get('bar'))->toBe(200)
            ;
        });

        test('preserves narrow key type K on original map after widening clone', function () {
            $map = new WideningImmutableMap([1 => 'a', 2 => 'b']);
            $widened = $map->put('foo', 'c');

            expect($widened->get('foo'))->toBe('c');

            expect(fn () => $map->get('foo'))->toThrow(TypeError::class);
        });
    });
});