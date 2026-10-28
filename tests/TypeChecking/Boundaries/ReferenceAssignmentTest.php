<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

describe('Reference Assignment Type Propagation ($ref =& $var)', function () {
    test('intercepts invalid assignment to variable referencing array element (Test 2)', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers[0];

        expect(function () use (&$ref) {
            $ref = 'wrong';
        })->toThrow(TypeError::class, 'Variable $ref must be of type int, string \'wrong\' given');
    });

    test('allows valid assignment to variable referencing array element and mutates array in place', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers[0];
        $ref = 42;

        expect($numbers)->toBe([42, 2, 3])
            ->and($ref)->toBe(42)
        ;
    });

    test('intercepts invalid assignment on aliased whole collection ($ref =& $numbers)', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers;

        expect(function () use (&$ref) {
            $ref[] = 'wrong';
        })->toThrow(TypeError::class, 'must be of type int');
    });

    test('intercepts invalid assignment to reference on specific array shape key', function () {
        /** @var array{id: positive-int, name: non-empty-string} $user */
        $user = ['id' => 1, 'name' => 'Alice'];

        $idRef = &$user['id'];
        $nameRef = &$user['name'];

        expect(function () use (&$idRef) {
            $idRef = -5;
        })->toThrow(TypeError::class, 'Variable $idRef must be of type positive-int');

        expect(function () use (&$nameRef) {
            $nameRef = '';
        })->toThrow(TypeError::class, 'Variable $nameRef must be of type non-empty-string');
    });

    test('unregisters alias type when reference is broken or rebound to untyped variable', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $ref = &$numbers[0];

        $untypedString = 'clean';
        $ref = &$untypedString;

        // $ref is now bound to $untypedString (untyped), so assigning string must not throw int mismatch!
        $ref = 'allowed_string';
        expect($ref)->toBe('allowed_string');
    });
});
