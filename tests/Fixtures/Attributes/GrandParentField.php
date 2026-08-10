<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

abstract class GrandParentField
{
    /**
     * @param non-empty-string $type
     * @param bool $api
     */
    public function __construct(
        string $type,
        bool $api = false
    ) {
    }
}
