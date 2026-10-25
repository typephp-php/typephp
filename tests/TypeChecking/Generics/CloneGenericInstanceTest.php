<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericBox;
use TypePHP\Tests\Fixtures\Generics\GenericBoxWithMagicClone;

/**
 * @template T
 */
class SmartBoxWithInvalidCloneFixture
{
    /**
     * @var T|null
     */
    public mixed $item = null;

    /**
     * @param T $item
     */
    public function set(mixed $item): void
    {
        $this->item = $item;
    }

    public function __clone(): void
    {
        $this->set(new Car());
    }
}

describe('Clone Keyword & Generic Prebinding Preservation', function () {
    test('enforces generic template contract inside __clone() method execution (TDD Reproduction)', function () {
        /** @var SmartBoxWithInvalidCloneFixture<Dog> $box */
        $box = new SmartBoxWithInvalidCloneFixture();

        expect(fn () => clone $box)
            ->toThrow(TypeError::class, 'must be of type ' . Dog::class)
        ;
    });

    test('preserves generic template bindings when a standard object is cloned', function () {
        /** @var GenericBox<Dog> $dogBox */
        $dogBox = new GenericBox();
        $dogBox->set(new Dog());

        $clonedBox = clone $dogBox;

        $clonedBox->set(new Dog());
        expect($clonedBox->item)->toBeInstanceOf(Dog::class);

        expect(fn () => $clonedBox->set(new Car()))
            ->toThrow(TypeError::class, 'template T = TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('preserves generic template bindings when an object with __clone() is cloned', function () {
        /** @var GenericBoxWithMagicClone<Dog> $magicBox */
        $magicBox = new GenericBoxWithMagicClone();
        $clonedMagicBox = clone $magicBox;

        $clonedMagicBox->set(new Dog());
        expect($clonedMagicBox->item)->toBeInstanceOf(Dog::class);

        expect(fn () => $clonedMagicBox->set(new Car()))
            ->toThrow(TypeError::class, 'template T = TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('isolates generic template bindings and object state between original and cloned instances in WeakMap', function () {
        /** @var GenericBox<Dog> $box1 */
        $box1 = new GenericBox();
        $dog1 = new Dog();
        $box1->set($dog1);

        $box2 = clone $box1;

        $dog2 = new Dog();
        $box2->set($dog2);

        expect($box2->item)->toBe($dog2)
            ->and($box1->item)->toBe($dog1)
        ;

        expect(fn () => $box1->set(new Car()))
            ->toThrow(TypeError::class, 'template T = TypePHP\Tests\Fixtures\Domain\Dog')
        ;

        expect(fn () => $box2->set(new Car()))
            ->toThrow(TypeError::class, 'template T = TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('enforces invariant generic type matching when assigning a cloned instance to an incompatible variable annotation', function () {
        /** @var GenericBox<Dog> $box1 */
        $box1 = new GenericBox();
        $box1->set(new Dog());

        expect(function () use ($box1) {
            /** @var GenericBox<Cat> $box2 */
            $box2 = clone $box1;
        })->toThrow(TypeError::class, 'GenericBox<invariant TypePHP\Tests\Fixtures\Domain\Cat>');
    });
});
