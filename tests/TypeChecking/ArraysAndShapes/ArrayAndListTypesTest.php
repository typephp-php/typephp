<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;

/**
 * 1. Object Array (Dog[])
 *
 * @param Dog[] $dogs
 */
function testDogArrayParam(array $dogs): int
{
    return \count($dogs);
}

/**
 * 2. Key-Value Generic Array (array<string, positive-int>)
 *
 * @param array<string, positive-int> $scores
 */
function testAssocScoreArrayParam(array $scores): int
{
    return \count($scores);
}

/**
 * 3. List & Non-Empty List
 *
 * @param list<non-empty-string> $tags
 */
function testTagListParam(array $tags): int
{
    return \count($tags);
}

/**
 * @param non-empty-list<int> $numbers
 */
function testNonEmptyNumberListParam(array $numbers): int
{
    return \count($numbers);
}

/**
 * 4. Deeply Nested Arrays (array<string, list<positive-int>>)
 *
 * @param array<string, list<positive-int>> $matrix
 */
function testNestedMatrixParam(array $matrix): int
{
    return \count($matrix);
}

/**
 * 5. List of Generic Objects (list<Producer<covariant Animal>>)
 *
 * @param list<Producer<covariant Animal>> $producers
 */
function testGenericProducerListParam(array $producers): int
{
    return \count($producers);
}

/**
 * 6. Positional Tuple Shapes (array{0: positive-int, 1: non-empty-string})
 *
 * @param array{0: positive-int, 1: non-empty-string} $tuple
 */
function testTupleShapeParam(array $tuple): bool
{
    return true;
}

/**
 * 7. Unsealed Array Shapes (array{id: positive-int, ...<string, string>})
 *
 * @param array{id: positive-int, ...<string, string>} $payload
 */
function testUnsealedShapeParam(array $payload): bool
{
    return true;
}

/**
 * 8. Variadic Array Shapes (@param array{...} ...$users)
 *
 * @param array{id: positive-int, name: non-empty-string} ...$users
 */
function testVariadicArrayShapeParam(array ...$users): int
{
    return \count($users);
}

/**
 * 9. Complex Shapes with Nested Generic Lists & Unions
 *
 * @param array{id: positive-int, tags: list<non-empty-string>, status?: 'active'|'pending'} $data
 */
function testComplexNestedShapeParam(array $data): bool
{
    return true;
}

/**
 * Test function for Issue #20: Implicit keyless tuple array shapes
 *
 * @param array{list<positive-int>, list<non-empty-string>} $tuple
 */
function testKeylessImplicitTupleShape(array $tuple): bool
{
    return true;
}

/**
 * 1. Sealed Shape (Default)
 *
 * @param array{id: positive-int, username: non-empty-string} $sealedPayload
 */
function testSealedShape(array $sealedPayload): bool
{
    return true;
}

/**
 * 2. Unsealed Typed Shape
 * Requires 'id' to be positive-int, but permits additional string-string pairs
 *
 * @param array{id: positive-int, ...<string, string>} $unsealedPayload
 */
function testUnsealedTypedShape(array $unsealedPayload): bool
{
    return true;
}

/**
 * Helpers for Issue #20 Edge Cases
 *
 * @phpstan-type LocalTupleAlias array{list<positive-int>, list<non-empty-string>}
 * @phpstan-type MixedTupleShape array{non-empty-string, code: positive-int, list<int>}
 *
 * @phpstan-import-type SharedTupleShape from \TypePHP\Tests\Fixtures\Types\GlobalTypes as ImportedTuple
 *
 * @param LocalTupleAlias $payload
 * @param MixedTupleShape $mixedPayload
 */
function testLocalTupleAliasParam(array $payload, array $mixedPayload): bool
{
    return true;
}

/**
 * @phpstan-import-type SharedTupleShape from \TypePHP\Tests\Fixtures\Types\GlobalTypes as ImportedTuple
 *
 * @param ImportedTuple $tuple
 */
function testImportedTupleAliasParam(array $tuple): bool
{
    return true;
}

/**
 * @return array{list<positive-int>, non-empty-string}
 */
function testReturnKeylessTuple(bool $valid): array
{
    if (! $valid) {
        return [[10, -5], 'bundle'];
    }

    return [[10, 20], 'bundle'];
}

/**
 * Positional Tuple with Optional Trailing Element
 *
 * @param array{0: positive-int, 1?: non-empty-string} $tuple
 */
function testOptionalTupleParam(array $tuple): bool
{
    return true;
}

