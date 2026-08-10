<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

class BaseField
{
    /**
     * Parent constructor has $api at index 2
     *
     * @param string $type
     * @param string|null $storageName
     * @param bool|array{admin-api: bool, store-api: bool} $api
     */
    public function __construct(
        string $type,
        ?string $storageName = null,
        bool|array $api = false
    ) {
    }
}
