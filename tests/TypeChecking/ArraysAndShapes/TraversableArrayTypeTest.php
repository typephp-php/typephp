<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * 1. Function with @param string[] and native `iterable` typehint
 *
 * @param string[] $extensions
 */
function testTraversableToStringArray(iterable $extensions): array
{
    $result = [];
    foreach ($extensions as $ext) {
        $result[] = $ext;
    }

    return $result;
}

/**
 * 2. Function with @param Dog[] and native `Traversable` typehint
 *
 * @param Dog[] $dogs
 */
function testTraversableToDogArray(Traversable $dogs): int
{
    return iterator_count($dogs);
}

/**
 * 3. Function with @param positive-int[] and un-typehinted parameter
 *
 * @param positive-int[] $numbers
 */
function testTraversableToPositiveIntArray($numbers): int
{
    $count = 0;
    foreach ($numbers as $num) {
        $count++;
    }

    return $count;
}

describe('Traversable & Generator Support for Array Types (Type[])', function () {
    test('accepts ArrayIterator instance matching string[] contract', function () {
        $iterator = new ArrayIterator(['ext1', 'ext2', 'ext3']);

        expect(testTraversableToStringArray($iterator))->toBe(['ext1', 'ext2', 'ext3']);
    });

    test('accepts Generator instance yielding valid string[] elements', function () {
        $generator = (function () {
            yield 'foo';
            yield 'bar';
        })();

        expect(testTraversableToStringArray($generator))->toBe(['foo', 'bar']);
    });

    test('throws TypeError when Traversable yields an element violating string[] contract', function () {
        $badIterator = new ArrayIterator(['ext1', 12345, 'ext3']);

        expect(fn () => testTraversableToStringArray($badIterator))
            ->toThrow(TypeError::class, '[1] must be of type string')
        ;
    });

    test('accepts Traversable instance matching object Dog[] contract', function () {
        $dogIterator = new ArrayIterator([new Dog(), new Dog()]);

        expect(testTraversableToDogArray($dogIterator))->toBe(2);
    });

    test('throws TypeError when Traversable contains an object violating Dog[] contract', function () {
        $badDogIterator = new ArrayIterator([new Dog(), new Car()]);

        expect(fn () => testTraversableToDogArray($badDogIterator))
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Dog')
        ;
    });

    test('accepts Traversable for un-typehinted parameter with positive-int[] docblock', function () {
        $numbers = new ArrayIterator([10, 20, 30]);

        expect(testTraversableToPositiveIntArray($numbers))->toBe(3);
    });

    test('throws TypeError when Traversable yields negative integer for positive-int[] docblock', function () {
        $badNumbers = new ArrayIterator([10, -5, 30]);

        expect(fn () => testTraversableToPositiveIntArray($badNumbers))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('rejects non-array and non-traversable values (e.g. string or plain stdClass)', function () {
        expect(fn () => testTraversableToPositiveIntArray('not_iterable'))
            ->toThrow(TypeError::class, 'must be of type array')
        ;

        expect(fn () => testTraversableToPositiveIntArray(new stdClass()))
            ->toThrow(TypeError::class, 'must be of type array')
        ;
    });
});