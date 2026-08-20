<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Doctrine;

/**
 * Simulates Doctrine DBAL's Column
 *
 * @extends AbstractNamedObject<UnqualifiedName>
 */
class Column extends AbstractNamedObject
{
    public function __construct(string $name)
    {
        parent::__construct($name);
    }
}
