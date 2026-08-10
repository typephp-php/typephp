<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

class DeepMultiLevelField extends ParentField
{
    /**
     * Child inserts $id at index 0!
     *
     * @param positive-int $id
     */
    public function __construct(
        public int $id,
        string $type,
        string $name,
        bool $api = false
    ) {
        parent::__construct($type, $name, $api);
    }
}
