<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Fixture: Class with @template T of Animal = Dog
 *
 * @template T of Animal = Dog
 */
class BoundedDefaultShelter
{
    /**
     * @var T|null
     */
    public mixed $occupant = null;

    /**
     * @param T|null $occupant
     */
    public function __construct(mixed $occupant = null)
    {
        $this->occupant = $occupant;
    }

    /**
     * @param T $occupant
     */
    public function set(mixed $occupant): void
    {
        $this->occupant = $occupant;
    }

    /**
     * @return T
     */
    public function get(): mixed
    {
        return $this->occupant;
    }

    /**
     * Method with nullable template return: @return T|null
     *
     * @return T|null
     */
    public function findOccupant(): mixed
    {
        return $this->occupant;
    }

    /**
     * Method with strict non-nullable template return: @return T
     *
     * @return T
     */
    public function requireOccupant(): mixed
    {
        return $this->occupant;
    }

    /**
     * Method returning invalid object violating @return ?T
     *
     * @return ?T
     */
    public function getInvalidOccupant(): mixed
    {
        return new Car();
    }
}

/**
 * Fixture: Multi-Template with independent bounds and defaults
 *
 * @template K of array-key = string
 * @template V of object = stdClass
 */
class BoundedDefaultDictionary
{
    /**
     * @var array<K, V>
     */
    public array $storage = [];

    /**
     * @param K $key
     * @param V $val
     */
    public function put(mixed $key, mixed $val): void
    {
        $this->storage[$key] = $val;
    }

    /**
     * @param K $key
     *
     * @return V
     */
    public function get(mixed $key): mixed
    {
        return $this->storage[$key] ?? new stdClass();
    }

    /**
     * @param K $key
     *
     * @return V|null
     */
    public function find(mixed $key): ?object
    {
        return $this->storage[$key] ?? null;
    }
}

/**
 * Function-level template with bound + default
 *
 * @template T of Animal = Dog
 *
 * @param T|null $animal
 * @param mixed $fallback
 *
 * @return T
 */
function rescueOrFallbackAnimal(mixed $animal = null, mixed $fallback = null): mixed
{
    return $animal ?? $fallback ?? new Dog();
}

/**
 * Function with nullable template return: @return ?T
 *
 * @template T of Animal = Dog
 *
 * @param T|null $animal
 * @param bool $returnNull
 *
 * @return ?T
 */
function findAnimalOrNull(mixed $animal = null, bool $returnNull = false): mixed
{
    if ($returnNull) {
        return null;
    }

    return $animal ?? new Dog();
}

/**
 * Function with inferred template bounded by range: @template T of int<1, 100> = 50
 *
 * @template T of int<1, 100> = 50
 *
 * @param T $val
 *
 * @return T
 */
function resolvePercentageWithBound(mixed $val): mixed
{
    return $val;
}

/**
 * Function where T is unbound and falls back to default 50
 *
 * @template T of int<1, 100> = 50
 *
 * @param mixed $val
 *
 * @return T
 */
function resolvePercentageFallback(mixed $val): mixed
{
    return $val;
}

