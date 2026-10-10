<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

class ForwardingAnimal
{
}

class ForwardingDog extends ForwardingAnimal
{
}

class ForwardingCat extends ForwardingAnimal
{
}

class ForwardingUser
{
}

/**
 * @template T of ForwardingAnimal
 */
class BaseAnimalRepositoryFixture
{
    /**
     * @param T $value
     */
    public function save(mixed $value): void
    {
    }
}

/**
 * @extends BaseAnimalRepositoryFixture<ForwardingDog>
 */
class DirectDogRepositoryFixture extends BaseAnimalRepositoryFixture
{
}

/**
 * @template U of ForwardingAnimal
 *
 * @extends BaseAnimalRepositoryFixture<U>
 */
class MiddleAnimalRepositoryFixture extends BaseAnimalRepositoryFixture
{
}

/**
 * @extends MiddleAnimalRepositoryFixture<ForwardingDog>
 */
class DogRepositoryFixture extends MiddleAnimalRepositoryFixture
{
}

/**
 * 3-Level hierarchy: Root<T> -> Level1<U> -> Level2<V> -> Concrete
 *
 * @template V of ForwardingAnimal
 *
 * @extends MiddleAnimalRepositoryFixture<V>
 */
class Level2AnimalRepositoryFixture extends MiddleAnimalRepositoryFixture
{
}

/**
 * @extends Level2AnimalRepositoryFixture<ForwardingDog>
 */
class DeepDogRepositoryFixture extends Level2AnimalRepositoryFixture
{
}

describe('Multi-Level Generic Template Forwarding Inheritance', function () {
    test('1. Direct specialization binds T to Dog and rejects Cat', function () {
        $direct = new DirectDogRepositoryFixture();

        expect(TypePHP::getGenericType($direct, 'T'))->toBe(ForwardingDog::class);

        $direct->save(new ForwardingDog());

        expect(fn () => $direct->save(new ForwardingCat()))
            ->toThrow(TypeError::class, 'must be of type ' . ForwardingDog::class)
        ;
    });

    test('2. Two-level specialization binds both U and T to Dog upon instantiation', function () {
        $repo = new DogRepositoryFixture();

        $bindings = TypePHP::getGenericTypes($repo);

        expect($bindings)->toHaveKey('U')
            ->and($bindings)->toHaveKey('T')
            ->and($bindings['U'])->toBe(ForwardingDog::class)
            ->and($bindings['T'])->toBe(ForwardingDog::class)
        ;
    });

    test('3. Fresh DogRepository rejects Cat on the very first call to save()', function () {
        $fresh = new DogRepositoryFixture();

        expect(fn () => $fresh->save(new ForwardingCat()))
            ->toThrow(TypeError::class, 'must be of type ' . ForwardingDog::class)
        ;
    });

    test('4. Fresh DogRepository rejects unrelated User mentioning Dog, not generic Animal', function () {
        $freshUser = new DogRepositoryFixture();

        expect(fn () => $freshUser->save(new ForwardingUser()))
            ->toThrow(TypeError::class, 'must be of type ' . ForwardingDog::class)
        ;
    });

    test('5. Three-level specialization (Root<T> -> Mid<U> -> Sub<V> -> Leaf<Dog>) forwards T to Dog', function () {
        $deep = new DeepDogRepositoryFixture();

        $bindings = TypePHP::getGenericTypes($deep);

        expect($bindings)->toHaveKey('V')
            ->and($bindings)->toHaveKey('U')
            ->and($bindings)->toHaveKey('T')
            ->and($bindings['T'])->toBe(ForwardingDog::class)
        ;

        expect(fn () => $deep->save(new ForwardingCat()))
            ->toThrow(TypeError::class, 'must be of type ' . ForwardingDog::class)
        ;
    });
});
