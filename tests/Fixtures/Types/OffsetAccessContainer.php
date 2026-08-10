<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

/**
 * @phpstan-type UserShape array{id: positive-int, username: non-empty-string}
 */
class OffsetAccessContainer
{
    public const CONFIG_MAP = [
        'mysql' => 'PDO\MySQL\Driver',
    ];

    /**
     * @param UserShape['id'] $id
     */
    public function setUserId(int $id): int
    {
        return $id;
    }

    /**
     * @param self::CONFIG_MAP['mysql'] $driverClass
     */
    public static function setDriver(string $driverClass): string
    {
        return $driverClass;
    }
}
