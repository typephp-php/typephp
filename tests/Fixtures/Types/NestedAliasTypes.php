<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Central shared type definitions for nested alias testing
 *
 * @phpstan-type SharedId positive-int
 * @phpstan-type SharedStatus 'active'|'pending'
 * @phpstan-type SharedRecordShape array{id: SharedId, status: SharedStatus}
 */
class NestedAliasTypes
{
}
