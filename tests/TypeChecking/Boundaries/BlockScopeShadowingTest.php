<?php

declare(strict_types=1);

describe('Block-Level Scope Shadowing (@var inside if/foreach/try blocks)', function () {
    test('restores outer scope variable contract after exiting an if block', function () {
        /** @var positive-int $z */
        $z = 10;

        if (true) {
            /** @var non-empty-string $z */
            $z = 'hello';
            expect($z)->toBe('hello');
        }

        // Outside if block, $z reverts back to outer contract: positive-int!
        // -5 violates positive-int (NOT non-empty-string)!
        expect(function () use (&$z) {
            $z = -5;
        })->toThrow(TypeError::class, 'Variable $z must be of type positive-int');
    });

    test('does not pollute outer scope when inner if block is never executed at runtime', function () {
        /** @var positive-int $x */
        $x = 100;

        if (false) {
            /** @var non-empty-string $x */
            $x = 'unreachable';
        }

        // Outer contract positive-int is preserved cleanly
        expect(function () use (&$x) {
            $x = -50;
        })->toThrow(TypeError::class, 'Variable $x must be of type positive-int');
    });
});
