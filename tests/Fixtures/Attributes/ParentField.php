<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

abstract class ParentField extends GrandParentField
{
    /**
     * ParentField inserts $name at index 1
     *
     * @param non-empty-string $name
     */
    public function __construct(
        string $type,
        string $name,
        bool $api = false
    ) {
        parent::__construct($type, $api);
    }
}
