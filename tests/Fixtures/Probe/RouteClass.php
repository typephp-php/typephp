<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Probe;

use TypePHP\Tests\Fixtures\Probe\Sub\HasRoute;

/**
 * @implements RouteInterface<RouteClass>
 */
class RouteClass implements RouteInterface
{
    use HasRoute;

    public static function from(string $name): self
    {
        return new self();
    }
}
