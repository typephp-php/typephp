<?php

declare(strict_types=1);

namespace TypePHP\Tests\Fixtures\Types;

class DatabaseDriverMap
{
    private const DRIVER_MAP = [
        'pdo_mysql'  => 'PDO\MySQL\Driver',
        'pdo_sqlite' => 'PDO\SQLite\Driver',
    ];

    public const PUBLIC_MAP = [
        'read'  => 1,
        'write' => 2,
    ];

    /**
     * @param key-of<self::DRIVER_MAP> $driver
     */
    public static function checkStaticDriverKey(string $driver): string
    {
        return $driver;
    }

    /**
     * @param value-of<self::DRIVER_MAP> $driverClass
     */
    public static function checkStaticDriverValue(string $driverClass): string
    {
        return $driverClass;
    }

    /**
     * @param key-of<self::DRIVER_MAP> $driver
     */
    public function checkInstanceDriverKey(string $driver): string
    {
        return $driver;
    }

    /**
     * @param key-of<self::PUBLIC_MAP> $action
     */
    private function checkPrivateMethodKey(string $action): string
    {
        return $action;
    }

    public function proxyPrivateMethod(string $action): string
    {
        return $this->checkPrivateMethodKey($action);
    }

    /**
     * @param key-of<array{id: int, name: string}> $key
     */
    public static function checkArrayShapeKey(string $key): string
    {
        return $key;
    }
}