<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Callables\FirstClassCallableService;

/**
 * Higher-order function accepting a record formatter callback
 *
 * @param callable(positive-int, non-empty-string): non-empty-string $formatter
 * @param positive-int $id
 * @param non-empty-string $prefix
 *
 * @return non-empty-string
 */
function applyRecordFormatter(callable $formatter, int $id, string $prefix): string
{
    return $formatter($id, $prefix);
}

/**
 * Higher-order function accepting a single-argument code formatter callback
 *
 * @param callable(positive-int): non-empty-string $formatter
 * @param positive-int $code
 *
 * @return non-empty-string
 */
function applyCodeFormatter(callable $formatter, int $code): string
{
    return $formatter($code);
}

describe('PHP 8.1+ First-Class Callables ($obj->method(...) and Class::staticMethod(...))', function () {
    describe('Instance Method First-Class Callables', function () {
        test('executes valid instance method passed as first-class callable', function () {
            $service = new FirstClassCallableService();
            $callable = $service->formatRecord(...);

            $result = applyRecordFormatter($callable, 42, 'ITEM');
            expect($result)->toBe('ITEM_42');
        });

        test('throws TypeError when first-class callable receives invalid positive-int argument', function () {
            $service = new FirstClassCallableService();
            $callable = $service->formatRecord(...);

            expect(fn () => applyRecordFormatter($callable, -5, 'ITEM'))
                ->toThrow(TypeError::class, 'positive-int');
        });

        test('throws TypeError when first-class callable receives empty prefix string', function () {
            $service = new FirstClassCallableService();
            $callable = $service->formatRecord(...);

            expect(fn () => applyRecordFormatter($callable, 42, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });

        test('throws TypeError when first-class callable method returns an invalid return value', function () {
            $service = new FirstClassCallableService();
            $badCallable = $service->badReturnMethod(...);

            expect(fn () => applyCodeFormatter($badCallable, 10))
                ->toThrow(TypeError::class, 'must be of type non-empty-string');
        });
    });

    describe('Static Method First-Class Callables', function () {
        test('executes valid static method passed as first-class callable', function () {
            $staticCallable = FirstClassCallableService::formatStaticCode(...);

            $result = applyCodeFormatter($staticCallable, 200);
            expect($result)->toBe('CODE_200');
        });

        test('throws TypeError when static first-class callable receives negative code', function () {
            $staticCallable = FirstClassCallableService::formatStaticCode(...);

            expect(fn () => applyCodeFormatter($staticCallable, -10))
                ->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('Inline Variable Assignment with First-Class Callables', function () {
        test('enforces inline @var contract on first-class callable closure', function () {
            $service = new FirstClassCallableService();

            /** @var callable(positive-int, non-empty-string): non-empty-string $formatter */
            $formatter = $service->formatRecord(...);

            expect($formatter(100, 'USER'))->toBe('USER_100');

            expect(fn () => $formatter(-1, 'USER'))
                ->toThrow(TypeError::class, 'positive-int');

            expect(fn () => $formatter(100, ''))
                ->toThrow(TypeError::class, 'non-empty-string');
        });
    });
});