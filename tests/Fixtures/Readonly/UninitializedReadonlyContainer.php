<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Readonly;

class UninitializedReadonlyContainer
{
    /**
     * @var positive-int
     */
    public readonly int $id;

    /**
     * @var non-empty-string
     */
    public readonly string $name;

    // Left uninitialized on instantiation
    public function __construct()
    {
    }

    public function initialize(int $id, string $name): void
    {
        $this->id = $id;
        $this->name = $name;
    }
}