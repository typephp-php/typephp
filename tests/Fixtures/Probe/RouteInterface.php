<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Probe;

/**
 * @template TRoute of RouteInterface
 */
interface RouteInterface
{
    /**
     * @return TRoute
     */
    public static function fromName(string $name): self;
}
