<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

describe('Foreach Reference Assignment Type Propagation', function () {
    test('intercepts invalid assignment to by-reference foreach item (Test 3)', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use (&$numbers) {
            foreach ($numbers as &$number) {
                $number = 'wrong';
            }
        })->toThrow(TypeError::class, 'Variable $number must be of type int, string \'wrong\' given');
    });

    test('allows valid assignment to by-reference foreach item and mutates array in place', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        foreach ($numbers as &$number) {
            $number *= 10;
        }
        unset($number);

        expect($numbers)->toBe([10, 20, 30]);
    });

    test('intercepts invalid assignment on associative map value and validates key type', function () {
        /** @var array<string, positive-int> $scores */
        $scores = ['alice' => 10, 'bob' => 20];

        expect(function () use (&$scores) {
            foreach ($scores as $name => &$score) {
                $score = -5;
            }
        })->toThrow(TypeError::class, 'Variable $score must be of type positive-int, negative int (-5) given');
    });

    test('propagates element type when iterating member dimension (e.g. $data[\'items\'] as &$item)', function () {
        /** @var array{items: list<positive-int>} $data */
        $data = ['items' => [1, 2, 3]];

        expect(function () use (&$data) {
            foreach ($data['items'] as &$item) {
                $item = -99;
            }
        })->toThrow(TypeError::class, 'Variable $item must be of type positive-int');
    });

    test('preserves explicit inline @var docblock on foreach loop over deduced type', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        expect(function () use (&$numbers) {
            /** @var positive-int $number */
            foreach ($numbers as &$number) {
                $number = 0;
            }
        })->toThrow(TypeError::class, 'positive-int');
    });
});

describe('Post-Loop Boundary Re-validation (The @param-out Analog)', function () {
    test('catches by-ref mutation caused by an untyped opaque function call at loop exit', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        $mutator = function (mixed &$val): void {
            $val = 'corrupted_by_opaque_call';
        };

        expect(function () use (&$numbers, $mutator) {
            foreach ($numbers as &$number) {
                $mutator($number);
            }
        })->toThrow(TypeError::class, 'Variable $numbers[0] must be of type int');
    });

    test('statically eliminates post-loop boundary check when loop body has no opaque calls and valid operations', function () {
        $source = <<<'PHP'
<?php
/** @var list<int> $numbers */
$numbers = [1, 2, 3];
foreach ($numbers as &$number) {
    $number = 42;
}
unset($number);
PHP;

        $transformed = \TypePHP\Internal\Io\StreamWrapper::transformSource($source, 'test_loop_elim.php');

        // Neither the assignment nor the loop boundary requires runtime checks
        expect($transformed)->not()->toContain('RuntimeTypeChecker::checkVariable');
    });

    test('unregisters reference variable after unset() statement', function () {
        /** @var list<int> $numbers */
        $numbers = [1, 2, 3];

        foreach ($numbers as &$number) {
            $number = 10;
        }
        unset($number);

        // Reusing $number without docblock after unset should not enforce int
        $number = 'new_string';
        expect($number)->toBe('new_string');
    });
});
