<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Domain;

class User
{
    public function __construct(
        public string $name = 'Alice',
        public int $id = 1
    ) {
    }
}