<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @phpstan-type DatabaseConfig array{
 *     connection: array{
 *         port: int<1, 65535>,
 *         driver: 'mysql'|'pgsql'
 *     }
 * }
 */
class DeepOffsetContainer
{
    /**
     * @param DatabaseConfig['connection']['port'] $port
     * @param DatabaseConfig['connection']['driver'] $driver
     */
    public function configureDatabase(int $port, string $driver): array
    {
        return [
            'port' => $port,
            'driver' => $driver,
        ];
    }
}
