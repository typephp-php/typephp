<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Probe\Sub;

trait HasRoute
{
    public static function fromName(string $name): self
    {
        return self::from($name);
    }
}
