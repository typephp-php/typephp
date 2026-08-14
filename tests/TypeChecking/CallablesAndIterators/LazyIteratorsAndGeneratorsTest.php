<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Services\NestedAggregateService;

/**
 * 1. Generator Parameter Contracts (@param Generator<string, positive-int>)
 *
 * @param Generator<string, positive-int> $gen
 */
function testGeneratorParamContract(Generator $gen): array
{
    $result = [];
    foreach ($gen as $k => $v) {
        $result[$k] = $v;
    }

    return $result;
}

/**
 * 2. Non-Array Traversable / Iterator Parameter Contracts
 *
 * @param Traversable<non-empty-string, positive-int> $items
 */
function testTraversableParamContract(Traversable $items): array
{
    $out = [];
    foreach ($items as $k => $v) {
        $out[$k] = $v;
    }

    return $out;
}

/**
 * 3. Multiple Iterations on Traversable Parameter (Rewindability)
 *
 * @param Traversable<string, positive-int> $items
 */
function testMultipleIterationTraversableParam(Traversable $items): int
{
    $count = 0;
    foreach ($items as $k => $v) {
        $count++;
    }
    foreach ($items as $k => $v) {
        $count++;
    }

    return $count;
}

/**
 * 4. Countable & Method Forwarding on Traversable Parameter
 *
 * @param Traversable<string, positive-int> $items
 */
function testCountableTraversableParam(Traversable $items): int
{
    if ($items instanceof Countable) {
        return $items->count();
    }

    return 0;
}

/**
 * 5. Generator Return Contracts
 *
 * @return Generator<non-empty-string, positive-int, positive-int, void>
 */
function testGeneratorReturnContract(bool $yieldBadValue = false, bool $yieldBadKey = false): Generator
{
    if ($yieldBadValue) {
        yield 'a' => 10;
        yield 'b' => -99; // Invalid value
    } elseif ($yieldBadKey) {
        yield '' => 10; // Invalid key
    } else {
        $receivedValue = yield 'a' => 10;
        yield 'b' => 20;
    }
}

/**
 * 6. Delegated yield from with Array
 *
 * @return Generator<string, positive-int>
 */
function testYieldFromArrayGenerator(bool $bad = false): Generator
{
    yield 'start' => 1;
    yield from ($bad ? ['a' => 10, 'b' => -50] : ['a' => 10, 'b' => 20]);
    yield 'end' => 99;
}

/**
 * 7. Delegated yield from with Nested Generator
 *
 * @return Generator<non-empty-string, positive-int>
 */
function testYieldFromChildGenerator(bool $bad = false): Generator
{
    $child = function () use ($bad): Generator {
        yield 'child_1' => 100;
        yield ($bad ? '' : 'child_2') => 200; // Empty string violates non-empty-string key!
    };

    yield from $child();
}

/**
 * 8. Complex Array Shapes in $gen->send() (TSend)
 *
 * @return Generator<int, array{id: positive-int, name: non-empty-string}, array{action: 'approve'|'reject'}, void>
 */
function testComplexShapeGenerator(): Generator
{
    $input = yield 1 => ['id' => 10, 'name' => 'Alice'];
    yield 2 => ['id' => 20, 'name' => 'Bob']; // Valid TValue array shape!
}

/**
 * 9. Traversable Return Contracts
 *
 * @return Traversable<non-empty-string, positive-int>
 */
function testTraversableReturnContract(array $data): Traversable
{
    return new ArrayIterator($data);
}

