<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Iterators\GenericStreamService;

/**
 * Generator with valid TReturn
 *
 * @param list<string> $items
 *
 * @return Generator<int, string, mixed, bool>
 */
function testGeneratorWithValidReturn(array $items): Generator
{
    foreach ($items as $i => $item) {
        yield $i => $item;
    }

    return true;
}

/**
 * Generator with invalid TReturn
 *
 * @param list<string> $items
 *
 * @return Generator<int, string, mixed, bool>
 */
function testGeneratorWithInvalidReturn(array $items): Generator
{
    foreach ($items as $i => $item) {
        yield $i => $item;
    }

    return 12345; // Violates bool!
}

/**
 * Generator declared to return void but returns a value
 *
 * @return Generator<int, string, mixed, void>
 */
function testGeneratorWithInvalidVoidReturn(): Generator
{
    yield 0 => 'a';

    return 'not_void';
}

class D2GeneratorFixture
{
    /**
     * @return Generator<int, int, void, void>
     */
    public function run(): Generator
    {
        yield 1;

        return;
    }
}

/**
 * Top-level conditional generator return with default template T = int
 *
 * @template T = int
 *
 * @param T|null $sample
 * @param mixed $yieldValue
 *
 * @return (T is int ? Generator<int, positive-int> : Generator<int, non-empty-string>)
 */
function testConditionalGeneratorReturn(mixed $sample = null, mixed $yieldValue = null): Generator
{
    yield 0 => $yieldValue;
}

/**
 * Nested conditional element inside Generator<K, V> return
 *
 * @template T = int
 *
 * @param T|null $sample
 * @param mixed $yieldValue
 *
 * @return Generator<int, (T is int ? positive-int : non-empty-string)>
 */
function testNestedConditionalGeneratorReturn(mixed $sample = null, mixed $yieldValue = null): Generator
{
    yield 0 => $yieldValue;
}

/**
 * Conditional iterable parameter with Traversable lazy wrapping
 *
 * @template T = int
 *
 * @param iterable<int, (T is int ? positive-int : non-empty-string)> $stream
 * @param T|null $sample
 *
 * @return list<mixed>
 */
function testConditionalIterableParam(iterable $stream, mixed $sample = null): array
{
    $collected = [];
    foreach ($stream as $item) {
        $collected[] = $item;
    }

    return $collected;
}

