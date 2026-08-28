<?php

declare(strict_types=1);

describe('Array Destructuring Contracts (list() & [$a, $b] = $data)', function () {
    test('enforces inline @var annotations on traditional list($a, $b) destructuring', function () {
        /**
         * @var positive-int $id
         * @var non-empty-string $name
         */
        list($id, $name) = [10, 'Alice'];

        expect($id)->toBe(10);
        expect($name)->toBe('Alice');

        expect(function () {
            /**
             * @var positive-int $id
             * @var non-empty-string $name
             */
            list($id, $name) = [-5, 'Alice'];
        })->toThrow(TypeError::class, 'Variable $id');
    });

    test('enforces inline @var annotations on short [$a, $b] destructuring', function () {
        /**
         * @var positive-int $id
         * @var non-empty-string $name
         */
        [$id, $name] = [42, 'Bob'];

        expect($id)->toBe(42);
        expect($name)->toBe('Bob');

        expect(function () {
            /**
             * @var positive-int $id
             * @var non-empty-string $name
             */
            [$id, $name] = [42, ''];
        })->toThrow(TypeError::class, 'Variable $name');
    });

    test('enforces mixed @phpstan-var and @var annotations on array destructuring', function () {
        /**
         * @phpstan-var positive-int $id
         *
         * @var non-empty-string $name
         */
        [$id, $name] = [10, 'Alice'];

        expect($id)->toBe(10);
        expect($name)->toBe('Alice');

        expect(function () {
            /**
             * @phpstan-var positive-int $id
             *
             * @var non-empty-string $name
             */
            [$id, $name] = [10, ''];
        })->toThrow(TypeError::class, 'Variable $name');
    });
});
