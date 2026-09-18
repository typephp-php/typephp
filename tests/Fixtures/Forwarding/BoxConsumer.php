<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Forwarding;

final class BoxConsumer
{
    /**
     * @template T of ItemBase
     * @template U of ItemBase
     *
     * @param BoxInterface<T, U> $box
     */
    public function __construct(public readonly BoxInterface $box)
    {
    }
}