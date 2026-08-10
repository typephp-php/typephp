<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * Mock imitating Doctrine DBAL's DriverManager using type aliases
 *
 * @phpstan-type ConnectionParams array{
 *     driver: key-of<DatabaseDriverMap::DRIVER_MAP>,
 *     driverClass?: value-of<DatabaseDriverMap::DRIVER_MAP>
 * }
 * @phpstan-type LocalParams array{
 *     action: key-of<self::LOCAL_ACTIONS>
 * }
 */
class DoctrineLikeConnection
{
    /**
     * @param ConnectionParams $params
     */
    public function connect(array $params): bool
    {
        return true;
    }

    private const LOCAL_ACTIONS = ['start' => 1, 'stop' => 0];

    /**
     * @param LocalParams $params
     */
    public function localAction(array $params): bool
    {
        return true;
    }
}
