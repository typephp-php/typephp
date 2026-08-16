<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template K of array-key = string
 * @template V = int
 */
class MultiTemplateBag
{
    /**
     * @var array<K, V>
     */
    private array $storage = [];

    /**
     * @param K $key
     * @param V $val
     */
    public function set(mixed $key, mixed $val): void
    {
        $this->storage[$key] = $val;
    }

    /**
     * @param K $key
     *
     * @return V
     */
    public function get(mixed $key): mixed
    {
        return $this->storage[$key] ?? null;
    }

    /**
     * @return array<K, V>
     */
    public function all(): array
    {
        return $this->storage;
    }
}
