<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

/**
 * 1. Standard Variadic By-Reference
 *
 * @param positive-int &...$numbers
 */
function testByRefVariadicUnpack(int &...$numbers): void
{
    foreach ($numbers as &$num) {
        $num *= 2;
    }
}

/**
 * 2. Mixed Positional By-Reference + Variadic By-Reference
 *
 * @param non-empty-string &$prefix
 * @param positive-int &...$numbers
 */
function testMixedLeadingByRefUnpack(string &$prefix, int &...$numbers): void
{
    $prefix = strtoupper($prefix);
    foreach ($numbers as &$num) {
        $num += 100;
    }
}

/**
 * 3. Complex Array Shape By-Reference Variadic
 *
 * @param array{id: positive-int, score: positive-int} &...$players
 */
function testByRefShapeVariadicUnpack(array &...$players): void
{
    foreach ($players as &$player) {
        $player['score'] += 50;
    }
}

/**
 * 4. Variadic using Array-Level DocBlock Syntax (@param positive-int[] ...$items)
 *
 * @param positive-int[] &...$items
 */
function testByRefArrayDocblockVariadic(int &...$items): void
{
    foreach ($items as &$item) {
        $item += 1;
    }
}

/**
 * 5. Variadic using List-Level DocBlock Syntax (@param list<positive-int> ...$items)
 *
 * @param list<positive-int> &...$items
 */
function testByRefListDocblockVariadic(int &...$items): void
{
    foreach ($items as &$item) {
        $item += 1;
    }
}

/**
 * OOP Fixtures for Variadic By-Reference
 */
interface VariadicByRefServiceInterface
{
    /**
     * @param positive-int &...$codes
     */
    public function batchIncrement(int &...$codes): void;
}

class VariadicByRefService implements VariadicByRefServiceInterface
{
    public function batchIncrement(int &...$codes): void
    {
        foreach ($codes as &$c) {
            $c += 10;
        }
    }
}

describe('Variadic By-Reference with Array Unpacking (&...$args with ...$data)', function () {

    describe('Basic Variadic Reference Unpacking', function () {
        test('mutates caller variables in place when unpacking reference array', function () {
            $a = 10;
            $b = 20;
            $c = 30;

            $args = [&$a, &$b, &$c];
            testByRefVariadicUnpack(...$args);

            expect($a)->toBe(20)
                ->and($b)->toBe(40)
                ->and($c)->toBe(60)
            ;
        });

        test('atomic failure: guarantees zero mutation if any unpacked reference violates contract', function () {
            $a = 10;
            $b = -5; // Violates positive-int!
            $c = 30;

            $args = [&$a, &$b, &$c];

            expect(fn () => testByRefVariadicUnpack(...$args))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            // Zero mutation must have occurred
            expect($a)->toBe(10)
                ->and($b)->toBe(-5)
                ->and($c)->toBe(30)
            ;
        });
    });

    describe('Leading Positional By-Reference + Variadic By-Reference', function () {
        test('unpacks mixed array across leading and variadic by-reference parameters', function () {
            $prefix = 'order';
            $num1 = 10;
            $num2 = 20;

            $payload = [&$prefix, &$num1, &$num2];
            testMixedLeadingByRefUnpack(...$payload);

            expect($prefix)->toBe('ORDER')
                ->and($num1)->toBe(110)
                ->and($num2)->toBe(120)
            ;
        });

        test('atomic failure: does not mutate leading by-ref if variadic item fails validation', function () {
            $prefix = 'order';
            $num1 = 10;
            $num2 = -99; // Invalid positive-int

            $payload = [&$prefix, &$num1, &$num2];

            expect(fn () => testMixedLeadingByRefUnpack(...$payload))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect($prefix)->toBe('order')
                ->and($num1)->toBe(10)
                ->and($num2)->toBe(-99)
            ;
        });
    });

    describe('PHP 8.1+ Named Variadics by Reference (Associative Unpacking)', function () {
        test('unpacks associative array of references into variadic by-reference parameter', function () {
            $x = 5;
            $y = 15;

            $named = ['first' => &$x, 'second' => &$y];
            testByRefVariadicUnpack(...$named);

            expect($x)->toBe(10)
                ->and($y)->toBe(30)
            ;
        });

        test('throws TypeError with key name when associative unpacked reference item fails', function () {
            $x = 5;
            $y = -20;

            $named = ['first' => &$x, 'bad_key' => &$y];

            expect(fn () => testByRefVariadicUnpack(...$named))
                ->toThrow(TypeError::class, "['bad_key'] must be of type positive-int")
            ;

            expect($x)->toBe(5)
                ->and($y)->toBe(-20)
            ;
        });
    });

    describe('Complex Array Shapes Passed By Reference with Unpacking', function () {
        test('mutates nested shape properties when unpacked by reference', function () {
            $p1 = ['id' => 1, 'score' => 100];
            $p2 = ['id' => 2, 'score' => 200];

            $players = [&$p1, &$p2];
            testByRefShapeVariadicUnpack(...$players);

            expect($p1['score'])->toBe(150)
                ->and($p2['score'])->toBe(250)
            ;
        });

        test('throws TypeError when unpacked array shape item violates inner constraint', function () {
            $p1 = ['id' => 1, 'score' => 100];
            $p2 = ['id' => 2, 'score' => -10]; // Invalid score!

            $players = [&$p1, &$p2];

            expect(fn () => testByRefShapeVariadicUnpack(...$players))
                ->toThrow(TypeError::class, "['score'] must be of type positive-int")
            ;

            expect($p1['score'])->toBe(100)
                ->and($p2['score'])->toBe(-10)
            ;
        });
    });

    describe('Variadic DocBlock Notation Edge Cases (Array/List Double-Wrapping)', function () {
        test('accepts unpacked references when docblock uses array syntax (@param positive-int[] ...$items)', function () {
            $a = 10;
            $b = 20;

            $args = [&$a, &$b];
            testByRefArrayDocblockVariadic(...$args);

            expect($a)->toBe(11)
                ->and($b)->toBe(21)
            ;
        });

        test('accepts unpacked references when docblock uses list syntax (@param list<positive-int> ...$items)', function () {
            $a = 10;
            $b = 20;

            $args = [&$a, &$b];
            testByRefListDocblockVariadic(...$args);

            expect($a)->toBe(11)
                ->and($b)->toBe(21)
            ;
        });
    });

    describe('OOP & Interface Inheritance with Variadic By-Reference Unpacking', function () {
        test('inherits variadic by-reference contracts across interface implementation when unpacking', function () {
            $service = new VariadicByRefService();

            $c1 = 100;
            $c2 = 200;
            $codes = [&$c1, &$c2];

            $service->batchIncrement(...$codes);

            expect($c1)->toBe(110)
                ->and($c2)->toBe(210)
            ;
        });

        test('throws TypeError when unpacked items violate interface variadic contract', function () {
            $service = new VariadicByRefService();

            $c1 = 100;
            $c2 = -50;
            $codes = [&$c1, &$c2];

            expect(fn () => $service->batchIncrement(...$codes))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect($c1)->toBe(100)
                ->and($c2)->toBe(-50);
        });
    });
});
