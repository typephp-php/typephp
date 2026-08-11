<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\OffsetAccessContainer;

/**
 * @param array{status: 'active'|'pending'}['status'] $status
 */
function testDirectShapeOffset(string $status): string
{
    return $status;
}

describe('Offset Access Types T[K]', function () {

    test('resolves shape offset from type alias UserShape[\'id\'] to positive-int', function () {
        $container = new OffsetAccessContainer();

        expect($container->setUserId(42))->toBe(42);

        expect(fn () => $container->setUserId(-5))
            ->toThrow(TypeError::class, 'must be of type positive-int')
        ;
    });

    test('resolves constant offset from self::CONFIG_MAP[\'mysql\'] to literal string', function () {
        expect(OffsetAccessContainer::setDriver('PDO\MySQL\Driver'))->toBe('PDO\MySQL\Driver');

        expect(fn () => OffsetAccessContainer::setDriver('PDO\PgSQL\Driver'))
            ->toThrow(TypeError::class, 'must be literal')
        ;
    });

    test('resolves direct inline shape offset array{...}[\'status\'] to union status', function () {
        expect(testDirectShapeOffset('active'))->toBe('active');
        expect(testDirectShapeOffset('pending'))->toBe('pending');

        expect(fn () => testDirectShapeOffset('archived'))
            ->toThrow(TypeError::class, "('active' | 'pending')")
        ;
    });

});
