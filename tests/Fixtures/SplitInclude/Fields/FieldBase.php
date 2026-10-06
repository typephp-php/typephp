<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\SplitInclude\Fields;

/**
 * @template TEntity
 */
abstract class FieldBase
{
    /**
     * @var TEntity
     */
    public mixed $entity = null;

    /**
     * @var int<0, max>
     */
    public int $count = 0;
}
