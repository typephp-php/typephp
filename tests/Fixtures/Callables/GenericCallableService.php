<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Callables;

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Dog;

class GenericCallableService
{
    /**
     * Generic callback where T is inferred from $input
     *
     * @template T
     *
     * @param callable(T): T $transformer
     * @param T $input
     *
     * @return T
     */
    public function transform(callable $transformer, mixed $input): mixed
    {
        return $transformer($input);
    }

    /**
     * Generic callback accepting Animal bounds
     *
     * @template T of Animal
     *
     * @param callable(T): non-empty-string $formatter
     * @param T $animal
     *
     * @return non-empty-string
     */
    public function formatAnimal(callable $formatter, Animal $animal): string
    {
        return $formatter($animal);
    }
}