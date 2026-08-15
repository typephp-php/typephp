<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Conditionals\ConditionalReturnService;
use TypePHP\Tests\Fixtures\Domain\Dog;

describe('Multi-Branch Nested & Negated Conditional Return Types', function () {
    describe('Deep 4-Branch Parameter Conditionals', function () {
        test('evaluates int branch (positive-int) when format is "int"', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByParameter('int', 42))->toBe(42);

            expect(fn () => $service->formatByParameter('int', -10))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int');
        });

        test('evaluates float branch (positive-float) when format is "float"', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByParameter('float', 3.14))->toBe(3.14);

            expect(fn () => $service->formatByParameter('float', -2.5))
                ->toThrow(TypeError::class, 'Return value must be of type positive-float');
        });

        test('evaluates bool branch when format is "bool"', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByParameter('bool', true))->toBeTrue();

            expect(fn () => $service->formatByParameter('bool', 'not_a_bool'))
                ->toThrow(TypeError::class, 'Return value must be of type bool');
        });

        test('evaluates list branch (list<positive-int>) when format is "list"', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByParameter('list', [10, 20, 30]))->toBe([10, 20, 30]);

            expect(fn () => $service->formatByParameter('list', [10, -5, 30]))
                ->toThrow(TypeError::class, "Return value[1] must be of type positive-int");
        });

        test('evaluates final fallback branch (non-empty-string) when format is any other string', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByParameter('text', 'hello_world'))->toBe('hello_world');

            expect(fn () => $service->formatByParameter('text', ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string');
        });
    });

    describe('Mixed Parameter & Generic Template Conditionals ($wrap is true ? list<T> : T)', function () {
        test('returns list<Dog> when wrapInList is true', function () {
            $service = new ConditionalReturnService();
            $dog1 = new Dog();
            $dog2 = new Dog();

            $result = $service->wrapOrReturn(true, $dog1, [$dog1, $dog2]);
            expect($result)->toBe([$dog1, $dog2]);
        });

        test('throws TypeError when wrapInList is true but single Dog is returned instead of list', function () {
            $service = new ConditionalReturnService();
            $dog = new Dog();

            expect(fn () => $service->wrapOrReturn(true, $dog, $dog))
                ->toThrow(TypeError::class, 'must be a list');
        });

        test('returns single Dog instance when wrapInList is false', function () {
            $service = new ConditionalReturnService();
            $dog = new Dog();

            $result = $service->wrapOrReturn(false, $dog, $dog);
            expect($result)->toBe($dog);
        });
    });

    describe('Negated Parameter Conditionals ($flag is not true)', function () {
        test('evaluates non-empty-string branch when flag is false', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByNegation(false, 'active_status'))->toBe('active_status');

            expect(fn () => $service->formatByNegation(false, ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string');
        });

        test('evaluates positive-int branch when flag is true', function () {
            $service = new ConditionalReturnService();

            expect($service->formatByNegation(true, 100))->toBe(100);

            expect(fn () => $service->formatByNegation(true, -50))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int');
        });
    });
});