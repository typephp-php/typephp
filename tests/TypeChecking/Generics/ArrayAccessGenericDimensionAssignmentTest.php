<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use ArrayAccess;
use ArrayObject;
use Countable;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\TypePHP;

/**
 * Simulates Ramsey\Collection\Collection<T>
 *
 * @template T
 *
 * @implements ArrayAccess<array-key, T>
 */
class ReproGenericCollection implements ArrayAccess, Countable
{
    /**
     * @var array<array-key, T>
     */
    private array $items = [];

    /**
     * @param string $collectionType
     * @param array<array-key, T> $data
     */
    public function __construct(
        private readonly string $collectionType = 'mixed',
        array $data = []
    ) {
        $this->items = $data;
    }

    /**
     * @param T $element
     */
    public function add(mixed $element): bool
    {
        $this[] = $element;

        return true;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @return T|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * @param array-key|null $offset
     * @param T $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}

/**
 * Simulates Ramsey\Collection\Map\TypedMap<K, T>
 *
 * @template K of array-key
 * @template T
 *
 * @implements ArrayAccess<K, T>
 */
class ReproGenericTypedMap implements ArrayAccess, Countable
{
    /**
     * @var array<K, T>
     */
    private array $items = [];

    public function __construct(
        private readonly string $keyType = 'string',
        private readonly string $valueType = 'mixed'
    ) {
    }

    /**
     * @param K $key
     * @param T $value
     */
    public function put(mixed $key, mixed $value): void
    {
        $this[$key] = $value;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    /**
     * @param K $offset
     *
     * @return T|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * @param K|null $offset
     * @param T $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}

class ReproPropertyCollectionHolder
{
    /**
     * @var ReproGenericCollection<Dog>
     */
    public ReproGenericCollection $dogs;

    /**
     * @var ReproGenericTypedMap<non-empty-string, positive-int>
     */
    public ReproGenericTypedMap $scores;

    public function __construct()
    {
        $this->dogs = new ReproGenericCollection(Dog::class);
        $this->scores = new ReproGenericTypedMap('string', 'int');
    }
}

describe('Generic ArrayAccess Dimension Assignment ($collection[] = $val & $map[$k] = $val)', function () {
    beforeEach(function () {
        Config::set(['array_validation' => 'full']);
    });

    afterEach(function () {
        Config::reset();
    });

    describe('1. Single-Template Generic Collection<T> (Ramsey Collection Reproduction)', function () {
        test('enforces generic type T on add() and array append $dogs[] = ... in full validation mode', function () {
            /** @var ReproGenericCollection<Dog> $dogs */
            $dogs = new ReproGenericCollection(Dog::class);

            $dogs->add(new Dog());
            $dogs[] = new Dog();

            expect($dogs->count())->toBe(2)
                ->and(TypePHP::getGenericType($dogs))->toBe(Dog::class)
            ;

            expect(function () use ($dogs) {
                $dogs[] = new Car();
            })->toThrow(TypeError::class, Dog::class);
        });

        test('enforces positive-int, array shapes, and unions on Collection<T> with $collection[] = ...', function () {
            /** @var ReproGenericCollection<positive-int> $scores */
            $scores = new ReproGenericCollection('int');
            $scores->add(100);
            $scores[] = 200;

            expect($scores->count())->toBe(2);

            expect(function () use ($scores) {
                $scores[] = -50;
            })->toThrow(TypeError::class, 'positive-int');

            /** @var ReproGenericCollection<Dog|Cat> $pets */
            $pets = new ReproGenericCollection('object');
            $pets[] = new Dog();
            $pets[] = new Cat();

            expect($pets->count())->toBe(2);

            expect(function () use ($pets) {
                $pets[] = new Car();
            })->toThrow(TypeError::class);
        });
    });

    describe('2. Two-Template Generic TypedMap<K, T> (Ramsey TypedMap Reproduction)', function () {
        test('enforces both Key (K) and Value (T) generics on put() and $map[$k] = ... in full validation mode', function () {
            /** @var ReproGenericTypedMap<string, Dog> $map */
            $map = new ReproGenericTypedMap('string', Dog::class);

            $map->put('primary', new Dog());
            $map['secondary'] = new Dog();

            expect($map->count())->toBe(2)
                ->and(TypePHP::getGenericType($map, 'K'))->toBe('string')
                ->and(TypePHP::getGenericType($map, 'T'))->toBe(Dog::class)
            ;

            expect(function () use ($map) {
                $map['tertiary'] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            expect(function () use ($map) {
                $map[123] = new Dog();
            })->toThrow(TypeError::class, 'key must be of type string');
        });

        test('enforces generic key and value on native SPL ArrayObject<K, V> dimension assignments', function () {
            /** @var ArrayObject<non-empty-string, positive-int> $ao */
            $ao = new ArrayObject();

            $ao['alpha'] = 10;
            expect($ao['alpha'])->toBe(10);

            expect(function () use ($ao) {
                $ao[''] = 20;
            })->toThrow(TypeError::class, 'key must be of type non-empty-string');

            expect(function () use ($ao) {
                $ao['beta'] = -5;
            })->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('3. Class Properties Holding Generic ArrayAccess Objects ($this->dogs[] = ...)', function () {
        test('validates dimension assignments on object properties without converting property to array', function () {
            $holder = new ReproPropertyCollectionHolder();

            $holder->dogs[] = new Dog();
            expect($holder->dogs->count())->toBe(1);

            expect(function () use ($holder) {
                $holder->dogs[] = new Car();
            })->toThrow(TypeError::class, Dog::class);

            $holder->scores['first'] = 100;
            expect($holder->scores->count())->toBe(1);

            expect(function () use ($holder) {
                $holder->scores[''] = 100;
            })->toThrow(TypeError::class, 'key must be of type non-empty-string');

            expect(function () use ($holder) {
                $holder->scores['second'] = -10;
            })->toThrow(TypeError::class, 'positive-int');
        });
    });
});
