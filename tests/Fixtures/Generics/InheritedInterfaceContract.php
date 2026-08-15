<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Generics;

/**
 * @template T
 */
interface InheritedInterfaceContract
{
    /**
     * @param T $item
     */
    public function push(mixed $item): bool;

    /**
     * @return array<int, mixed>
     */
    public function all(): array;
}
