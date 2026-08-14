<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;

/**
 * 1. Function accepting a list of lazy callables
 *
 * @param list<callable(positive-int): non-empty-string> $formatters
 */
function processFormatterList(array $formatters, int $id): array
{
    $results = [];
    foreach ($formatters as $formatter) {
        $results[] = $formatter($id);
    }

    return $results;
}

describe('Advanced Edge-Case Behaviors', function () {
    describe('Skipped and Deeply Nested Array Destructuring with @var', function () {
        test('validates variables when skipping elements with empty commas in destructuring', function () {
            /**
             * @var positive-int $id
             * @var non-empty-string $username
             */
            [$id, , $username] = [10, 'skipped_token', 'Alice'];

            expect($id)->toBe(10)
                ->and($username)->toBe('Alice');

            expect(function () {
                /**
                 * @var positive-int $id
                 * @var non-empty-string $username
                 */
                [$id, , $username] = [-5, 'skipped_token', 'Alice'];
            })->toThrow(TypeError::class, 'Variable $id must be of type positive-int');
        });

        test('validates variables in deeply nested array destructuring', function () {
            /**
             * @var positive-int $id
             * @var non-empty-string $street
             * @var int<10000, 99999> $zip
             */
            [$id, [$street, $zip]] = [42, ['Broadway', 90210]];

            expect($id)->toBe(42)
                ->and($street)->toBe('Broadway')
                ->and($zip)->toBe(90210);

            expect(function () {
                /**
                 * @var positive-int $id
                 * @var non-empty-string $street
                 * @var int<10000, 99999> $zip
                 */
                [$id, [$street, $zip]] = [42, ['', 90210]]; 
            })->toThrow(TypeError::class, 'Variable $street must be of type non-empty-string');
        });
    });

    describe('Nullable Generic Elements in Collections', function () {
        test('accepts null and valid refined scalars in Collection<?positive-int>', function () {
            /** @var GenericCollection<?positive-int> $collection */
            $collection = new GenericCollection();

            $collection->add(10);
            $collection->add(null);
            $collection->add(20);

            expect($collection->count())->toBe(3)
                ->and($collection->toArray())->toBe([10, null, 20]);
        });

        test('throws TypeError when adding invalid scalar to Collection<?positive-int>', function () {
            /** @var GenericCollection<?positive-int> $collection */
            $collection = new GenericCollection();

            expect(fn () => $collection->add(-50))
                ->toThrow(TypeError::class, 'Argument $item (template T = ?positive-int) must be of type positive-int, negative int (-50) given');
        });
    });

    describe('Collections of Lazy Callables', function () {
        test('executes and validates a list of lazy callable proxies', function () {
            $formatters = [
                fn (int $id): string => "id_{$id}",
                fn (int $id): string => "user#{$id}",
            ];

            $results = processFormatterList($formatters, 42);
            expect($results)->toBe(['id_42', 'user#42']);
        });

        test('throws TypeError when a callable in the collection returns an invalid type', function () {
            $formatters = [
                fn (int $id): string => "id_{$id}",
                fn (int $id): string => '', 
            ];

            expect(fn () => processFormatterList($formatters, 42))
                ->toThrow(TypeError::class, 'Callback $formatters[1] return value must be of type non-empty-string');
        });
    });
});