describe('Lazy Generator Parameter Contracts (@param Generator<K, V>)', function () {
    test('iterates valid generator yielding string keys and positive-int values', function () {
        $validGen = function (): Generator {
            yield 'x' => 100;
            yield 'y' => 200;
        };

        expect(testGeneratorParamContract($validGen()))->toBe(['x' => 100, 'y' => 200]);
    });

    test('throws TypeError lazily during iteration when generator yields bad value', function () {
        $badValueGen = function (): Generator {
            yield 'x' => 100;
            yield 'y' => -50;
        };

        $gen = $badValueGen();

        expect(fn () => testGeneratorParamContract($gen))
            ->toThrow(TypeError::class, 'Iterator $gen value')
        ;
    });

    test('throws TypeError lazily during iteration when generator yields bad key', function () {
        $badKeyGen = function (): Generator {
            yield 123 => 100;
        };

        $gen = $badKeyGen();

        expect(fn () => testGeneratorParamContract($gen))
            ->toThrow(TypeError::class, 'Iterator $gen key')
        ;
    });
});

describe('Delegated Generators (yield from)', function () {
    test('lazily validates items yielded from delegated array', function () {
        $gen = testYieldFromArrayGenerator(true);

        expect(function () use ($gen) {
            foreach ($gen as $k => $v) {
                // Iteration throws on delegated 'b' => -50
            }
        })->toThrow(TypeError::class, 'Return iterator value');
    });

    test('lazily validates items yielded from delegated child generator', function () {
        $gen = testYieldFromChildGenerator(true);

        expect(function () use ($gen) {
            foreach ($gen as $k => $v) {
                // Iteration throws on child empty string key
            }
        })->toThrow(TypeError::class, 'Return iterator key');
    });
});

describe('Complex Array Shapes in Generator TSend Input', function () {
    test('accepts valid shape sent into generator via $gen->send()', function () {
        $gen = testComplexShapeGenerator();
        $firstItem = $gen->current();

        expect($firstItem)->toBe(['id' => 10, 'name' => 'Alice']);

        $gen->send(['action' => 'approve']); // Valid TSend shape
        expect($gen->valid())->toBeTrue()
            ->and($gen->current())->toBe(['id' => 20, 'name' => 'Bob'])
        ;
    });

    test('throws TypeError when $gen->send() receives value violating TSend shape', function () {
        $gen = testComplexShapeGenerator();
        $gen->current();

        expect(fn () => $gen->send(['action' => 'delete'])) // 'delete' violates 'approve'|'reject'
            ->toThrow(TypeError::class, "Generator sent value (TSend)['action'] must be of type ('approve' | 'reject')")
        ;
    });
});

describe('Multi-Level IteratorAggregate Unwrapping & Method Forwarding', function () {
    test('unwraps nested IteratorAggregates and preserves method and count forwarding on proxy', function () {
        $nestedService = new NestedAggregateService(['item1' => 10, 'item2' => 20]);

        expect(testTraversableParamContract($nestedService))->toBe(['item1' => 10, 'item2' => 20]);
        expect(testCountableTraversableParam($nestedService))->toBe(2);
    });
});

describe('Traversable Rewindability & Return Contracts', function () {
    test('allows multiple foreach iterations over wrapped Traversable parameter', function () {
        $iterator = new ArrayIterator(['a' => 10, 'b' => 20]);
        expect(testMultipleIterationTraversableParam($iterator))->toBe(4);
    });

    test('throws TypeError lazily when returned ArrayIterator yields invalid element', function () {
        $badIterator = testTraversableReturnContract(['item1' => 10, 'item2' => -99]);

        expect(function () use ($badIterator) {
            foreach ($badIterator as $k => $v) {
                // Iteration throws on 'item2' => -99
            }
        })->toThrow(TypeError::class, 'Return iterator value');
    });
});

describe('Generator Input Validation ($gen->send() TSend Contract)', function () {
    test('accepts valid TSend value sent into generator', function () {
        $gen = testGeneratorReturnContract(false, false);
        $gen->current();
        $gen->send(100);

        expect($gen->valid())->toBeTrue();
    });

    test('throws TypeError when $gen->send() receives value violating TSend contract', function () {
        $gen = testGeneratorReturnContract(false, false);
        $gen->current();

        expect(fn () => $gen->send(-500))
            ->toThrow(TypeError::class, 'Generator sent value (TSend)')
        ;
    });
});
