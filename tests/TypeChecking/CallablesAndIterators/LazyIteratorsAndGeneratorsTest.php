<?php

declare(strict_types=1);

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
 * 5. Generator Return Contracts (@return Generator<non-empty-string, positive-int, positive-int, void>)
 *
 * @return Generator<non-empty-string, positive-int, positive-int, void>
 */
function testGeneratorReturnContract(bool $yieldBadValue = false, bool $yieldBadKey = false): Generator
{
    if ($yieldBadValue) {
        yield 'a' => 10;
        yield 'b' => -99; // Invalid value (-99 is not positive-int)
    } elseif ($yieldBadKey) {
        yield '' => 10; // Invalid key (empty string)
    } else {
        $receivedValue = yield 'a' => 10;
        yield 'b' => 20;
    }
}

/**
 * 6. Traversable / Iterator Return Contracts (@return Traversable<non-empty-string, positive-int>)
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
            yield 'y' => -50; // Invalid positive-int
        };

        $gen = $badValueGen();

        expect(fn () => testGeneratorParamContract($gen))
            ->toThrow(TypeError::class, 'Iterator $gen value')
        ;
    });

    test('throws TypeError lazily during iteration when generator yields bad key', function () {
        $badKeyGen = function (): Generator {
            yield 123 => 100; // Int key instead of string
        };

        $gen = $badKeyGen();

        expect(fn () => testGeneratorParamContract($gen))
            ->toThrow(TypeError::class, 'Iterator $gen key')
        ;
    });
});

describe('Lazy Non-Array Traversable Parameter Contracts (@param Traversable<K, V>)', function () {
    test('iterates valid ArrayIterator with non-empty-string keys and positive-int values', function () {
        $iterator = new ArrayIterator([
            'item1' => 10,
            'item2' => 20,
        ]);

        expect(testTraversableParamContract($iterator))->toBe(['item1' => 10, 'item2' => 20]);
    });

    test('throws TypeError lazily during iteration when ArrayIterator has bad value', function () {
        $badIterator = new ArrayIterator([
            'item1' => 10,
            'item2' => -50, // -50 is not positive-int
        ]);

        expect(fn () => testTraversableParamContract($badIterator))
            ->toThrow(TypeError::class, 'Iterator $items value')
        ;
    });

    test('throws TypeError lazily during iteration when ArrayIterator has bad key', function () {
        $badKeyIterator = new ArrayIterator([
            '' => 10, // Empty string key
        ]);

        expect(fn () => testTraversableParamContract($badKeyIterator))
            ->toThrow(TypeError::class, 'Iterator $items key')
        ;
    });
});

describe('Traversable Rewindability & Method Forwarding (IteratorProxy)', function () {
    test('allows multiple foreach iterations over wrapped Traversable parameter', function () {
        $iterator = new ArrayIterator(['a' => 10, 'b' => 20]);
        expect(testMultipleIterationTraversableParam($iterator))->toBe(4);
    });

    test('forwards Countable interface and custom method calls to inner iterator', function () {
        $arrayIterator = new ArrayIterator(['a' => 10, 'b' => 20]);
        expect(testCountableTraversableParam($arrayIterator))->toBe(2);
    });
});

describe('Lazy Generator & Traversable Return Contracts', function () {
    test('iterates valid generator return cleanly', function () {
        $result = [];
        foreach (testGeneratorReturnContract(false, false) as $k => $v) {
            $result[$k] = $v;
        }

        expect($result)->toBe(['a' => 10, 'b' => 20]);
    });

    test('throws TypeError lazily when returned generator yields invalid value', function () {
        $gen = testGeneratorReturnContract(true, false);

        expect(function () use ($gen) {
            foreach ($gen as $k => $v) {
                // Iteration throws when yielding 'b' => -99
            }
        })->toThrow(TypeError::class, 'Return iterator value');
    });

    test('throws TypeError lazily when returned generator yields invalid key', function () {
        $gen = testGeneratorReturnContract(false, true);

        expect(function () use ($gen) {
            foreach ($gen as $k => $v) {
                // Iteration throws when yielding '' => 10
            }
        })->toThrow(TypeError::class, 'Return iterator key');
    });

    test('iterates valid ArrayIterator return cleanly', function () {
        $iterator = testTraversableReturnContract(['item1' => 10, 'item2' => 20]);
        $out = [];
        foreach ($iterator as $k => $v) {
            $out[$k] = $v;
        }

        expect($out)->toBe(['item1' => 10, 'item2' => 20]);
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
        $gen->current(); // Reaches first yield
        $gen->send(100); // 100 is positive-int (valid TSend)

        expect($gen->valid())->toBeTrue();
    });

    test('throws TypeError when $gen->send() receives value violating TSend contract', function () {
        $gen = testGeneratorReturnContract(false, false);
        $gen->current(); // Reaches first yield

        // -500 violates positive-int TSend contract
        expect(fn () => $gen->send(-500))
            ->toThrow(TypeError::class, 'Generator sent value (TSend)')
        ;
    });
});
