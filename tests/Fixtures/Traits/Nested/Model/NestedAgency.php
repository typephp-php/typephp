<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Traits\Nested\Model;

use TypePHP\Tests\Fixtures\Traits\Nested\Contracts\NestedEntity;
use TypePHP\Tests\Fixtures\Traits\Nested\Traits\NestedOuterTrait;

final class NestedAgency implements NestedEntity
{
    use NestedOuterTrait;
}
