<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Readonly;

use TypePHP\Tests\TypeChecking\Boundaries\ReadonlyAdapterInterface;

/**
 * Readonly classes need PHP 8.2+, so this lives outside the test file and is only autoloaded there.
 */
readonly class ReadonlyClassWithPromotedProperty
{
    /**
     * @param iterable<ReadonlyAdapterInterface> $adapters
     */
    public function __construct(public iterable $adapters)
    {
    }
}
