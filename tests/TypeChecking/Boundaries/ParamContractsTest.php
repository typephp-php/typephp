<?php

declare(strict_types=1);

use TypePHP\Contract\ContractParser;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Services\VariadicPropertyService;
use TypePHP\Tests\Fixtures\Types\NonCpmStrings;

/**
 * @param positive-int $id
 * @param non-empty-string $name
 * @param array{role: 'admin'|'user', active: bool} $meta
 */
function testProcessUserParam(int $id, string $name, array $meta): bool
{
    return true;
}

/**
 * @param callable(positive-int, non-empty-string): bool $callback
 */
function testExecuteCallback(callable $callback): bool
{
    return $callback(10, 'alice');
}

/**
 * @param callable(int): string $callback
 */
function testExecuteBadArgCallback(callable $callback): mixed
{
    return $callback('not_an_int');
}

/**
 * @param iterable<string, positive-int> $items
 */
function testProcessGenerator(iterable $items): array
{
    $result = [];
    foreach ($items as $k => $v) {
        $result[$k] = $v;
    }

    return $result;
}

/**
 * @param iterable<int, string> $items
 */
function testProcessIntKeyGenerator(iterable $items): array
{
    $out = [];
    foreach ($items as $k => $v) {
        $out[$k] = $v;
    }

    return $out;
}

/**
 * Function with purely mixed parameters
 *
 * @param mixed $data
 * @param mixed $meta
 */
function testPureMixedParamFunction(mixed $data, mixed $meta): bool
{
    return true;
}

describe('Function & Method Parameter Contracts', function () {
    test('inherits variadic constructor parameter contracts from property @var array docblocks without double-wrapping', function () {
        $service = new VariadicPropertyService(['tag1', 'tag2'], new Dog(), new Dog());

        expect($service->getAnimals())->toHaveCount(2)
            ->and($service->getAnimals()[0])->toBeInstanceOf(Dog::class)
        ;

        expect(fn () => new VariadicPropertyService(['tag1'], new Dog(), new Car()))
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Animal')
        ;
    });

    test('accepts valid parameters matching PHPDocs', function () {
        $result = testProcessUserParam(10, 'Alice', ['role' => 'admin', 'active' => true]);
        expect($result)->toBeTrue();
    });

    test('throws TypeError on invalid positive-int parameter', function () {
        expect(fn () => testProcessUserParam(-5, 'Alice', ['role' => 'admin', 'active' => true]))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('throws TypeError on empty string for non-empty-string parameter', function () {
        expect(fn () => testProcessUserParam(10, '', ['role' => 'admin', 'active' => true]))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('throws TypeError on invalid array shape item', function () {
        expect(fn () => testProcessUserParam(10, 'Alice', ['role' => 'superadmin', 'active' => true]))
            ->toThrow(TypeError::class, "['role']")
        ;
    });

    test('inherits constructor parameter contracts from property @var docblocks when constructor docblock is absent', function () {
        expect(fn () => new NonCpmStrings(['a', 'b', 'c', 1]))
            ->toThrow(TypeError::class, 'Argument $strings[3] must be of type string')
        ;
    });

    test('filters out pure mixed parameters so hasParamContract is false', function () {
        $contract = ContractParser::parse('testPureMixedParamFunction');

        expect($contract['types'])->toBeEmpty()
            ->and($contract['hasParamContract'])->toBeFalse()
        ;

        expect(testPureMixedParamFunction('anything', 12345))->toBeTrue();
    });
});

describe('Lazy Wrapped Callable Parameter Contracts', function () {
    test('executes valid wrapped callback cleanly', function () {
        $validCallback = fn (int $id, string $name): bool => \strlen($name) > 0;
        $result = testExecuteCallback($validCallback);

        expect($result)->toBeTrue();
    });

    test('throws TypeError when wrapped callback receives invalid argument', function () {
        $callback = fn (int $id): string => 'ok';

        expect(fn () => testExecuteBadArgCallback($callback))
            ->toThrow(TypeError::class, 'Callback $callback argument')
        ;
    });

    test('throws TypeError when wrapped callback returns invalid return type', function () {
        $badReturnCallback = fn (int $id, string $name): int => 123;

        expect(fn () => testExecuteCallback($badReturnCallback))
            ->toThrow(TypeError::class, 'Callback $callback return value')
        ;
    });
});

describe('Lazy Wrapped Iterable/Generator Parameter Contracts', function () {
    test('iterates valid generator yielding string keys and positive-int values', function () {
        $validGenerator = function () {
            yield 'a' => 10;
            yield 'b' => 20;
        };

        $result = testProcessGenerator($validGenerator());
        expect($result)->toBe(['a' => 10, 'b' => 20]);
    });

    test('throws TypeError lazily when generator yields invalid value', function () {
        $badValueGenerator = function () {
            yield 'a' => 10;
            yield 'b' => -50;
        };

        expect(fn () => testProcessGenerator($badValueGenerator()))
            ->toThrow(TypeError::class, 'Iterator $items value')
        ;
    });

    test('throws TypeError lazily when generator yields invalid key', function () {
        $badKeyGenerator = function () {
            yield 'not_an_int_key' => 'hello';
        };

        expect(fn () => testProcessIntKeyGenerator($badKeyGenerator()))
            ->toThrow(TypeError::class, 'Iterator $items key')
        ;
    });
});
