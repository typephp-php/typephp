<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

class ChildWithoutTemplateDocblock implements InheritedInterfaceContract
{
    /**
     * @var array<int, mixed>
     */
    private array $items = [];

    public function push(mixed $item): bool
    {
        $this->items[] = $item;

        return true;
    }

    public function all(): array
    {
        return $this->items;
    }
}
