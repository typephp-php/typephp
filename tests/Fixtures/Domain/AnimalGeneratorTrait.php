<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Domain;

use Generator;

/**
 * @template T of Animal|null
 */
trait AnimalGeneratorTrait
{
    /**
     * @return Generator<string, Animal>
     */
    public function yieldSameNamespaceAnimals(): Generator
    {
        yield 'first' => new Dog();
    }

    /**
     * @return Generator<string, (T is null ? Car : Animal)>
     */
    public function yieldConditionalAnimal(): Generator
    {
        if ($this->item === null) {
            yield 'car' => new Car();

            return;
        }

        yield 'dog' => new Dog();
    }
}
