<?php

declare(strict_types=1);

describe('Closure Variable Type Preservation (Arrow Functions & Long Closures)', function () {
    test('preserves outer variable type contract inside short closures (arrow functions)', function () {
        /** @var positive-int $id */
        $id = 10;

        $arrowFn = fn () => $id;
        expect($arrowFn())->toBe(10);

        $badArrowFn = fn () => $id = -5;
        expect($badArrowFn)->toThrow(TypeError::class, 'Variable $id');
    });

    test('preserves outer variable type contract inside long closures (use ($id))', function () {
        /** @var positive-int $count */
        $count = 100;

        $closure = function () use ($count) {
            return $count;
        };
        expect($closure())->toBe(100);

        $badClosure = function () use ($count) {
            $count = -50;
        };
        expect($badClosure)->toThrow(TypeError::class, 'Variable $count');
    });

    test('preserves outer variable type contract when captured by reference (use (&$ref))', function () {
        /** @var positive-int $num */
        $num = 50;

        $refClosure = function () use (&$num) {
            $num = -99;
        };

        expect($refClosure)->toThrow(TypeError::class, 'Variable $num');
    });
});
