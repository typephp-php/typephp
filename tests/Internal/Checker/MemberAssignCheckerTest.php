<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Checker;

use TypePHP\Internal\Checker\MemberAssignChecker;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

describe('MemberAssignChecker Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
    });

    test('accepts valid array shape key assignment and returns value', function () {
        $root = ['count' => 5, 'name' => 'Alice'];
        $chain = [['dim', 'count']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'array{count: int, name: string}',
            'stats',
            __FILE__,
            $this->registry
        );

        expect($result)->toBe(10)
            ->and($root['count'])->toBe(5)
        ;
    });

    test('returns ErrorMessage on invalid array shape key assignment', function () {
        $root = ['count' => 5];
        $chain = [['dim', 'count']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'not_an_int',
            'array{count: int}',
            'stats',
            __FILE__,
            $this->registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("['count'] must be of type int")
        ;
    });

    test('returns ErrorMessage when assigning unexpected key to sealed array shape', function () {
        $root = ['count' => 5];
        $chain = [['dim', 'extra']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'forbidden',
            'array{count: int}',
            'stats',
            __FILE__,
            $this->registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("contains unsealed unexpected key 'extra'")
        ;
    });

    test('fast-path O(1) in hybrid mode validates leaf directly on large list', function () {
        Config::set(['array_validation' => 'hybrid']);

        $root = range(1, 200);
        $chain = [['dim', 50]];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            100,
            'list<positive-int>',
            'list',
            __FILE__,
            $this->registry
        );
        expect($valid)->toBe(100);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -50,
            'list<positive-int>',
            'list',
            __FILE__,
            $this->registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('positive-int')
        ;
    });

    test('fast-path in hybrid mode rejects non-integer keys on list types', function () {
        Config::set(['array_validation' => 'hybrid']);

        $root = [1, 2, 3];
        $chain = [['dim', 'string_key']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'list<int>',
            'items',
            __FILE__,
            $this->registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('must be a list, non-integer key')
        ;
    });

    test('fast-path in hybrid mode validates key on generic maps (array<string, positive-int>)', function () {
        Config::set(['array_validation' => 'hybrid']);

        $root = ['a' => 1];
        $chain = [['dim', 123]];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'array<string, positive-int>',
            'map',
            __FILE__,
            $this->registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('key must be of type string')
        ;
    });

    test('validates multi-level nested array shape assignment', function () {
        $root = ['user' => ['id' => 1, 'role' => 'admin']];
        $chain = [['dim', 'user'], ['dim', 'id']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            42,
            'array{user: array{id: positive-int, role: non-empty-string}}',
            'config',
            __FILE__,
            $this->registry
        );
        expect($valid)->toBe(42);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -99,
            'array{user: array{id: positive-int, role: non-empty-string}}',
            'config',
            __FILE__,
            $this->registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain("['user']['id']")
        ;
    });

    test('validates object shape property assignment on stdClass', function () {
        $root = (object) ['count' => 5];
        $chain = [['prop', 'count']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'object{count: int}',
            'stats',
            __FILE__,
            $this->registry
        );
        expect($valid)->toBe(10)
            ->and($root->count)->toBe(5)
        ;

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            'not_an_int',
            'object{count: int}',
            'stats',
            __FILE__,
            $this->registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('->count must be of type int')
        ;
    });

    test('returns ErrorMessage when assigning unexpected property to sealed object shape', function () {
        $root = (object) ['count' => 5];
        $chain = [['prop', 'unexpected_field']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'forbidden',
            'object{count: int}',
            'stats',
            __FILE__,
            $this->registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("contains unexpected property 'unexpected_field'")
        ;
    });

    test('validates multi-level nested object shape property assignment', function () {
        $root = (object) ['user' => (object) ['id' => 1]];
        $chain = [['prop', 'user'], ['prop', 'id']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            100,
            'object{user: object{id: positive-int}}',
            'data',
            __FILE__,
            $this->registry
        );
        expect($valid)->toBe(100);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -50,
            'object{user: object{id: positive-int}}',
            'data',
            __FILE__,
            $this->registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('->user->id')
        ;
    });
});
