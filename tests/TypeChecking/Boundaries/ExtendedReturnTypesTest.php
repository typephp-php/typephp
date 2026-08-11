<?php

declare(strict_types=1);

/**
 * 1. Extended Scalar Return Types
 *
 * @return positive-int
 */
function testReturnPositiveInt(int $val): int
{
    return $val;
}

/**
 * @return non-empty-string
 */
function testReturnNonEmptyString(string $val): string
{
    return $val;
}

/**
 * @return numeric-string
 */
function testReturnNumericString(string $val): string
{
    return $val;
}

/**
 * 2. List and Array Return Types
 *
 * @return list<positive-int>
 */
function testReturnPositiveIntList(array $items): array
{
    return $items;
}

/**
 * @return non-empty-list<string>
 */
function testReturnNonEmptyList(array $items): array
{
    return $items;
}

/**
 * 3. Positional Tuple Return Types
 *
 * @return array{0: positive-int, 1: non-empty-string}
 */
function testReturnTupleShape(array $tuple): array
{
    return $tuple;
}

/**
 * 4. Generic Template List Return Types
 *
 * @template T
 *
 * @param T $sampleItem
 * @param mixed $listToReturn
 *
 * @return list<T>
 */
function testReturnTemplateList(mixed $sampleItem, mixed $listToReturn): array
{
    return $listToReturn;
}

/**
 * 5. Literal Union Enum Return Types
 *
 * @return 'active'|'pending'|'closed'
 */
function testReturnLiteralUnion(string $status): string
{
    return $status;
}

describe('Extended Scalar Return Types', function () {
    test('accepts valid extended scalar returns', function () {
        expect(testReturnPositiveInt(42))->toBe(42);
        expect(testReturnNonEmptyString('hello'))->toBe('hello');
        expect(testReturnNumericString('123.45'))->toBe('123.45');
    });

    test('throws TypeError when return value violates positive-int contract', function () {
        expect(fn () => testReturnPositiveInt(-10))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('throws TypeError when return value violates non-empty-string contract', function () {
        expect(fn () => testReturnNonEmptyString(''))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('throws TypeError when return value violates numeric-string contract', function () {
        expect(fn () => testReturnNumericString('not_numeric'))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('List and Array Return Types', function () {
    test('accepts valid list and non-empty list returns', function () {
        expect(testReturnPositiveIntList([10, 20, 30]))->toBe([10, 20, 30]);
        expect(testReturnNonEmptyList(['a', 'b']))->toBe(['a', 'b']);
    });

    test('throws TypeError when returned list contains invalid element', function () {
        // -5 is not positive-int
        expect(fn () => testReturnPositiveIntList([10, -5, 30]))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('throws TypeError when non-empty-list return is an empty array', function () {
        expect(fn () => testReturnNonEmptyList([]))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Positional Tuple Return Types', function () {
    test('accepts valid tuple return shape', function () {
        expect(testReturnTupleShape([100, 'success']))->toBe([100, 'success']);
    });

    test('throws TypeError when tuple return element is invalid', function () {
        // First element -5 is not positive-int
        expect(fn () => testReturnTupleShape([-5, 'success']))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Generic Template List Return Types', function () {
    test('accepts list of items matching inferred template type T', function () {
        expect(testReturnTemplateList(10, [20, 30, 40]))->toBe([20, 30, 40]);
    });

    test('throws TypeError when returned list contains item violating inferred T', function () {
        // T is inferred as int from 1st arg (10), list contains string 'invalid'
        expect(fn () => testReturnTemplateList(10, [20, 'invalid']))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Literal Union Enum Return Types', function () {
    test('accepts valid status literal return', function () {
        expect(testReturnLiteralUnion('active'))->toBe('active');
        expect(testReturnLiteralUnion('pending'))->toBe('pending');
    });

    test('throws TypeError when returned status is not in allowed union', function () {
        expect(fn () => testReturnLiteralUnion('archived'))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});
