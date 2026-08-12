<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @property positive-int $magicScore
 * @property-write non-empty-string $magicName
 * @property-read list<int> $magicTags
 */
class MagicPropertyFixture
{
    public array $data = [];

    public function __set(string $name, mixed $value): void
    {
        $this->data[$name] = $value;
    }

    public function __get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }
}
