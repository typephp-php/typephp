<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Dog;

class GenericConditionalFactory
{
    /**
     * @template T of Animal
     *
     * @param class-string<T> $class
     * @param mixed $payload
     *
     * @return (T is Dog ? list<positive-int> : list<non-empty-string>)
     */
    public function createPayload(string $class, mixed $payload): array
    {
        return $payload;
    }

    /**
     * @template T
     *
     * @param T $input
     * @param mixed $result
     *
     * @return (T is not Dog ? non-empty-string : positive-int)
     */
    public function processNegated(mixed $input, mixed $result): mixed
    {
        return $result;
    }
}
