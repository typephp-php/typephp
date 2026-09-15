<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class FixtureKeyOfUser
{
    public function __construct(public string $name)
    {
    }
}

/**
 * 1. Basic key-of<T> on array shapes
 *
 * @param key-of<array{name: string, age: int, email: string}> $field
 */
function tddSelectField(string $field): string
{
    return $field;
}

/**
 * 2. Basic value-of<T> on array shapes
 *
 * @param value-of<array{name: string, age: int, active: bool}> $value
 */
function tddSetFieldValue(mixed $value): mixed
{
    return $value;
}

/**
 * 3. key-of<T> with literal union shapes
 *
 * @param key-of<array{gateway: 'stripe'|'paypal', currency: string}> $key
 */
function tddConfigKey(string $key): string
{
    return $key;
}

/**
 * 4. value-of<T> with literal union values
 *
 * @param value-of<array{status: 'draft'|'published'|'archived'}> $status
 */
function tddSetStatus(string $status): string
{
    return $status;
}

/**
 * 5. Generic array key-of<T> and value-of<T>
 *
 * @template T
 *
 * @param array<string, T> $map
 * @param key-of<array<string, T>> $key
 *
 * @return T|null
 */
function tddGetFromMap(array $map, string $key): mixed
{
    return $map[$key] ?? null;
}

/**
 * @template T
 *
 * @param value-of<array<string, T>> $value
 */
function tddSetGenericValue(mixed $value): mixed
{
    return $value;
}

/**
 * 6. key-of<T> as return type
 *
 * @return key-of<array{name: string, age: int}>
 */
function tddPickField(string $field): string
{
    return $field;
}

/**
 * 7. value-of<T> as return type
 *
 * @return value-of<array{id: positive-int, name: non-empty-string}>
 */
function tddPickValue(mixed $value): mixed
{
    return $value;
}

/**
 * 8. Containers holding key-of / value-of
 *
 * @param list<key-of<array{a: int, b: string}>> $keys
 */
function tddSetKeys(array $keys): array
{
    return $keys;
}

/**
 * @param array{
 *   field: key-of<array{name: string, age: int}>,
 *   value: value-of<array{name: string, age: int}>
 * } $pair
 */
function tddSetPair(array $pair): array
{
    return $pair;
}

/**
 * 9. Deep nested extraction (key-of<value-of<...>>)
 *
 * @param key-of<value-of<array{config: array{host: string, port: int}, other: string}>> $key
 */
function tddNestedKey(string $key): string
{
    return $key;
}

describe('key-of<T> and value-of<T> Shape Extractions', function () {
    describe('1. Basic key-of<T> from Array Shapes', function () {
        test('accepts valid declared keys', function () {
            expect(tddSelectField('name'))->toBe('name');
            expect(tddSelectField('age'))->toBe('age');
            expect(tddSelectField('email'))->toBe('email');
        });

        test('rejects undeclared keys and case mismatches', function () {
            expect(fn () => tddSelectField('unknown'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;

            expect(fn () => tddSelectField('Name'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });
    });

    describe('2. Basic value-of<T> from Array Shapes', function () {
        test('accepts valid values satisfying any field in the shape', function () {
            expect(tddSetFieldValue('Alice'))->toBe('Alice');
            expect(tddSetFieldValue(30))->toBe(30);
            expect(tddSetFieldValue(true))->toBeTrue();
        });

        test('rejects types not matching any field in the shape', function () {
            expect(fn () => tddSetFieldValue(3.14))
                ->toThrow(TypeError::class, 'must be a value of the specified array shape')
            ;

            expect(fn () => tddSetFieldValue(null))
                ->toThrow(TypeError::class, 'must be a value of the specified array shape')
            ;
        });
    });

    describe('3. key-of<T> with Literal Unions in Shapes', function () {
        test('accepts valid shape keys', function () {
            expect(tddConfigKey('gateway'))->toBe('gateway');
            expect(tddConfigKey('currency'))->toBe('currency');
        });

        test('rejects unknown key', function () {
            expect(fn () => tddConfigKey('other'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });
    });

    describe('4. value-of<T> with Literal Unions in Shapes', function () {
        test('accepts declared literal union values', function () {
            expect(tddSetStatus('draft'))->toBe('draft');
            expect(tddSetStatus('published'))->toBe('published');
            expect(tddSetStatus('archived'))->toBe('archived');
        });

        test('rejects value not present in the union', function () {
            expect(fn () => tddSetStatus('deleted'))
                ->toThrow(TypeError::class, 'must be a value of the specified array shape')
            ;
        });
    });

    describe('5. key-of<T> and value-of<T> with Generic Arrays', function () {
        test('allows string keys for key-of<array<string, T>>', function () {
            $user = new FixtureKeyOfUser('Alice');
            expect(tddGetFromMap(['a' => $user], 'a'))->toBe($user);
            expect(tddGetFromMap(['a' => $user], 'b'))->toBeNull();
        });

        test('allows generic values for value-of<array<string, T>>', function () {
            expect(tddSetGenericValue('hello'))->toBe('hello');
            expect(tddSetGenericValue(3.14))->toBe(3.14);
        });
    });

    describe('6. key-of<T> and value-of<T> as Return Types', function () {
        test('validates key-of return types', function () {
            expect(tddPickField('name'))->toBe('name');

            expect(fn () => tddPickField('unknown'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });

        test('validates value-of return types', function () {
            expect(tddPickValue('Alice'))->toBe('Alice');

            expect(fn () => tddPickValue(''))
                ->toThrow(TypeError::class, 'must be a value of the specified array shape')
            ;
        });
    });

    describe('7. key-of and value-of inside Containers (Lists & Shapes)', function () {
        test('validates list<key-of<...>>', function () {
            expect(tddSetKeys(['a', 'b']))->toBe(['a', 'b']);

            expect(fn () => tddSetKeys(['a', 'c']))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });

        test('validates shapes combining key-of and value-of', function () {
            expect(tddSetPair(['field' => 'name', 'value' => 'Alice']))->toBe(['field' => 'name', 'value' => 'Alice']);

            expect(fn () => tddSetPair(['field' => 'unknown', 'value' => 'Alice']))
                ->toThrow(TypeError::class, "['field'] must be a key of the specified array shape")
            ;

            expect(fn () => tddSetPair(['field' => 'name', 'value' => 3.14]))
                ->toThrow(TypeError::class, "['value'] must be a value of the specified array shape")
            ;
        });
    });

    describe('8. Deep Nested Extractions (key-of<value-of<...>>)', function () {
        test('extracts keys from nested inner array shapes', function () {
            expect(tddNestedKey('host'))->toBe('host');
            expect(tddNestedKey('port'))->toBe('port');
        });

        test('rejects outer keys not belonging to inner value shapes', function () {
            expect(fn () => tddNestedKey('other'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;

            expect(fn () => tddNestedKey('config'))
                ->toThrow(TypeError::class, 'must be a key of the specified array shape')
            ;
        });
    });
});
