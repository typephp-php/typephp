<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\ByRef\ByRefService;

/**
 * Standard scalar by-reference parameter
 *
 * @param positive-int &$id
 */
function testByRefScalar(int &$id): void
{
    $id += 100;
}

/**
 * Docblock without explicit '&' in annotation, but native signature has by-reference
 *
 * @param positive-int $count
 */
function testByRefNativeOnly(int &$count): void
{
    $count *= 2;
}

/**
 * Array passed by-reference
 *
 * @param list<positive-int> &$items
 */
function testByRefList(array &$items): void
{
    $items[] = 999;
}

/**
 * Variadic by-reference parameters
 *
 * @param positive-int &...$numbers
 */
function testByRefVariadic(int &...$numbers): void
{
    foreach ($numbers as &$num) {
        $num += 10;
    }
}

/**
 * String by-reference with trimming
 *
 * @param non-empty-string &$name
 */
function testByRefString(string &$name): void
{
    $name = trim($name);
}

describe('Arguments Passed By-Reference (&$param)', function () {
    describe('Scalar By-Reference Parameters', function () {
        test('accepts valid positive-int by reference and preserves in-place caller mutation', function () {
            $value = 25;
            testByRefScalar($value);

            expect($value)->toBe(125);
        });

        test('throws TypeError when by-reference variable violates contract on function entry', function () {
            $value = -50;

            expect(fn () => testByRefScalar($value))
                ->toThrow(TypeError::class, 'positive-int');

            // Value must remain untouched in caller scope
            expect($value)->toBe(-50);
        });

        test('handles native by-reference parameter when docblock omits & prefix in @param tag', function () {
            $counter = 15;
            testByRefNativeOnly($counter);

            expect($counter)->toBe(30);

            $invalidCounter = -10;
            expect(fn () => testByRefNativeOnly($invalidCounter))
                ->toThrow(TypeError::class, 'positive-int');

            expect($invalidCounter)->toBe(-10);
        });

        test('validates non-empty-string by reference and preserves in-place modification', function () {
            $name = '  Alice  ';
            testByRefString($name);

            expect($name)->toBe('Alice');

            $emptyName = '';
            expect(fn () => testByRefString($emptyName))
                ->toThrow(TypeError::class, 'non-empty-string');

            expect($emptyName)->toBe('');
        });
    });

    describe('Array / List By-Reference Parameters', function () {
        test('accepts valid list by reference and mutates array in caller scope', function () {
            $list = [10, 20, 30];
            testByRefList($list);

            expect($list)->toBe([10, 20, 30, 999]);
        });

        test('throws TypeError when by-reference list contains invalid items on entry', function () {
            $list = [10, -20, 30];

            expect(fn () => testByRefList($list))
                ->toThrow(TypeError::class, 'positive-int');

            // Original array remains untouched
            expect($list)->toBe([10, -20, 30]);
        });
    });

    describe('Variadic By-Reference Parameters (&...$params)', function () {
        test('accepts multiple variables by reference and mutates all of them in caller scope', function () {
            $a = 1;
            $b = 2;
            $c = 3;

            testByRefVariadic($a, $b, $c);

            expect($a)->toBe(11)
                ->and($b)->toBe(12)
                ->and($c)->toBe(13);
        });

        test('throws TypeError when any variadic by-reference argument is invalid on entry', function () {
            $a = 1;
            $b = -5;
            $c = 3;

            expect(fn () => testByRefVariadic($a, $b, $c))
                ->toThrow(TypeError::class, 'positive-int');

            // None of the variables should have mutated
            expect($a)->toBe(1)
                ->and($b)->toBe(-5)
                ->and($c)->toBe(3);
        });
    });

    describe('OOP & Interface Inherited By-Reference Parameters', function () {
        test('inherits by-reference parameter contract from interface and mutates caller variable', function () {
            $service = new ByRefService();
            $status = 'active';

            $service->updateStatus($status);

            expect($status)->toBe('ACTIVE');
        });

        test('throws TypeError when inherited by-reference parameter receives invalid value', function () {
            $service = new ByRefService();
            $status = '';

            expect(fn () => $service->updateStatus($status))
                ->toThrow(TypeError::class, 'non-empty-string');

            expect($status)->toBe('');
        });

        test('inherits by-reference contract across renamed parameter ($code -> $statusCode)', function () {
            $service = new ByRefService();
            $code = 50;

            $service->incrementCode($code);
            expect($code)->toBe(150);

            $badCode = -10;
            expect(fn () => $service->incrementCode($badCode))
                ->toThrow(TypeError::class, 'positive-int');

            expect($badCode)->toBe(-10);
        });
    });
});