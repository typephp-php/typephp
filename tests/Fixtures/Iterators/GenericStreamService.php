<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Iterators;

use ArrayIterator;
use Generator;
use Traversable;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Dog;

class GenericStreamService
{
    /**
     * Generic stream parameter where T is inferred from $sample
     *
     * @template T
     *
     * @param iterable<T> $stream
     * @param T $sample
     *
     * @return list<T>
     */
    public function collectStream(iterable $stream, mixed $sample): array
    {
        $collected = [];
        foreach ($stream as $item) {
            $collected[] = $item;
        }

        return $collected;
    }

    /**
     * Generic animal stream with string keys
     *
     * @template T of Animal
     *
     * @param Traversable<string, T> $stream
     *
     * @return list<T>
     */
    public function collectAnimalStream(Traversable $stream): array
    {
        $collected = [];
        foreach ($stream as $key => $animal) {
            $collected[] = $animal;
        }

        return $collected;
    }

    /**
     * Generic generator method yielding template T
     *
     * @template T
     *
     * @param T $item
     * @param positive-int $count
     *
     * @return Generator<int, T>
     */
    public function streamItem(mixed $item, int $count): Generator
    {
        for ($i = 0; $i < $count; $i++) {
            yield $i => $item;
        }
    }

    /**
     * Generic interactive generator with TSend
     *
     * @template T
     *
     * @param T $initial
     *
     * @return Generator<int, T, T, void>
     */
    public function streamInteractive(mixed $initial): Generator
    {
        $current = $initial;
        for ($i = 0; $i < 3; $i++) {
            $input = yield $i => $current;
            if ($input !== null) {
                $current = $input;
            }
        }
    }
}