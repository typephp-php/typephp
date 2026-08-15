<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Unpack\UnpackService;

/**
 * @param positive-int $id
 * @param non-empty-string $name
 * @param int<1, 100> $age
 */
function testUnpackFunction(int $id, string $name, int $age): array
{
    return ['id' => $id, 'name' => $name, 'age' => $age];
}

/**
 * @param positive-int ...$ids
 */
function testVariadicUnpackFunction(int ...$ids): int
{
    return count($ids);
}

describe('PHP 8.0+ Argument Unpacking / Spread (...$args)', function () {
    describe('Standalone Functions with Argument Unpacking', function () {
        test('accepts unpacked associative array with named keys in swapped order', function () {
            $payload = [
                'age' => 25,
                'name' => 'Alice',
                'id' => 100,
            ];

            $result = testUnpackFunction(...$payload);

            expect($result)->toBe([
                'id' => 100,
                'name' => 'Alice',
                'age' => 25,
            ]);
        });

        test('accepts mixed positional and unpacked named arguments', function () {
            $extra = [
                'age' => 30,
                'name' => 'Bob',
            ];

            $result = testUnpackFunction(42, ...$extra);

            expect($result)->toBe([
                'id' => 42,
                'name' => 'Bob',
                'age' => 30,
            ]);
        });

        test('throws TypeError when unpacked argument violates parameter contract', function () {
            $badPayload = [
                'age' => 25,
                'name' => 'Alice',
                'id' => -10, // Violates positive-int
            ];

            expect(fn () => testUnpackFunction(...$badPayload))
                ->toThrow(TypeError::class, 'Argument $id must be of type positive-int');
        });

        test('throws TypeError when unpacked int range argument exceeds max bound', function () {
            $badAgePayload = [
                'id' => 10,
                'name' => 'Alice',
                'age' => 150, // Violates int<1, 100>
            ];

            expect(fn () => testUnpackFunction(...$badAgePayload))
                ->toThrow(TypeError::class, 'Argument $age');
        });

        test('throws TypeError when unpacked string argument is empty', function () {
            $badNamePayload = [
                'id' => 10,
                'name' => '', // Violates non-empty-string
                'age' => 25,
            ];

            expect(fn () => testUnpackFunction(...$badNamePayload))
                ->toThrow(TypeError::class, 'Argument $name must be of type non-empty-string');
        });
    });

    describe('Variadic Parameter Unpacking (...$ids)', function () {
        test('accepts valid unpacked positional list into variadic parameter', function () {
            $ids = [10, 20, 30, 40];

            expect(testVariadicUnpackFunction(...$ids))->toBe(4);
        });

        test('throws TypeError when unpacked variadic list contains an invalid element', function () {
            $ids = [10, 20, -5, 40];

            expect(fn () => testVariadicUnpackFunction(...$ids))
                ->toThrow(TypeError::class, 'Argument $ids[2] must be of type positive-int');
        });
    });

    describe('Class Method Argument Unpacking', function () {
        test('accepts unpacked named arguments on class method', function () {
            $service = new UnpackService();
            $params = [
                'username' => 'alice_admin',
                'role' => 'admin',
                'id' => 1,
                'active' => true,
            ];

            $result = $service->configureUser(...$params);

            expect($result)->toBe([
                'id' => 1,
                'username' => 'alice_admin',
                'role' => 'admin',
                'active' => true,
            ]);
        });

        test('throws TypeError when unpacked role on class method violates literal union', function () {
            $service = new UnpackService();
            $params = [
                'id' => 1,
                'username' => 'alice_admin',
                'role' => 'superadmin', // Violates 'admin'|'editor'|'viewer'
            ];

            expect(fn () => $service->configureUser(...$params))
                ->toThrow(TypeError::class, "Argument \$role must be of type ('admin' | 'editor' | 'viewer')");
        });

        test('accepts unpacked variadic integers on class method', function () {
            $service = new UnpackService();
            $scores = [100, 200, 300];

            expect($service->sumScores(...$scores))->toBe(600);

            $badScores = [100, -50, 300];
            expect(fn () => $service->sumScores(...$badScores))
                ->toThrow(TypeError::class, 'Argument $scores[1] must be of type positive-int');
        });
    });
});