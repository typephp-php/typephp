<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

/**
 * 1. Conditional inside Array Shape Return
 *
 * @template T
 *
 * @param T $v
 * @param mixed $out
 *
 * @return array{out: (T is int ? positive-int : T)}
 */
function testConditionalInArrayShapeReturn(mixed $v, mixed $out): array
{
    return ['out' => $out];
}

/**
 * Conditional inside Array Shape Parameter
 *
 * @template T
 *
 * @param T $v
 * @param array{data: (T is int ? positive-int : T)} $payload
 */
function testConditionalInArrayShapeParam(mixed $v, array $payload): bool
{
    return true;
}

/**
 * 2. Conditional inside Generic List Return
 *
 * @template T
 *
 * @param T $v
 * @param array<int, mixed> $items
 *
 * @return list<(T is int ? positive-int : T)>
 */
function testConditionalInGenericListReturn(mixed $v, array $items): array
{
    return $items;
}

/**
 * Conditional inside Generic List Parameter
 *
 * @template T
 *
 * @param T $v
 * @param list<(T is int ? positive-int : T)> $items
 */
function testConditionalInGenericListParam(mixed $v, array $items): bool
{
    return true;
}

/**
 * 3. Conditional inside Union Return
 *
 * @template T
 *
 * @param T $v
 * @param mixed $result
 *
 * @return (T is int ? positive-int : T)|null
 */
function testConditionalInUnionReturn(mixed $v, mixed $result): mixed
{
    return $result;
}

/**
 * 4. Conditional inside Nullable Return
 *
 * @template T
 *
 * @param T $v
 * @param mixed $result
 *
 * @return ?(T is int ? positive-int : T)
 */
function testConditionalInNullableReturn(mixed $v, mixed $result): mixed
{
    return $result;
}

/**
 * 5. Conditional inside Callable Return Type
 *
 * @template T
 *
 * @param T $v
 * @param mixed $closureReturn
 *
 * @return callable(): (T is int ? positive-int : T)
 */
function testConditionalInCallableReturn(mixed $v, mixed $closureReturn): callable
{
    return fn () => $closureReturn;
}

/**
 * Conditional inside Callable Parameter Type
 *
 * @template T
 *
 * @param T $v
 * @param callable((T is int ? positive-int : T)): bool $callback
 * @param mixed $arg
 */
function testConditionalInCallableParam(mixed $v, callable $callback, mixed $arg): bool
{
    return $callback($arg);
}

/**
 * 6. Conditional inside Object Shape (stdClass{...})
 *
 * @template T
 *
 * @param T $v
 * @param mixed $out
 *
 * @return stdClass{out: (T is int ? positive-int : T)}
 */
function testConditionalInObjectShapeReturn(mixed $v, mixed $out): stdClass
{
    $obj = new stdClass();
    $obj->out = $out;

    return $obj;
}

/**
 * Parameter-based conditional inside an Array Shape return
 *
 * @param bool $asInt
 * @param mixed $out
 *
 * @return array{result: ($asInt is true ? positive-int : non-empty-string)}
 */
function testParamConditionalInShapeReturn(bool $asInt, mixed $out): array
{
    return ['result' => $out];
}

/**
 * Parameter-based conditional inside a Generic List parameter
 *
 * @param string $format
 * @param list<($format is 'int' ? positive-int : non-empty-string)> $items
 */
function testParamConditionalInListParam(string $format, array $items): bool
{
    return true;
}

class NestedConditionalServiceFixture
{
    /**
     * @template T
     *
     * @param T $v
     * @param mixed $out
     *
     * @return array{out: (T is int ? positive-int : T)}
     */
    public function transformShape(mixed $v, mixed $out): array
    {
        return ['out' => $out];
    }

    /**
     * @template T
     *
     * @param T $v
     * @param array<int, mixed> $items
     *
     * @return list<(T is string ? non-empty-string : positive-int)>
     */
    public function transformList(mixed $v, array $items): array
    {
        return $items;
    }
}

