<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\DatabaseDriverMap;
use TypePHP\Tests\Fixtures\Types\DoctrineLikeConnection;
use TypePHP\Tests\Fixtures\Types\StatusEnum;

/**
 * External function evaluating key-of on a public class constant
 *
 * @param key-of<DatabaseDriverMap::PUBLIC_MAP> $action
 */
function testExternalPublicConstKey(string $action): string
{
    return $action;
}

/**
 * @param key-of<StatusEnum> $statusName
 */
function testEnumKeyOf(string $statusName): string
{
    return $statusName;
}

/**
 * @param value-of<StatusEnum> $statusValue
 */
function testEnumValueOf(string $statusValue): string
{
    return $statusValue;
}

/**
 * @param key-of<array{string, int}> $index
 */
function testKeylessTupleKeyOf(int $index): int
{
    return $index;
}

describe('key-of<T> and value-of<T> Annotations', function () {

    describe('Array Constants (e.g. self::DRIVER_MAP)', function () {

        test('accepts valid key-of on a private constant from a static method', function () {
            expect(DatabaseDriverMap::checkStaticDriverKey('pdo_mysql'))->toBe('pdo_mysql');
            expect(DatabaseDriverMap::checkStaticDriverKey('pdo_sqlite'))->toBe('pdo_sqlite');
        });

        test('throws TypeError on invalid key-of on a private constant from a static method', function () {
            expect(fn () => DatabaseDriverMap::checkStaticDriverKey('pdo_pgsql'))
                ->toThrow(TypeError::class, 'must be a key of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::DRIVER_MAP')
            ;
        });

        test('accepts valid key-of on a private constant from an instance method', function () {
            $dbMap = new DatabaseDriverMap();
            expect($dbMap->checkInstanceDriverKey('pdo_mysql'))->toBe('pdo_mysql');
        });

        test('throws TypeError on invalid key-of on a public constant from a private method', function () {
            $dbMap = new DatabaseDriverMap();

            expect($dbMap->proxyPrivateMethod('read'))->toBe('read');

            expect(fn () => $dbMap->proxyPrivateMethod('delete'))
                ->toThrow(TypeError::class, 'must be a key of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::PUBLIC_MAP')
            ;
        });

        test('accepts valid key-of on a public constant from an external function', function () {
            expect(testExternalPublicConstKey('write'))->toBe('write');

            expect(fn () => testExternalPublicConstKey('execute'))
                ->toThrow(TypeError::class, 'must be a key of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::PUBLIC_MAP')
            ;
        });

        test('accepts valid value-of on a private constant array', function () {
            expect(DatabaseDriverMap::checkStaticDriverValue('PDO\MySQL\Driver'))->toBe('PDO\MySQL\Driver');
        });

        test('throws TypeError on invalid value-of on a private constant array', function () {
            expect(fn () => DatabaseDriverMap::checkStaticDriverValue('PDO\PgSQL\Driver'))
                ->toThrow(TypeError::class, 'must be a value of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::DRIVER_MAP')
            ;
        });
    });

    describe('Enums (e.g. StatusEnum)', function () {

        test('key-of<Enum> strictly checks against the Enum CASE NAMES', function () {
            expect(testEnumKeyOf('Active'))->toBe('Active');
            expect(testEnumKeyOf('Pending'))->toBe('Pending');

            expect(fn () => testEnumKeyOf('Archived'))
                ->toThrow(TypeError::class, 'must be a key of enum TypePHP\Tests\Fixtures\Types\StatusEnum')
            ;

            expect(fn () => testEnumKeyOf('active'))
                ->toThrow(TypeError::class, "must be a key of enum TypePHP\Tests\Fixtures\Types\StatusEnum, string 'active' given")
            ;
        });

        test('value-of<Enum> strictly checks against the Enum BACKING VALUES', function () {
            expect(testEnumValueOf('active'))->toBe('active');
            expect(testEnumValueOf('pending'))->toBe('pending');

            expect(fn () => testEnumValueOf('archived'))
                ->toThrow(TypeError::class, 'must be a value of enum TypePHP\Tests\Fixtures\Types\StatusEnum')
            ;

            expect(fn () => testEnumValueOf('Active'))
                ->toThrow(TypeError::class, "must be a value of enum TypePHP\Tests\Fixtures\Types\StatusEnum, string 'Active' given")
            ;
        });
    });

    describe('Array Shapes (e.g. key-of<array{id: int, name: string}>)', function () {

        test('accepts valid string keys of an inline array shape', function () {
            expect(DatabaseDriverMap::checkArrayShapeKey('id'))->toBe('id');
            expect(DatabaseDriverMap::checkArrayShapeKey('name'))->toBe('name');
        });

        test('throws TypeError on invalid string key of an inline array shape', function () {
            expect(fn () => DatabaseDriverMap::checkArrayShapeKey('invalid_key'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });

        test('accepts valid positional indices for key-of on implicit tuple shapes', function () {
            expect(testKeylessTupleKeyOf(0))->toBe(0);
            expect(testKeylessTupleKeyOf(1))->toBe(1);

            expect(fn () => testKeylessTupleKeyOf(2))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });
    });

    describe('Type Aliases (@phpstan-type nested shapes)', function () {

        test('validates key-of and value-of correctly when deeply nested inside an array shape type alias', function () {
            $conn = new DoctrineLikeConnection();

            expect($conn->connect([
                'driver' => 'pdo_mysql',
                'driverClass' => 'PDO\MySQL\Driver',
            ]))->toBeTrue();

            expect(fn () => $conn->connect(['driver' => 'pdo_pgsql']))
                ->toThrow(TypeError::class, "['driver'] must be a key of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::DRIVER_MAP")
            ;

            expect(fn () => $conn->connect([
                'driver' => 'pdo_mysql',
                'driverClass' => 'PDO\PgSQL\Driver',
            ]))->toThrow(TypeError::class, "['driverClass'] must be a value of TypePHP\Tests\Fixtures\Types\DatabaseDriverMap::DRIVER_MAP");
        });

        test('validates key-of with self:: reference natively inside a local type alias', function () {
            $conn = new DoctrineLikeConnection();

            expect($conn->localAction(['action' => 'start']))->toBeTrue();

            expect(fn () => $conn->localAction(['action' => 'pause']))
                ->toThrow(TypeError::class, "['action'] must be a key of TypePHP\Tests\Fixtures\Types\DoctrineLikeConnection::LOCAL_ACTIONS")
            ;
        });
    });
});
