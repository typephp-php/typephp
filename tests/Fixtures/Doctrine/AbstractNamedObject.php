<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Doctrine;

/**
 * Simulates Doctrine DBAL's AbstractNamedObject
 *
 * @template N of Name
 */
abstract class AbstractNamedObject
{
    /**
     * The property is an object of type Name (template N)
     *
     * @var N
     */
    protected Name $name;

    /**
     * The constructor parameter is a native scalar string
     */
    public function __construct(string $name)
    {
    }
}
