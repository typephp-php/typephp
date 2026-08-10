<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_PROPERTY)]
class OneToManyRelation extends BaseField
{
    /**
     * Child constructor has $onDelete at index 2
     */
    public function __construct(
        public string $entity,
        public string $ref,
        public OnDeleteOption $onDelete = OnDeleteOption::NO_ACTION,
        public bool|array $api = false
    ) {
        parent::__construct('one-to-many', null, $api);
    }
}
