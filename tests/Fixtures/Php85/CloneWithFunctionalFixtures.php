<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Php85;

use TypePHP\Tests\Fixtures\Domain\Animal;

/**
 * @template T of Animal
 */
class BoundedGenericBoxFixture
{
    /**
     * @var T
     */
    public mixed $item;

    /**
     * @param T $item
     */
    public function __construct(mixed $item)
    {
        $this->item = $item;
    }

    /**
     * @param T $newItem
     */
    public function withItem(mixed $newItem): static
    {
        return clone($this, ['item' => $newItem]);
    }
}

/**
 * @template K of array-key
 * @template V
 */
class MultiTemplateMapFixture
{
    /**
     * @var array<K, V>
     */
    public array $data;

    /**
     * @param array<K, V> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<K, V> $newData
     */
    public function withData(array $newData): static
    {
        return clone($this, ['data' => $newData]);
    }
}

final readonly class Psr7ResponseWitherFixture
{
    /**
     * @var int<100, 599>
     */
    public int $statusCode;

    /**
     * @var non-empty-string
     */
    public string $reasonPhrase;

    public function __construct(int $statusCode = 200, string $reasonPhrase = 'OK')
    {
        $this->statusCode = $statusCode;
        $this->reasonPhrase = $reasonPhrase;
    }

    public function withStatus(int $code, string $reasonPhrase = 'OK'): self
    {
        return clone($this, [
            'statusCode' => $code,
            'reasonPhrase' => $reasonPhrase,
        ]);
    }
}

class MagicCloneWithFixture
{
    /**
     * @var positive-int
     */
    public int $counter = 10;

    public bool $magicCloneExecuted = false;

    public function __clone(): void
    {
        $this->magicCloneExecuted = true;
        $this->counter = 999;
    }
}

class HookedCloneWithFixture
{
    /**
     * @var positive-int
     */
    public int $total = 100 {
        set(int $value) => $value;
    }
}

class ShapeCloneWithFixture
{
    /**
     * @var array{id: positive-int, role: 'admin'|'user'}
     */
    public array $session = ['id' => 1, 'role' => 'user'];
}
