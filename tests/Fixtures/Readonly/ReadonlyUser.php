<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Readonly;

class ReadonlyUser
{
    /**
     * @var positive-int
     */
    public readonly int $id;

    /**
     * @var non-empty-string
     */
    public readonly string $username;

    public function __construct(int $id, string $username)
    {
        $this->id = $id;
        $this->username = $username;
    }
}