describe('Combined Template Bounds and Defaults (@template T of Bound = Default)', function () {

    describe('Class-Level Bound + Default (@template T of Animal = Dog)', function () {
        test('accepts pre-binding matching the default type (Dog)', function () {
            /** @var BoundedDefaultShelter<Dog> $shelter */
            $shelter = new BoundedDefaultShelter();

            $shelter->set(new Dog());
            expect($shelter->get())->toBeInstanceOf(Dog::class);
        });

        test('accepts pre-binding satisfying the bound even if different from default (Cat satisfies Animal)', function () {
            /** @var BoundedDefaultShelter<Cat> $shelter */
            $shelter = new BoundedDefaultShelter();

            $shelter->set(new Cat());
            expect($shelter->get())->toBeInstanceOf(Cat::class);
        });

        test('throws TypeError on assignment when pre-binding violates the bound (Car is not an Animal)', function () {
            expect(function () {
                /** @var BoundedDefaultShelter<Car> $shelter */
                $shelter = new BoundedDefaultShelter();
            })->toThrow(TypeError::class, 'does not satisfy upper bound');
        });

        test('unbound instance falls back to default Dog on return type contract', function () {
            $shelter = new BoundedDefaultShelter(new Dog());

            expect($shelter->get())->toBeInstanceOf(Dog::class);
        });

        test('infers template parameter from constructor argument and allows Cat on return', function () {
            $shelter = new BoundedDefaultShelter(new Cat());

            expect($shelter->get())->toBeInstanceOf(Cat::class);
        });
    });

    describe('Nullable Template Return Types (@return T|null and @return ?T)', function () {
        test('accepts null when method returns null for @return T|null with pre-bound T = Dog', function () {
            /** @var BoundedDefaultShelter<Dog> $shelter */
            $shelter = new BoundedDefaultShelter(null);

            expect($shelter->findOccupant())->toBeNull();
        });

        test('accepts valid Dog when method returns Dog for @return T|null with pre-bound T = Dog', function () {
            /** @var BoundedDefaultShelter<Dog> $shelter */
            $shelter = new BoundedDefaultShelter(new Dog());

            expect($shelter->findOccupant())->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError when method with @return ?T returns an object violating bound T = Dog', function () {
            /** @var BoundedDefaultShelter<Dog> $shelter */
            $shelter = new BoundedDefaultShelter();

            expect(fn () => $shelter->getInvalidOccupant())
                ->toThrow(TypeError::class, 'Return value')
            ;
        });

        test('throws TypeError when non-nullable @return T returns null', function () {
            /** @var BoundedDefaultShelter<Dog> $shelter */
            $shelter = new BoundedDefaultShelter(null);

            expect(fn () => $shelter->requireOccupant())
                ->toThrow(TypeError::class, 'none returned')
            ;
        });

        test('accepts null on unbound instance with @return T|null', function () {
            $shelter = new BoundedDefaultShelter(null);

            expect($shelter->findOccupant())->toBeNull();
        });

        test('accepts null for dictionary find method with @return V|null', function () {
            /** @var BoundedDefaultDictionary<string, Dog> $dict */
            $dict = new BoundedDefaultDictionary();

            expect($dict->find('non_existent_key'))->toBeNull();

            $dict->put('pet', new Dog());
            expect($dict->find('pet'))->toBeInstanceOf(Dog::class);
        });
    });

    describe('Multi-Template Bounds + Defaults (@template K of array-key = string, @template V of object = stdClass)', function () {
        test('accepts pre-binding satisfying both independent bounds', function () {
            /** @var BoundedDefaultDictionary<positive-int, Dog> $dict */
            $dict = new BoundedDefaultDictionary();

            $dict->put(10, new Dog());
            expect($dict->storage[10])->toBeInstanceOf(Dog::class);
        });

        test('throws TypeError on assignment when key type violates array-key bound', function () {
            expect(function () {
                /** @var BoundedDefaultDictionary<bool, Dog> $dict */
                $dict = new BoundedDefaultDictionary();
            })->toThrow(TypeError::class, 'does not satisfy upper bound');
        });

        test('throws TypeError on assignment when value type violates object bound', function () {
            expect(function () {
                /** @var BoundedDefaultDictionary<string, int> $dict */
                $dict = new BoundedDefaultDictionary();
            })->toThrow(TypeError::class, 'does not satisfy upper bound');
        });

        test('unbound instance falls back to default string and stdClass', function () {
            $dict = new BoundedDefaultDictionary();
            $dict->put('config_key', new stdClass());

            expect($dict->get('config_key'))->toBeInstanceOf(stdClass::class);
        });
    });

    describe('Function-Level Bound + Default (@template T of Animal = Dog)', function () {
        test('uses default Dog when called with no arguments', function () {
            $result = rescueOrFallbackAnimal();

            expect($result)->toBeInstanceOf(Dog::class);
        });

        test('infers template parameter from passed argument and overrides default (Cat overrides Dog)', function () {
            $cat = new Cat();
            $result = rescueOrFallbackAnimal($cat);

            expect($result)->toBe($cat);
        });

        test('throws TypeError when passed argument violates the template bound', function () {
            expect(fn () => rescueOrFallbackAnimal(new Car()))
                ->toThrow(TypeError::class)
            ;
        });

        test('throws TypeError when unbound function returns a value violating the default Dog type', function () {
            expect(fn () => rescueOrFallbackAnimal(null, new Cat()))
                ->toThrow(TypeError::class, 'Return value must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });

        test('accepts null for standalone function with @return ?T', function () {
            expect(findAnimalOrNull(new Dog(), returnNull: true))->toBeNull();
            expect(findAnimalOrNull(new Cat(), returnNull: true))->toBeNull();
            expect(findAnimalOrNull(null, returnNull: true))->toBeNull();
        });

        test('returns inferred Cat when non-null Cat is passed to function with @return ?T', function () {
            $cat = new Cat();
            expect(findAnimalOrNull($cat))->toBe($cat);
        });
    });

    describe('Scalar Bounds + Defaults (@template T of int<1, 100> = 50)', function () {
        test('infers template parameter from argument and enforces bound', function () {
            expect(resolvePercentageWithBound(50))->toBe(50);
            expect(resolvePercentageWithBound(75))->toBe(75);
        });

        test('throws TypeError when argument violates the int-range bound', function () {
            expect(fn () => resolvePercentageWithBound(150))
                ->toThrow(TypeError::class, '<= 100')
            ;

            expect(fn () => resolvePercentageWithBound(0))
                ->toThrow(TypeError::class, '>= 1')
            ;
        });

        test('unbound function falls back to default literal 50', function () {
            expect(resolvePercentageFallback(50))->toBe(50);

            expect(fn () => resolvePercentageFallback(75))
                ->toThrow(TypeError::class, 'Return value must be literal 50')
            ;
        });
    });
});
