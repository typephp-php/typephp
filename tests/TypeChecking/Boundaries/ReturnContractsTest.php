<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Services\FluentService;

/**
 * @return array{status: 'success'|'error', code: positive-int}
 */
function testReturnShape(int $code): array
{
    if ($code < 0) {
        return ['status' => 'error', 'code' => $code]; // Invalid code -5
    }

    return ['status' => 'success', 'code' => $code];
}

/**
 * @param bool $asInt
 *
 * @return ($asInt is true ? positive-int : non-empty-string)
 */
function testConditionalParamReturn(bool $asInt, mixed $value): mixed
{
    return $value;
}

/**
 * @template T
 *
 * @param T $input
 * @param mixed $value
 *
 * @return (T is string ? positive-int : bool)
 */
function testConditionalTemplateReturn(mixed $input, mixed $value): mixed
{
    return $value;
}

describe('Return Type Contracts & Shapes', function () {
    test('accepts valid return shape', function () {
        $result = testReturnShape(200);
        expect($result)->toBe(['status' => 'success', 'code' => 200]);
    });

    test('throws TypeError when return shape item is invalid', function () {
        expect(fn () => testReturnShape(-5))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Strict $this Identity Return Contracts', function () {
    test('accepts valid $this instance return', function () {
        $service = new FluentService();
        expect($service->setValidSelf())->toBe($service);
    });

    test('throws TypeError when method returns new instance instead of $this', function () {
        $service = new FluentService();
        expect(fn () => $service->setInvalidSelf())
            ->toThrow(TypeError::class, 'Return value must be $this instance')
        ;
    });
});

describe('Parameter-based Conditional Return Types', function () {
    test('returns positive-int when condition parameter asInt is true', function () {
        $result = testConditionalParamReturn(true, 42);
        expect($result)->toBe(42);

        expect(fn () => testConditionalParamReturn(true, 'not_an_int'))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('returns non-empty-string when condition parameter asInt is false', function () {
        $result = testConditionalParamReturn(false, 'hello');
        expect($result)->toBe('hello');

        expect(fn () => testConditionalParamReturn(false, ''))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});

describe('Template-based Conditional Return Types', function () {
    test('returns positive-int when template T is inferred as string', function () {
        $result = testConditionalTemplateReturn('input_string', 100);
        expect($result)->toBe(100);

        expect(fn () => testConditionalTemplateReturn('input_string', 'invalid_return'))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('returns bool when template T is inferred as int', function () {
        $result = testConditionalTemplateReturn(123, true);
        expect($result)->toBeTrue();

        expect(fn () => testConditionalTemplateReturn(123, 'not_a_bool'))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});
