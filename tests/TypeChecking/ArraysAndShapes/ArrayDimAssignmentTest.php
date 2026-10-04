<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

use TypePHP\Exception\TypeError;

describe('Array Dimension & Key Assignment Validation ($arr[$key] = $val)', function () {
    test('throws TypeError when assigning an invalid value to an array shape key (User Bug Report)', function () {
        /** @var array{count: int} $stats2 */
        $stats2 = ['count' => 5];

        expect(function () use (&$stats2) {
            $stats2['count'] = 'not_an_int';
        })->toThrow(TypeError::class);
    });

    test('allows assigning a valid value to an array shape key', function () {
        /** @var array{count: int, name: string} $stats */
        $stats = ['count' => 5, 'name' => 'Alice'];

        $stats['count'] = 10;
        $stats['name'] = 'Bob';

        expect($stats['count'])->toBe(10)
            ->and($stats['name'])->toBe('Bob')
        ;
    });

    test('throws TypeError when assigning an unexpected key to a sealed array shape', function () {
        /** @var array{count: int} $stats */
        $stats = ['count' => 5];

        expect(function () use (&$stats) {
            $stats['unknown_extra'] = 'forbidden';
        })->toThrow(TypeError::class, "contains unsealed unexpected key 'unknown_extra'");
    });

    test('throws TypeError when appending an invalid value to a list ($list[] = $val)', function () {
        /** @var list<positive-int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use (&$numbers) {
            $numbers[] = -10;
        })->toThrow(TypeError::class, 'positive-int');
    });

    test('throws TypeError when assigning to a multi-dimensional nested array shape key', function () {
        /** @var array{user: array{id: positive-int, role: non-empty-string}} $config */
        $config = [
            'user' => [
                'id' => 10,
                'role' => 'admin',
            ],
        ];

        expect(function () use (&$config) {
            $config['user']['id'] = -99;
        })->toThrow(TypeError::class, 'positive-int');

        expect(function () use (&$config) {
            $config['user']['role'] = '';
        })->toThrow(TypeError::class, 'non-empty-string');
    });

    test('preserves array state when assignment throws TypeError (atomic failure)', function () {
        /** @var array{count: int} $stats */
        $stats = ['count' => 5];

        try {
            $stats['count'] = 'invalid_string';
        } catch (TypeError $e) {
            // Expected
        }

        expect($stats['count'])->toBe(5);
    });
});

describe('Object Shape Property Assignment Validation ($obj->prop = $val)', function () {
    test('throws TypeError when assigning an invalid value to an object shape property on stdClass', function () {
        /** @var object{count: int} $stats */
        $stats = (object) ['count' => 5];

        expect(function () use (&$stats) {
            $stats->count = 'not_an_int';
        })->toThrow(TypeError::class);
    });

    test('allows assigning a valid value to an object shape property on stdClass', function () {
        /** @var object{count: int, name: string} $stats */
        $stats = (object) ['count' => 5, 'name' => 'Alice'];

        $stats->count = 10;
        $stats->name = 'Bob';

        expect($stats->count)->toBe(10)
            ->and($stats->name)->toBe('Bob')
        ;
    });

    test('throws TypeError when assigning an unexpected property to a sealed object shape', function () {
        /** @var object{count: int} $stats */
        $stats = (object) ['count' => 5];

        expect(function () use (&$stats) {
            $stats->unexpected_field = 'forbidden';
        })->toThrow(TypeError::class);
    });

    test('throws TypeError when assigning to a nested object shape property ($obj->user->id = $val)', function () {
        /** @var object{user: object{id: positive-int}} $config */
        $config = (object) [
            'user' => (object) ['id' => 1],
        ];

        expect(function () use (&$config) {
            $config->user->id = -99;
        })->toThrow(TypeError::class, 'positive-int');
    });

    test('throws TypeError on mixed array-inside-object shape assignment ($obj->items[] = $val)', function () {
        /** @var object{items: list<positive-int>} $data */
        $data = (object) [
            'items' => [1, 2, 3],
        ];

        expect(function () use (&$data) {
            $data->items[] = -10;
        })->toThrow(TypeError::class, 'positive-int');
    });

    test('throws TypeError on mixed object-inside-array shape assignment ($data[\'user\']->name = $val)', function () {
        /** @var array{user: object{name: non-empty-string}} $data */
        $data = [
            'user' => (object) ['name' => 'Alice'],
        ];

        expect(function () use (&$data) {
            $data['user']->name = '';
        })->toThrow(TypeError::class, 'non-empty-string');
    });
});