describe('Nested Conditional Types Inside Compound Structures', function () {
    describe('1. Conditionals Inside Array Shapes (array{key: (T is Target ? A : B)})', function () {
        test('evaluates conditional inside array shape return when T is int (positive-int)', function () {
            $valid = testConditionalInArrayShapeReturn(5, 42);
            expect($valid)->toBe(['out' => 42]);

            expect(fn () => testConditionalInArrayShapeReturn(5, -5))
                ->toThrow(TypeError::class, "['out'] must be of type positive-int")
            ;
        });

        test('evaluates fallback branch inside array shape return when T is string (T)', function () {
            $valid = testConditionalInArrayShapeReturn('hello', 'world');
            expect($valid)->toBe(['out' => 'world']);

            expect(fn () => testConditionalInArrayShapeReturn('hello', 12345))
                ->toThrow(TypeError::class, "['out'] must be of type string")
            ;
        });

        test('evaluates conditional inside array shape parameter', function () {
            expect(testConditionalInArrayShapeParam(10, ['data' => 100]))->toBeTrue();

            expect(fn () => testConditionalInArrayShapeParam(10, ['data' => -50]))
                ->toThrow(TypeError::class, "['data'] must be of type positive-int")
            ;
        });
    });

    describe('2. Conditionals Inside Generic Lists (list<(T is Target ? A : B)>)', function () {
        test('evaluates conditional inside generic list return when T is int', function () {
            $valid = testConditionalInGenericListReturn(5, [1, 2, 3]);
            expect($valid)->toBe([1, 2, 3]);

            expect(fn () => testConditionalInGenericListReturn(5, [1, -5, 3]))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('evaluates fallback branch inside generic list return when T is string', function () {
            $valid = testConditionalInGenericListReturn('tag', ['alpha', 'beta']);
            expect($valid)->toBe(['alpha', 'beta']);

            expect(fn () => testConditionalInGenericListReturn('tag', ['alpha', 123]))
                ->toThrow(TypeError::class, 'string')
            ;
        });

        test('evaluates conditional inside generic list parameter', function () {
            expect(testConditionalInGenericListParam(10, [10, 20, 30]))->toBeTrue();

            expect(fn () => testConditionalInGenericListParam(10, [10, -5, 30]))
                ->toThrow(TypeError::class, '[1] must be of type positive-int')
            ;
        });
    });

    describe('3. Conditionals Inside Unions and Nullables ((Cond)|null & ?Cond)', function () {
        test('evaluates conditional inside union return and allows null', function () {
            expect(testConditionalInUnionReturn(5, null))->toBeNull();
            expect(testConditionalInUnionReturn(5, 42))->toBe(42);

            expect(fn () => testConditionalInUnionReturn(5, -5))
                ->toThrow(TypeError::class, 'must be of type (positive-int | null)')
            ;
        });

        test('evaluates conditional inside nullable return and allows null', function () {
            expect(testConditionalInNullableReturn(5, null))->toBeNull();
            expect(testConditionalInNullableReturn(5, 100))->toBe(100);

            expect(fn () => testConditionalInNullableReturn(5, -5))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });

    describe('4. Conditionals Inside Callables (callable(): Cond & callable(Cond): bool)', function () {
        test('evaluates conditional in callable return and validates callback invocation', function () {
            $validCb = testConditionalInCallableReturn(5, 42);
            expect($validCb())->toBe(42);

            $invalidCb = testConditionalInCallableReturn(5, -5);
            expect(fn () => $invalidCb())
                ->toThrow(TypeError::class, 'return value must be of type positive-int')
            ;
        });

        test('evaluates conditional in callable parameter and validates callback argument', function () {
            $cb = fn (int $x): bool => $x > 0;

            expect(testConditionalInCallableParam(5, $cb, 10))->toBeTrue();

            expect(fn () => testConditionalInCallableParam(5, $cb, -50))
                ->toThrow(TypeError::class, 'must be of type positive-int')
            ;
        });
    });

    describe('5. Conditionals Inside Object Shapes (stdClass{out: Cond})', function () {
        test('evaluates conditional inside object shape return', function () {
            $valid = testConditionalInObjectShapeReturn(5, 42);
            expect($valid->out)->toBe(42);

            expect(fn () => testConditionalInObjectShapeReturn(5, -5))
                ->toThrow(TypeError::class, '->out must be of type positive-int')
            ;
        });
    });

    describe('6. Parameter-Based Conditionals in Compounds ($param is Target ? A : B)', function () {
        test('evaluates parameter conditional inside array shape return ($asInt is true)', function () {
            $resInt = testParamConditionalInShapeReturn(true, 100);
            expect($resInt)->toBe(['result' => 100]);

            expect(fn () => testParamConditionalInShapeReturn(true, -50))
                ->toThrow(TypeError::class, "['result'] must be of type positive-int")
            ;

            $resStr = testParamConditionalInShapeReturn(false, 'active_status');
            expect($resStr)->toBe(['result' => 'active_status']);

            expect(fn () => testParamConditionalInShapeReturn(false, ''))
                ->toThrow(TypeError::class, "['result'] must be of type non-empty-string")
            ;
        });

        test('evaluates parameter conditional inside generic list parameter', function () {
            expect(testParamConditionalInListParam('int', [10, 20]))->toBeTrue();

            expect(fn () => testParamConditionalInListParam('int', [10, -5]))
                ->toThrow(TypeError::class, '[1] must be of type positive-int')
            ;

            expect(testParamConditionalInListParam('str', ['a', 'b']))->toBeTrue();

            expect(fn () => testParamConditionalInListParam('str', ['a', '']))
                ->toThrow(TypeError::class, '[1] must be of type non-empty-string')
            ;
        });
    });

    describe('7. Class Methods with Nested Conditionals', function () {
        test('evaluates nested conditional shape in class methods', function () {
            $service = new NestedConditionalServiceFixture();

            expect($service->transformShape(10, 42))->toBe(['out' => 42]);

            expect(fn () => $service->transformShape(10, -5))
                ->toThrow(TypeError::class, "['out'] must be of type positive-int")
            ;
        });

        test('evaluates nested conditional list in class methods', function () {
            $service = new NestedConditionalServiceFixture();

            expect($service->transformList('sample', ['valid_a', 'valid_b']))->toBe(['valid_a', 'valid_b']);

            expect(fn () => $service->transformList('sample', ['valid_a', '']))
                ->toThrow(TypeError::class, '[1] must be of type non-empty-string');
        });
    });
});