describe('Positional Tuples with Optional Trailing Elements (array{0: T1, 1?: T2})', function () {
    test('accepts tuple when optional trailing element is omitted', function () {
        expect(testOptionalTupleParam([42]))->toBeTrue();
    });

    test('accepts tuple when optional trailing element is provided with valid value', function () {
        expect(testOptionalTupleParam([42, 'Alice']))->toBeTrue();
    });

    test('throws TypeError when required first tuple item is invalid', function () {
        expect(fn () => testOptionalTupleParam([-5]))
            ->toThrow(TypeError::class, "['0'] must be of type positive-int")
        ;
    });

    test('throws TypeError when optional second tuple item is provided with invalid value', function () {
        expect(fn () => testOptionalTupleParam([42, '']))
            ->toThrow(TypeError::class, "['1'] must be of type non-empty-string")
        ;
    });
});

describe('Class Object Arrays (Dog[])', function () {
    test('accepts array of matching class instances', function () {
        expect(testDogArrayParam([new Dog(), new Dog()]))->toBe(2);
        expect(testDogArrayParam([]))->toBe(0); // Empty array is valid for Dog[]
    });

    test('throws TypeError when array contains an invalid class object', function () {
        expect(fn () => testDogArrayParam([new Dog(), new Car()]))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Generic Key-Value Arrays (array<K, V>)', function () {
    test('accepts valid string keys and positive-int values', function () {
        expect(testAssocScoreArrayParam(['alice' => 100, 'bob' => 95]))->toBe(2);
    });

    test('throws TypeError when key type is invalid', function () {
        // Integer key 0 instead of string key
        expect(fn () => testAssocScoreArrayParam([0 => 100]))
            ->toThrow(TypeError::class, 'key')
        ;
    });

    test('throws TypeError when value type is invalid', function () {
        // Negative integer -10 instead of positive-int
        expect(fn () => testAssocScoreArrayParam(['alice' => -10]))
            ->toThrow(TypeError::class, "['alice']")
        ;
    });
});

describe('Lists & Non-Empty Lists (list<T> & non-empty-list<T>)', function () {
    test('accepts sequential list of non-empty strings', function () {
        expect(testTagListParam(['php', 'testing', 'pest']))->toBe(3);
    });

    test('throws TypeError when list contains associative keys', function () {
        expect(fn () => testTagListParam(['tag' => 'php']))
            ->toThrow(TypeError::class, 'must be a list')
        ;
    });

    test('throws TypeError when list contains an empty string', function () {
        expect(fn () => testTagListParam(['php', '']))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('accepts valid non-empty list and rejects empty array', function () {
        expect(testNonEmptyNumberListParam([1, 2, 3]))->toBe(3);

        expect(fn () => testNonEmptyNumberListParam([]))
            ->toThrow(TypeError::class, 'non-empty list')
        ;
    });
});

describe('Deeply Nested Arrays (array<K, list<V>>)', function () {
    test('accepts valid nested array structure', function () {
        $validMatrix = [
            'math' => [100, 95],
            'science' => [88, 92],
        ];

        expect(testNestedMatrixParam($validMatrix))->toBe(2);
    });

    test('throws TypeError when nested array item is invalid', function () {
        $invalidMatrix = [
            'math' => [100, -50], // -50 is not positive-int
        ];

        expect(fn () => testNestedMatrixParam($invalidMatrix))
            ->toThrow(TypeError::class)
        ;
    });

    test('throws TypeError when nested list is associative', function () {
        $invalidMatrix = [
            'math' => ['score' => 100], // Not a list
        ];

        expect(fn () => testNestedMatrixParam($invalidMatrix))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Lists of Generic Objects (list<Producer<covariant Animal>>)', function () {
    test('accepts list of producers holding Dog and Cat', function () {
        $producers = [
            new Producer(new Dog()),
            new Producer(new Cat()),
        ];

        expect(testGenericProducerListParam($producers))->toBe(2);
    });

    test('throws TypeError when producer holds non-animal object', function () {
        $producers = [
            new Producer(new Dog()),
            new Producer(new Car()), // Car is not an Animal
        ];

        expect(fn () => testGenericProducerListParam($producers))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Positional Tuple Shapes (array{0: T1, 1: T2})', function () {
    test('accepts valid positional tuple', function () {
        expect(testTupleShapeParam([10, 'alice']))->toBeTrue();
    });

    test('throws TypeError on invalid tuple element', function () {
        // First item -5 is not positive-int
        expect(fn () => testTupleShapeParam([-5, 'alice']))
            ->toThrow(TypeError::class, "['0']")
        ;

        // Second item '' is not non-empty-string
        expect(fn () => testTupleShapeParam([10, '']))
            ->toThrow(TypeError::class, "['1']")
        ;
    });
});

describe('Unsealed Array Shapes (array{id: T, ...<K, V>})', function () {
    test('accepts required keys plus additional unsealed key-value pairs', function () {
        $payload = [
            'id' => 10,
            'note' => 'extra info',
            'category' => 'admin',
        ];

        expect(testUnsealedShapeParam($payload))->toBeTrue();
    });

    test('throws TypeError when unsealed extra key/value violates unsealed type', function () {
        $payload = [
            'id' => 10,
            'invalid_extra' => 999, // int given, but string expected by unsealed type
        ];

        expect(fn () => testUnsealedShapeParam($payload))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Variadic Array Shapes (@param array{...} ...$users)', function () {
    test('accepts multiple variadic array shape arguments', function () {
        expect(testVariadicArrayShapeParam(
            ['id' => 1, 'name' => 'Alice'],
            ['id' => 2, 'name' => 'Bob']
        ))->toBe(2);
    });

    test('throws TypeError when any variadic argument violates the array shape', function () {
        expect(fn () => testVariadicArrayShapeParam(
            ['id' => 1, 'name' => 'Alice'],
            ['id' => -2, 'name' => 'Bob'] // -2 is not positive-int
        ))->toThrow(TypeError::class);
    });
});

describe('Complex Shapes with Generic Lists & Unions', function () {
    test('accepts valid payload with nested list and optional union key', function () {
        $payload = [
            'id' => 42,
            'tags' => ['php', 'testing'],
            'status' => 'active',
        ];

        expect(testComplexNestedShapeParam($payload))->toBeTrue();
    });

    test('throws TypeError on nested list constraint failure', function () {
        $payload = [
            'id' => 42,
            'tags' => ['php', ''], // Empty string violates list<non-empty-string>
        ];

        expect(fn () => testComplexNestedShapeParam($payload))
            ->toThrow(TypeError::class)
        ;
    });
});

describe('Issue #20 Edge Cases: Keyless Tuples in Type Aliases, Returns, and Mixed Keys', function () {
    test('resolves keyless tuple shapes defined inside local @phpstan-type aliases', function () {
        expect(testLocalTupleAliasParam(
            [[10, 20], ['a', 'b']],
            ['status_ok', 'code' => 200, [1, 2, 3]]
        ))->toBeTrue();

        expect(fn () => testLocalTupleAliasParam(
            [[10, -5], ['a', 'b']],
            ['status_ok', 'code' => 200, [1, 2, 3]]
        ))->toThrow(TypeError::class, "Argument \$payload['0'][1] must be of type positive-int");

        expect(fn () => testLocalTupleAliasParam(
            [[10, 20], ['a', 'b']],
            ['status_ok', 'code' => -100, [1, 2, 3]]
        ))->toThrow(TypeError::class, "Argument \$mixedPayload['code'] must be of type positive-int");
    });

    test('resolves keyless tuple shapes imported via @phpstan-import-type', function () {
        expect(testImportedTupleAliasParam([[100, 200], 'valid_string']))->toBeTrue();

        expect(fn () => testImportedTupleAliasParam([[100, 200], '']))
            ->toThrow(TypeError::class, "Argument \$tuple['1'] must be of type non-empty-string")
        ;
    });

    test('validates keyless tuple shapes returned from functions', function () {
        expect(testReturnKeylessTuple(true))->toBe([[10, 20], 'bundle']);

        expect(fn () => testReturnKeylessTuple(false))
            ->toThrow(TypeError::class, "Return value['0'][1] must be of type positive-int")
        ;
    });
});

describe('Sealed vs Unsealed Array Shapes', function () {
    describe('Sealed Shapes (array{id: int})', function () {
        test('accepts exact declared shape keys', function () {
            expect(testSealedShape(['id' => 10, 'username' => 'Alice']))->toBeTrue();
        });

        test('throws TypeError when sealed shape receives unexpected extra key', function () {
            expect(fn () => testSealedShape(['id' => 10, 'username' => 'Alice', 'extra_key' => 'bar']))
                ->toThrow(TypeError::class, "contains unsealed unexpected key 'extra_key'")
            ;
        });
    });

    describe('Unsealed Typed Shapes (array{id: int, ...<string, string>})', function () {
        test('accepts required keys plus additional string-string pairs', function () {
            $payload = [
                'id' => 42,
                'category' => 'admin_user',
                'department' => 'engineering',
            ];

            expect(testUnsealedTypedShape($payload))->toBeTrue();
        });

        test('throws TypeError when unsealed extra value violates unsealed type contract', function () {
            $payload = [
                'id' => 42,
                'code' => 999, // 999 is int, but unsealed type requires string value!
            ];

            expect(fn () => testUnsealedTypedShape($payload))
                ->toThrow(TypeError::class, "['code'] must be of type string, int (999) given")
            ;
        });
    });
});
