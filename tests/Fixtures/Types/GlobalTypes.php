<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @phpstan-type SharedShape array{id: positive-int, name: non-empty-string}
 * @phpstan-type SharedTupleShape array{list<positive-int>, non-empty-string}
 */
class GlobalTypes
{
}
