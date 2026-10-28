<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

describe('End-to-End Reference Mutation & Aliasing Suite', function () {
    test('1. Direct index assignment catches type violation', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use (&$numbers) {
            $numbers[0] = 'wrong';
        })->toThrow(TypeError::class, 'Variable $numbers[0] must be of type int, string \'wrong\' given');
    });

    test('2. Modification through a reference alias catches type violation at mutation point', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers[0];

        expect(function () use (&$ref) {
            $ref = 'wrong';
        })->toThrow(TypeError::class, 'Variable $ref must be of type int, string \'wrong\' given');
    });

    test('3. Modification through foreach reference catches type violation inside loop', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use (&$numbers) {
            foreach ($numbers as &$number) {
                $number = 'wrong';
            }
        })->toThrow(TypeError::class, 'Variable $number must be of type int, string \'wrong\' given');
    });

    test('4. Mutation through a by-reference parameter catches type violation upon callback exit', function () {
        /** @var callable(list<int>&): void $corrupt */
        $corrupt = function (array &$numbers): void {
            $numbers[] = 'wrong';
        };

        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use ($corrupt, &$numbers) {
            $corrupt($numbers);
        })->toThrow(TypeError::class, 'Callback argument #1[3] must be of type int, string \'wrong\' given');
    });

    test('5. Revalidation at reference assignment intercepts before function boundary call', function () {
        /** @var callable(list<int>): void $acceptNumbers */
        $acceptNumbers = function (array $numbers): void {
        };

        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers[0];

        expect(function () use (&$ref) {
            $ref = 'wrong';
        })->toThrow(TypeError::class, 'Variable $ref must be of type int, string \'wrong\' given');
    });
});
