<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Dnf;

use ArrayAccess;
use Countable;
use Iterator;

/**
 * @phpstan-type SharedDnf (Countable&ArrayAccess)|(Iterator&Countable)
 */
class DnfService
{
    /**
     * @param (Countable&ArrayAccess)|null $payload
     */
    public function processNullableIntersection(?object $payload): ?int
    {
        return $payload !== null ? count($payload) : null;
    }

    /**
     * @param array{collection: Countable&ArrayAccess, id: positive-int} $data
     */
    public function processShapeWithIntersection(array $data): int
    {
        return count($data['collection']);
    }

    /**
     * @param SharedDnf $payload
     */
    public function processDnfAlias(object $payload): int
    {
        return count($payload);
    }
}