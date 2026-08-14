<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Services;

/**
 * @template K of array-key
 * @template V
 */
abstract class BaseGenericMap
{
    /**
     * @param array<K, V> $entries
     */
    public function __construct(public array $entries = [])
    {
    }

    /**
     * Static factory creating an instance with multiple bound templates
     *
     * @template TKey of array-key
     * @template TVal
     *
     * @param TKey $key
     * @param TVal $val
     *
     * @return static<TKey, TVal>
     */
    public static function fromEntry(mixed $key, mixed $val): static
    {
        return new static([$key => $val]);
    }

    /**
     * Factory returning Array Shape holding generic static instance
     *
     * @template TKey of array-key
     * @template TVal
     *
     * @param TKey $key
     * @param TVal $val
     *
     * @return array{instance: static<TKey, TVal>, count: positive-int}
     */
    public static function toShape(mixed $key, mixed $val): array
    {
        return [
            'instance' => new static([$key => $val]),
            'count' => 1,
        ];
    }

    /**
     * Factory returning bad shape with invalid count
     *
     * @template TKey of array-key
     * @template TVal
     *
     * @param TKey $key
     * @param TVal $val
     *
     * @return array{instance: static<TKey, TVal>, count: positive-int}
     */
    public static function toBadShape(mixed $key, mixed $val): array
    {
        return [
            'instance' => new static([$key => $val]),
            'count' => -1, // Violates positive-int in shape
        ];
    }
}
