<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Domain\Sub;

use Generator;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Dog;

final class ImportedAnimalProducer
{
    /**
     * @return Generator<string, Animal>
     */
    public function yieldImportedAnimals(): Generator
    {
        yield 'first' => new Dog();
    }
}
