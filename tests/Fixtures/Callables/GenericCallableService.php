<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Callables;

use TypePHP\Tests\Fixtures\Domain\Animal;

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

    /**
     * Higher-order generic array transformer
     *
     * @template K of array-key
     * @template V
     * @template V2
     *
     * @param callable(V): V2 $callback
     * @param array<K, V> $array
     *
     * @return array<K, V2>
     */
    public function mapArray(callable $callback, array $array): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $result[$key] = $callback($value);
        }

        return $result;
    }

    /**
     * Higher-order sequential list transformer
     *
     * @template T
     * @template R
     *
     * @param callable(T): R $callback
     * @param list<T> $items
     *
     * @return list<R>
     */
    public function mapList(callable $callback, array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            $result[] = $callback($item);
        }

        return $result;
    }

    /**
     * Higher-order transformer passing both Key and Value to callback
     *
     * @template K of array-key
     * @template V
     * @template V2
     *
     * @param callable(K, V): V2 $callback
     * @param array<K, V> $map
     *
     * @return array<K, V2>
     */
    public function mapWithKey(callable $callback, array $map): array
    {
        $result = [];
        foreach ($map as $key => $value) {
            $result[$key] = $callback($key, $value);
        }

        return $result;
    }
}