<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Shapes;

class UnsealedPayloadService
{
    /**
     * Unsealed shape allowing dynamic extra keys with list<positive-int> values
     *
     * @param array{id: positive-int, ...<string, list<positive-int>>} $payload
     */
    public function processBatchOptions(array $payload): int
    {
        return count($payload);
    }

    /**
     * Unsealed shape allowing dynamic extra keys with nested array shape values
     *
     * @param array{version: non-empty-string, ...<string, array{score: positive-int, active: bool}>} $data
     */
    public function processPlayerStats(array $data): int
    {
        return count($data);
    }
}