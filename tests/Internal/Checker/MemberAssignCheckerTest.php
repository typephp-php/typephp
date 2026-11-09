<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Checker\MemberAssignChecker;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;

class HybridPropertyFixture
{
    /**
     * @var list<positive-int>
     */
    public array $listProp = [];

    /**
     * @var array<string, positive-int>
     */
    public array $mapProp = [];

    /**
     * @var array<positive-int>
     */
    public array $singleGenericProp = [];

    public array $unannotatedProp = [];
}

/**
 * @template T
 */
class HybridGenericPropertyHolder
{
    /**
     * @var list<T>
     */
    public array $items = [];
}

describe('MemberAssignChecker Unit Tests', function () {
    beforeEach(function () {
        $this->registry = new TypeValidatorRegistry();
    });

    afterEach(function () {
        Config::reset();
    });

    test('accepts valid array shape key assignment and returns value', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = ['count' => 5, 'name' => 'Alice'];
        $chain = [['dim', 'count']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'array{count: int, name: string}',
            'stats',
            __FILE__,
            $registry
        );

        expect($result)->toBe(10)
            ->and($root['count'])->toBe(5)
        ;
    });

    test('returns ErrorMessage on invalid array shape key assignment', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = ['count' => 5];
        $chain = [['dim', 'count']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'not_an_int',
            'array{count: int}',
            'stats',
            __FILE__,
            $registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("['count'] must be of type int")
        ;
    });

    test('returns ErrorMessage when assigning unexpected key to sealed array shape', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = ['count' => 5];
        $chain = [['dim', 'extra']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'forbidden',
            'array{count: int}',
            'stats',
            __FILE__,
            $registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("contains unsealed unexpected key 'extra'")
        ;
    });

    test('fast-path O(1) in hybrid mode validates leaf directly on large list', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $root = range(1, 200);
        $chain = [['dim', 50]];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            100,
            'list<positive-int>',
            'list',
            __FILE__,
            $registry
        );
        expect($valid)->toBe(100);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -50,
            'list<positive-int>',
            'list',
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('positive-int')
        ;
    });

    test('fast-path in hybrid mode rejects non-integer keys on list types', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $root = [1, 2, 3];
        $chain = [['dim', 'string_key']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'list<int>',
            'items',
            __FILE__,
            $registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('must be a list, non-integer key')
        ;
    });

    test('fast-path in hybrid mode validates key on generic maps (array<string, positive-int>)', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $root = ['a' => 1];
        $chain = [['dim', 123]];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'array<string, positive-int>',
            'map',
            __FILE__,
            $registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain('key must be of type string')
        ;
    });

    test('validates multi-level nested array shape assignment', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = ['user' => ['id' => 1, 'role' => 'admin']];
        $chain = [['dim', 'user'], ['dim', 'id']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            42,
            'array{user: array{id: positive-int, role: non-empty-string}}',
            'config',
            __FILE__,
            $registry
        );
        expect($valid)->toBe(42);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -99,
            'array{user: array{id: positive-int, role: non-empty-string}}',
            'config',
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain("['user']['id']")
        ;
    });

    test('validates object shape property assignment on stdClass', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = (object) ['count' => 5];
        $chain = [['prop', 'count']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            10,
            'object{count: int}',
            'stats',
            __FILE__,
            $registry
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
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('->count must be of type int')
        ;
    });

    test('returns ErrorMessage when assigning unexpected property to sealed object shape', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = (object) ['count' => 5];
        $chain = [['prop', 'unexpected_field']];

        $result = MemberAssignChecker::check(
            $root,
            $chain,
            'forbidden',
            'object{count: int}',
            'stats',
            __FILE__,
            $registry
        );

        expect($result)->toBeInstanceOf(ErrorMessage::class)
            ->and($result->getMessage())->toContain("contains unexpected property 'unexpected_field'")
        ;
    });

    test('validates multi-level nested object shape property assignment', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $root = (object) ['user' => (object) ['id' => 1]];
        $chain = [['prop', 'user'], ['prop', 'id']];

        $valid = MemberAssignChecker::check(
            $root,
            $chain,
            100,
            'object{user: object{id: positive-int}}',
            'data',
            __FILE__,
            $registry
        );
        expect($valid)->toBe(100);

        $invalid = MemberAssignChecker::check(
            $root,
            $chain,
            -50,
            'object{user: object{id: positive-int}}',
            'data',
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('->user->id')
        ;
    });

    test('checkProperty validates object property dimension assignments and preserves state on failure', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();
        $fixture = new ConfiguredProperty();
        $fixture->numbers = [1, 2, 3];

        $validAppend = MemberAssignChecker::checkProperty(
            $fixture,
            'numbers',
            [['dim', null]],
            4,
            __FILE__,
            $registry
        );
        expect($validAppend)->toBe(4)
            ->and($fixture->numbers)->toBe([1, 2, 3])
        ;

        $invalidAppend = MemberAssignChecker::checkProperty(
            $fixture,
            'numbers',
            [['dim', null]],
            'not_an_int',
            __FILE__,
            $registry
        );
        expect($invalidAppend)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidAppend->getMessage())->toContain('numbers[3] must be of type int')
            ->and($fixture->numbers)->toBe([1, 2, 3])
        ;
    });

    test('checkProperty returns value untouched when target is non-object/non-string or inline properties are disabled', function () {
        $registry = $this->registry ?? new TypeValidatorRegistry();

        expect(MemberAssignChecker::checkProperty(123, 'numbers', [['dim', null]], 'val', __FILE__, $registry))
            ->toBe('val')
        ;

        Config::set(['inline_vars' => ['properties' => false]]);
        $fixture = new ConfiguredProperty();
        $fixture->numbers = [1, 2];

        expect(MemberAssignChecker::checkProperty($fixture, 'numbers', [['dim', null]], 'invalid', __FILE__, $registry))
            ->toBe('invalid')
        ;
    });

    test('checkProperty validates and accepts element mutation on ArrayTypeNode property with > 128 items in hybrid mode', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new ConfiguredProperty();
        $fixture->numbers = range(1, 150);

        $valid = MemberAssignChecker::checkProperty(
            $fixture,
            'numbers',
            [['dim', 50]],
            42,
            __FILE__,
            $registry
        );
        expect($valid)->toBe(42);

        $validAppend = MemberAssignChecker::checkProperty(
            $fixture,
            'numbers',
            [['dim', null]],
            100,
            __FILE__,
            $registry
        );
        expect($validAppend)->toBe(100);
    });

    test('checkProperty rejects element mutation on ArrayTypeNode property with > 128 items when value is invalid', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new ConfiguredProperty();
        $fixture->numbers = range(1, 150);

        $invalid = MemberAssignChecker::checkProperty(
            $fixture,
            'numbers',
            [['dim', 50]],
            'not_an_int',
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('numbers[50] must be of type int')
        ;
    });

    test('checkProperty validates list property on > 128 items: valid index, valid append, invalid value, and non-integer key', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new HybridPropertyFixture();
        $fixture->listProp = range(1, 150);

        $validIndex = MemberAssignChecker::checkProperty(
            $fixture,
            'listProp',
            [['dim', 20]],
            500,
            __FILE__,
            $registry
        );
        expect($validIndex)->toBe(500);

        $validAppend = MemberAssignChecker::checkProperty(
            $fixture,
            'listProp',
            [['dim', null]],
            600,
            __FILE__,
            $registry
        );
        expect($validAppend)->toBe(600);

        $invalidVal = MemberAssignChecker::checkProperty(
            $fixture,
            'listProp',
            [['dim', 20]],
            -10,
            __FILE__,
            $registry
        );
        expect($invalidVal)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidVal->getMessage())->toContain('listProp[20] must be of type positive-int')
        ;

        $invalidKey = MemberAssignChecker::checkProperty(
            $fixture,
            'listProp',
            [['dim', 'non_int_key']],
            500,
            __FILE__,
            $registry
        );
        expect($invalidKey)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidKey->getMessage())->toContain("must be a list, non-integer key 'non_int_key' given")
        ;
    });

    test('checkProperty validates generic key-value map property on > 128 items: valid key/value, invalid value, and invalid key', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new HybridPropertyFixture();
        $map = [];
        for ($i = 0; $i < 150; $i++) {
            $map["key_{$i}"] = $i + 1;
        }
        $fixture->mapProp = $map;

        $valid = MemberAssignChecker::checkProperty(
            $fixture,
            'mapProp',
            [['dim', 'key_10']],
            999,
            __FILE__,
            $registry
        );
        expect($valid)->toBe(999);

        $validAppend = MemberAssignChecker::checkProperty(
            $fixture,
            'mapProp',
            [['dim', null]],
            888,
            __FILE__,
            $registry
        );
        expect($validAppend)->toBe(888);

        $invalidVal = MemberAssignChecker::checkProperty(
            $fixture,
            'mapProp',
            [['dim', 'key_10']],
            -5,
            __FILE__,
            $registry
        );
        expect($invalidVal)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidVal->getMessage())->toContain("mapProp['key_10'] must be of type positive-int")
        ;

        $invalidKey = MemberAssignChecker::checkProperty(
            $fixture,
            'mapProp',
            [['dim', 12345]],
            100,
            __FILE__,
            $registry
        );
        expect($invalidKey)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalidKey->getMessage())->toContain('mapProp key must be of type string')
        ;
    });

    test('checkProperty validates single generic array property on > 128 items (array<T>)', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new HybridPropertyFixture();
        $fixture->singleGenericProp = range(1, 150);

        $valid = MemberAssignChecker::checkProperty(
            $fixture,
            'singleGenericProp',
            [['dim', 10]],
            50,
            __FILE__,
            $registry
        );
        expect($valid)->toBe(50);

        $invalid = MemberAssignChecker::checkProperty(
            $fixture,
            'singleGenericProp',
            [['dim', 10]],
            -5,
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain("singleGenericProp['10'] must be of type positive-int")
        ;
    });

    test('checkProperty substitutes generic templates on class when validating element mutation in hybrid mode', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $holder = new HybridGenericPropertyHolder();
        $holder->items = range(1, 150);
        TemplateManager::bindTemplate('none', $holder, 'T', new IdentifierTypeNode('positive-int'));

        $valid = MemberAssignChecker::checkProperty(
            $holder,
            'items',
            [['dim', 10]],
            42,
            __FILE__,
            $registry
        );
        expect($valid)->toBe(42);

        $invalid = MemberAssignChecker::checkProperty(
            $holder,
            'items',
            [['dim', 10]],
            -5,
            __FILE__,
            $registry
        );
        expect($invalid)->toBeInstanceOf(ErrorMessage::class)
            ->and($invalid->getMessage())->toContain('items[10] must be of type positive-int')
        ;
    });

    test('checkProperty returns null from validatePropertyElementMutation when property has no docblock and falls back cleanly', function () {
        Config::set(['array_validation' => 'hybrid']);
        $registry = $this->registry ?? new TypeValidatorRegistry();

        $fixture = new HybridPropertyFixture();
        $fixture->unannotatedProp = range(1, 150);

        $result = MemberAssignChecker::checkProperty(
            $fixture,
            'unannotatedProp',
            [['dim', 10]],
            'any_value',
            __FILE__,
            $registry
        );
        expect($result)->toBe('any_value');
    });
});
