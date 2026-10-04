<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits\Nested\Traits;

use TypePHP\Tests\Fixtures\Traits\Nested\Contracts\NestedEntity;

trait NestedGroupTrait
{
    /**
     * @var list<NestedEntity>|null
     */
    public ?array $group = null;

    /**
     * @var NestedEntity|null
     */
    public ?object $singleEntity = null;

    /**
     * @param list<NestedEntity> $entities
     */
    public function setGroup(array $entities): void
    {
        $this->group = $entities;
    }

    public function setSingle(NestedEntity $entity): void
    {
        $this->singleEntity = $entity;
    }
}