describe('Generic Iterables & Generators (@template T with iterable<T> and Generator<K, V>)', function () {
    describe('Generic Iterable Parameters (iterable<T>)', function () {
        test('collects items from iterable matching inferred template T = int', function () {
            $service = new GenericStreamService();
            $iterator = new ArrayIterator([10, 20, 30]);

            $result = $service->collectStream($iterator, 1);
            expect($result)->toBe([10, 20, 30]);
        });

        test('collects items from iterable matching inferred template T = string', function () {
            $service = new GenericStreamService();
            $iterator = new ArrayIterator(['alpha', 'beta', 'gamma']);

            $result = $service->collectStream($iterator, 'sample');
            expect($result)->toBe(['alpha', 'beta', 'gamma']);
        });

        test('throws TypeError lazily when iterable yields item violating inferred template T = int', function () {
            $service = new GenericStreamService();
            $badIterator = new ArrayIterator([10, 'not_an_int', 30]);

            expect(function () use ($service, $badIterator) {
                $service->collectStream($badIterator, 1);
            })->toThrow(TypeError::class, 'must be of type int');
        });
    });

    describe('Generic Traversables with Class Bounds (Traversable<string, T of Animal>)', function () {
        test('collects items from traversable with string keys and Animal instances', function () {
            $service = new GenericStreamService();
            $iterator = new ArrayIterator([
                'dog1' => new Dog(),
                'dog2' => new Dog(),
            ]);

            $result = $service->collectAnimalStream($iterator);
            expect($result)->toHaveCount(2)
                ->and($result[0])->toBeInstanceOf(Dog::class)
            ;
        });

        test('throws TypeError lazily when traversable yields non-string key', function () {
            $service = new GenericStreamService();
            $badKeyIterator = new ArrayIterator([
                123 => new Dog(),
            ]);

            expect(function () use ($service, $badKeyIterator) {
                $service->collectAnimalStream($badKeyIterator);
            })->toThrow(TypeError::class, 'key must be of type string');
        });

        test('throws TypeError lazily when traversable yields object violating Animal bound', function () {
            $service = new GenericStreamService();
            $badValueIterator = new ArrayIterator([
                'car1' => new Car(),
            ]);

            expect(function () use ($service, $badValueIterator) {
                $service->collectAnimalStream($badValueIterator);
            })->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Animal');
        });
    });

    describe('Generic Generator Returns (Generator<int, T>)', function () {
        test('yields items matching inferred template T', function () {
            $service = new GenericStreamService();
            $gen = $service->streamItem(100, 3);

            $results = [];
            foreach ($gen as $k => $v) {
                $results[$k] = $v;
            }

            expect($results)->toBe([0 => 100, 1 => 100, 2 => 100]);
        });
    });

    describe('Generic Generator Input Validation (TSend)', function () {
        test('accepts valid values sent via $gen->send() matching inferred template T = int', function () {
            $service = new GenericStreamService();
            $gen = $service->streamInteractive(10);

            expect($gen->current())->toBe(10);
            $gen->send(20);
            expect($gen->current())->toBe(20);
        });

        test('throws TypeError when $gen->send() receives value violating inferred template T = int', function () {
            $service = new GenericStreamService();
            $gen = $service->streamInteractive(10);

            $gen->current();

            expect(fn() => $gen->send('invalid'))
                ->toThrow(TypeError::class, 'must be of type int');
        });

        test('strictly enforces TSend = void and throws TypeError when non-null value is sent into generator', function () {
            $g = (new D2GeneratorFixture())->run();
            $g->current();

            expect(fn() => $g->send('not null'))
                ->toThrow(TypeError::class, 'Generator sent value (TSend) must be of type void, string \'not null\' given');
        });

        test('allows standard foreach iteration on generator with TSend = void', function () {
            $g = (new D2GeneratorFixture())->run();
            $items = iterator_to_array($g);

            expect($items)->toBe([1]);
        });
    });

    describe('Generator Return Value Validation (TReturn)', function () {
        test('accepts valid TReturn value upon generator completion', function () {
            $gen = testGeneratorWithValidReturn(['a', 'b']);

            foreach ($gen as $item) {
                // consume yields
            }

            expect($gen->getReturn())->toBeTrue();
        });

        test('throws TypeError when generator return value violates TReturn contract', function () {
            $gen = testGeneratorWithInvalidReturn(['a', 'b']);

            expect(function () use ($gen) {
                foreach ($gen as $item) {
                    // consume yields
                }
            })->toThrow(TypeError::class, 'Generator return value (TReturn) must be of type bool, int (12345) given');
        });

        test('throws TypeError when generator with TReturn = void returns a value', function () {
            $gen = testGeneratorWithInvalidVoidReturn();

            expect(function () use ($gen) {
                foreach ($gen as $item) {
                    // consume yields
                }
            })->toThrow(TypeError::class, 'must be of type void');
        });
    });

    describe('Conditional Generator & Iterable Contracts', function () {
        test('validates top-level conditional generator return with default T = int and overridden T = string', function () {
            $validIntGen = testConditionalGeneratorReturn(null, 42);
            expect(iterator_to_array($validIntGen))->toBe([0 => 42]);

            $badIntGen = testConditionalGeneratorReturn(null, -10);
            expect(fn() => iterator_to_array($badIntGen))
                ->toThrow(TypeError::class, 'Return iterator value must be of type positive-int');

            $validStrGen = testConditionalGeneratorReturn('override', 'hello');
            expect(iterator_to_array($validStrGen))->toBe([0 => 'hello']);

            $badStrGen = testConditionalGeneratorReturn('override', '');
            expect(fn() => iterator_to_array($badStrGen))
                ->toThrow(TypeError::class, 'Return iterator value must be of type non-empty-string');
        });

        test('validates nested conditional element inside Generator<int, (T is int ? ... : ...)>', function () {
            $validGen = testNestedConditionalGeneratorReturn(null, 100);
            expect(iterator_to_array($validGen))->toBe([0 => 100]);

            $badGen = testNestedConditionalGeneratorReturn(null, -5);
            expect(fn() => iterator_to_array($badGen))
                ->toThrow(TypeError::class, 'Return iterator value must be of type positive-int');

            $badStrGen = testNestedConditionalGeneratorReturn('override', '');
            expect(fn() => iterator_to_array($badStrGen))
                ->toThrow(TypeError::class, 'Return iterator value must be of type non-empty-string');
        });

        test('lazily validates conditional iterable parameter on Traversable iteration', function () {
            $validIter = new ArrayIterator([10, 20, 30]);
            expect(testConditionalIterableParam($validIter))->toBe([10, 20, 30]);

            $badIntIter = new ArrayIterator([10, -5, 30]);
            expect(fn() => testConditionalIterableParam($badIntIter))
                ->toThrow(TypeError::class, 'Iterator $stream value must be of type positive-int');

            $badStrIter = new ArrayIterator(['valid', '']);
            expect(fn() => testConditionalIterableParam($badStrIter, 'override'))
                ->toThrow(TypeError::class, 'Iterator $stream value must be of type non-empty-string');
        });
    });
});
