<?php

declare(strict_types=1);

/**
 * @template T of positive-int
 *
 * @param T $value
 *
 * @return T
 */
function testPositiveIntBound(mixed $value): mixed
{
    return $value;
}

/**
 * @template T of non-empty-string
 *
 * @param T $text
 *
 * @return T
 */
function testNonEmptyStringBound(mixed $text): mixed
{
    return $text;
}

/**
 * @template T of array{id: positive-int, role: 'admin'|'user'}
 *
 * @param T $data
 *
 * @return T
 */
function testArrayShapeBound(mixed $data): mixed
{
    return $data;
}

/**
 * @template T of list<positive-int>
 *
 * @param T $items
 *
 * @return T
 */
function testListBound(mixed $items): mixed
{
    return $items;
}

/**
 * @template T of int<1, 100>
 *
 * @param T $percentage
 *
 * @return T
 */
function testIntRangeBound(mixed $percentage): mixed
{
    return $percentage;
}

/**
 * @template T of 'active'|'pending'
 *
 * @param T $status
 *
 * @return T
 */
function testLiteralUnionBound(mixed $status): mixed
{
    return $status;
}

/**
 * @template T of Countable
 *
 * @param class-string<T> $class
 */
function testClassStringInterfaceBound(string $class): bool
{
    return true;
}

/**
 * Default template with object upper bound (@template T of object = stdClass)
 *
 * @template T of object = stdClass
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultObjectBound(mixed $value): mixed
{
    return $value;
}

/**
 * Default template with int-range upper bound (@template T of int<1, 100> = 50)
 *
 * @template T of int<1, 100> = 50
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultIntRangeBound(mixed $value): mixed
{
    return $value;
}

/**
 * Template where T is inferred from $input, overriding default stdClass
 *
 * @template T of object = stdClass
 *
 * @param T $input
 * @param mixed $valueToReturn
 *
 * @return T
 */
function testInferredOverridesDefault(mixed $input, mixed $valueToReturn): mixed
{
    return $valueToReturn;
}

describe('Generic Template Bounds Stress Test', function () {
    test('validates positive-int scalar bound', function () {
        expect(testPositiveIntBound(42))->toBe(42);

        expect(fn () => testPositiveIntBound(-10))
            ->toThrow(TypeError::class, 'positive-int')
        ;
        expect(fn () => testPositiveIntBound(0))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('validates non-empty-string scalar bound', function () {
        expect(testNonEmptyStringBound('hello'))->toBe('hello');

        expect(fn () => testNonEmptyStringBound(''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('validates array shape template bound', function () {
        $valid = ['id' => 10, 'role' => 'admin'];
        expect(testArrayShapeBound($valid))->toBe($valid);
        expect(fn () => testArrayShapeBound(['id' => -5, 'role' => 'admin']))
            ->toThrow(TypeError::class, "['id']")
        ;

        expect(fn () => testArrayShapeBound(['id' => 10, 'role' => 'superadmin']))
            ->toThrow(TypeError::class, "['role']")
        ;

        expect(fn () => testArrayShapeBound(['id' => 10]))
            ->toThrow(TypeError::class, "missing required key 'role'")
        ;
    });

    test('validates list template bound', function () {
        expect(testListBound([10, 20, 30]))->toBe([10, 20, 30]);

        expect(fn () => testListBound([10, -5, 30]))
            ->toThrow(TypeError::class, '[1]')
        ;

        expect(fn () => testListBound(['key' => 10]))
            ->toThrow(TypeError::class, 'must be a list')
        ;
    });

    test('validates int range template bound', function () {
        expect(testIntRangeBound(50))->toBe(50);

        expect(fn () => testIntRangeBound(150))
            ->toThrow(TypeError::class, '<= 100')
        ;
        expect(fn () => testIntRangeBound(0))
            ->toThrow(TypeError::class, '>= 1')
        ;
    });

    test('validates literal union enum template bound', function () {
        expect(testLiteralUnionBound('active'))->toBe('active');
        expect(testLiteralUnionBound('pending'))->toBe('pending');

        expect(fn () => testLiteralUnionBound('archived'))
            ->toThrow(TypeError::class, "('active' | 'pending')")
        ;
    });

    test('validates class-string<T of Countable> interface bound', function () {
        expect(testClassStringInterfaceBound(ArrayObject::class))->toBeTrue();

        expect(fn () => testClassStringInterfaceBound(stdClass::class))
            ->toThrow(TypeError::class, 'must be a class-string of Countable')
        ;
    });

    test('uses default template type when template T is unbound', function () {
        $std = new stdClass();
        expect(testDefaultObjectBound($std))->toBe($std);

        expect(fn () => testDefaultObjectBound(new DateTime()))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('uses default scalar literal type when template T is unbound', function () {
        expect(testDefaultIntRangeBound(50))->toBe(50);

        expect(fn () => testDefaultIntRangeBound(99))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('inferred template parameter from argument overrides default template type', function () {
        $dt = new DateTime();

        expect(testInferredOverridesDefault($dt, $dt))->toBe($dt);

        expect(fn () => testInferredOverridesDefault($dt, new stdClass()))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});
