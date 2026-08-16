<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Iterators\GenericStreamService;

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

            expect(fn () => $gen->send('invalid'))
                ->toThrow(TypeError::class, 'must be of type int')
            ;
        });
    });
});
