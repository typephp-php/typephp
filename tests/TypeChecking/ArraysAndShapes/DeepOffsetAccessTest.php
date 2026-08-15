<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\DeepOffsetContainer;

/**
 * Standalone function with 2-level nested offset access directly on an inline shape
 *
 * @param array{server: array{host: non-empty-string, ssl: bool}}['server']['host'] $host
 * @param array{server: array{host: non-empty-string, ssl: bool}}['server']['ssl'] $ssl
 */
function testDirectNestedOffsetAccess(string $host, bool $ssl): array
{
    return ['host' => $host, 'ssl' => $ssl];
}

describe('Multi-Level Nested Offset Access (T[K1][K2])', function () {
    describe('Nested Offset Access from Type Aliases (DatabaseConfig[\'connection\'][\'port\'])', function () {
        test('accepts valid parameters matching multi-level offset types', function () {
            $container = new DeepOffsetContainer();
            $result = $container->configureDatabase(3306, 'mysql');

            expect($result)->toBe([
                'port' => 3306,
                'driver' => 'mysql',
            ]);
        });

        test('throws TypeError when port exceeds integer bounds from nested offset access', function () {
            $container = new DeepOffsetContainer();
            
            expect(fn () => $container->configureDatabase(70000, 'mysql'))
                ->toThrow(TypeError::class, 'Argument $port');

            expect(fn () => $container->configureDatabase(0, 'mysql'))
                ->toThrow(TypeError::class, 'Argument $port');
        });

        test('throws TypeError when driver violates literal union from nested offset access', function () {
            $container = new DeepOffsetContainer();

            expect(fn () => $container->configureDatabase(3306, 'sqlite'))
                ->toThrow(TypeError::class, "Argument \$driver must be of type ('mysql' | 'pgsql')");
        });
    });

    describe('Direct Inline Shape Multi-Level Offset Access', function () {
        test('accepts valid parameters evaluated from direct multi-level shape offsets', function () {
            $result = testDirectNestedOffsetAccess('api.example.com', true);

            expect($result)->toBe([
                'host' => 'api.example.com',
                'ssl' => true,
            ]);
        });

        test('throws TypeError when host is empty string', function () {
            expect(fn () => testDirectNestedOffsetAccess('', true))
                ->toThrow(TypeError::class, 'Argument $host must be of type non-empty-string');
        });
    });
});