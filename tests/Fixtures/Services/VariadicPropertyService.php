<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

use TypePHP\Tests\Fixtures\Domain\Animal;

class VariadicPropertyService
{
    /**
     * @var Animal[]
     */
    private array $animals;

    /**
     * @var list<string>
     */
    private array $tags;

    public function __construct(
        array $tags = [],
        Animal ...$animals
    ) {
        $this->tags = $tags;
        $this->animals = $animals;
    }

    public function getAnimals(): array
    {
        return $this->animals;
    }

    public function getTags(): array
    {
        return $this->tags;
    }
}