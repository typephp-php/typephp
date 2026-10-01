<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

describe('Array Shape Destructuring Type Propagation', function () {
    describe('Associative Array Shape Destructuring ([\'key\' => $var] = $source)', function () {
        test('propagates member types to extracted variables and allows valid reassignments', function () {
            /** @var array{id: positive-int, name: non-empty-string} $source */
            $source = ['id' => 1, 'name' => 'Alice'];

            ['id' => $extractedId, 'name' => $extractedName] = $source;

            expect($extractedId)->toBe(1)
                ->and($extractedName)->toBe('Alice')
            ;

            $extractedId = 42;
            $extractedName = 'Bob';

            expect($extractedId)->toBe(42)
                ->and($extractedName)->toBe('Bob')
            ;
        });

        test('throws TypeError when variable extracted from shape is reassigned to invalid integer', function () {
            /** @var array{id: positive-int, name: non-empty-string} $source */
            $source = ['id' => 1, 'name' => 'Alice'];

            ['id' => $extractedId, 'name' => $extractedName] = $source;

            expect(function () use (&$extractedId) {
                $extractedId = -5;
            })->toThrow(TypeError::class, 'Variable $extractedId must be of type positive-int');

            expect(function () use (&$extractedId) {
                $extractedId = 0;
            })->toThrow(TypeError::class, 'Variable $extractedId must be of type positive-int');
        });

        test('throws TypeError when variable extracted from shape is reassigned to invalid string', function () {
            /** @var array{id: positive-int, name: non-empty-string} $source */
            $source = ['id' => 1, 'name' => 'Alice'];

            ['id' => $extractedId, 'name' => $extractedName] = $source;

            expect(function () use (&$extractedName) {
                $extractedName = '';
            })->toThrow(TypeError::class, 'Variable $extractedName must be of type non-empty-string');
        });
    });

    describe('Positional Tuple Destructuring ([$a, $b] = $source)', function () {
        test('propagates positional tuple types and prevents invalid reassignments', function () {
            /** @var array{positive-int, non-empty-string} $tupleSource */
            $tupleSource = [10, 'First'];

            [$first, $second] = $tupleSource;

            expect($first)->toBe(10)
                ->and($second)->toBe('First')
            ;

            $first = 99;
            $second = 'Updated';
            expect($first)->toBe(99)->and($second)->toBe('Updated');

            expect(function () use (&$first) {
                $first = -10;
            })->toThrow(TypeError::class, 'Variable $first must be of type positive-int');

            expect(function () use (&$second) {
                $second = '';
            })->toThrow(TypeError::class, 'Variable $second must be of type non-empty-string');
        });

        test('propagates types when elements are skipped using empty comma syntax', function () {
            /** @var array{positive-int, string, int<1, 100>} $tupleSource */
            $tupleSource = [42, 'skipped', 50];

            [$first, , $third] = $tupleSource;

            expect($first)->toBe(42)
                ->and($third)->toBe(50)
            ;

            expect(function () use (&$first) {
                $first = 0;
            })->toThrow(TypeError::class, 'Variable $first must be of type positive-int');

            expect(function () use (&$third) {
                $third = 150;
            })->toThrow(TypeError::class, '<= 100');
        });
    });

    describe('Traditional list(...) Syntax Destructuring', function () {
        test('propagates types when using traditional list() syntax with associative keys', function () {
            /** @var array{code: positive-int, label: non-empty-string} $data */
            $data = ['code' => 200, 'label' => 'OK'];

            list('code' => $code, 'label' => $label) = $data;

            expect($code)->toBe(200)
                ->and($label)->toBe('OK')
            ;

            expect(function () use (&$code) {
                $code = -1;
            })->toThrow(TypeError::class, 'Variable $code must be of type positive-int');

            expect(function () use (&$label) {
                $label = '';
            })->toThrow(TypeError::class, 'Variable $label must be of type non-empty-string');
        });
    });

    describe('Nested Destructuring ([[\'id\' => $id]] = $source)', function () {
        test('recursively propagates types through nested array shape destructuring', function () {
            /**
             * @var array{
             *   account: array{id: positive-int, role: 'admin'|'user'},
             *   status: non-empty-string
             * } $payload
             */
            $payload = [
                'account' => ['id' => 100, 'role' => 'admin'],
                'status' => 'active',
            ];

            ['account' => ['id' => $accountId, 'role' => $accountRole], 'status' => $status] = $payload;

            expect($accountId)->toBe(100)
                ->and($accountRole)->toBe('admin')
                ->and($status)->toBe('active')
            ;

            $accountRole = 'user';
            expect($accountRole)->toBe('user');

            expect(function () use (&$accountId) {
                $accountId = -99;
            })->toThrow(TypeError::class, 'Variable $accountId must be of type positive-int');

            expect(function () use (&$accountRole) {
                $accountRole = 'superadmin';
            })->toThrow(TypeError::class, "('admin' | 'user')");

            expect(function () use (&$status) {
                $status = '';
            })->toThrow(TypeError::class, 'Variable $status must be of type non-empty-string');
        });
    });

    describe('Complex Types Propagated from Shapes (Unions & Refinements)', function () {
        test('propagates literal union types and validates reassignment against union', function () {
            /** @var array{mode: 'sync'|'async', timeout: int<1, 60>} $options */
            $options = ['mode' => 'sync', 'timeout' => 30];

            ['mode' => $mode, 'timeout' => $timeout] = $options;

            $mode = 'async';
            $timeout = 10;
            expect($mode)->toBe('async')->and($timeout)->toBe(10);

            expect(function () use (&$mode) {
                $mode = 'deferred';
            })->toThrow(TypeError::class, "('sync' | 'async')");

            expect(function () use (&$timeout) {
                $timeout = 120;
            })->toThrow(TypeError::class, '<= 60');
        });
    });

    describe('Non-Regressions & Unannotated Fallbacks', function () {
        test('does not interfere with destructuring from untyped raw arrays', function () {
            $untyped = ['x' => 1, 'y' => 'hello'];

            ['x' => $x, 'y' => $y] = $untyped;

            expect($x)->toBe(1)->and($y)->toBe('hello');

            $x = -50;
            $y = '';
            expect($x)->toBe(-50)->and($y)->toBe('');
        });

        test('retaining explicit inline @var on destructuring statement overrides source shape', function () {
            /** @var array{id: int, name: string} $source */
            $source = ['id' => 10, 'name' => 'Alice'];

            /**
             * Explicit inline @var refines $id to positive-int:
             *
             * @var positive-int $id
             */
            ['id' => $id, 'name' => $name] = $source;

            expect(function () use (&$id) {
                $id = -1;
            })->toThrow(TypeError::class, 'Variable $id must be of type positive-int');
        });
    });
});
