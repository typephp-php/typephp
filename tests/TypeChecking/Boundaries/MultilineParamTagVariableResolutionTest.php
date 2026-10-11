<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

/**
 * 1. Multi-line union where $event is on a separate line below the union branches
 *
 * @param array{type: 'click', x: int, y: int}
 *       | array{type: 'hover', element: non-empty-string}
 *       | array{type: 'keypress', key: non-empty-string}
 *       $event
 */
function testMultilineUnionParamWithVariableOnNewLine(array $event): string
{
    return $event['type'];
}

/**
 * 2. Multi-line union with variable name on new line followed by a description
 *
 * @param array{type: 'click', x: int, y: int}
 *       | array{type: 'hover', element: non-empty-string}
 *       $action The event payload to handle
 */
function testMultilineUnionWithDescription(array $action): string
{
    return $action['type'];
}

/**
 * 3. Class method with parameter name on a separate line below the multi-line union
 */
class MultilineParamService
{
    /**
     * @param array{mode: 'sync', timeout: positive-int}
     *       | array{mode: 'async', queue: non-empty-string}
     *       $payload
     */
    public function handle(array $payload): string
    {
        return $payload['mode'];
    }
}

describe('Multi-Line @param Union with Variable Name on New Line (TDD)', function () {
    test('accepts valid discriminated union branches when variable is on a new line', function () {
        expect(testMultilineUnionParamWithVariableOnNewLine(['type' => 'click', 'x' => 10, 'y' => 20]))->toBe('click');
        expect(testMultilineUnionParamWithVariableOnNewLine(['type' => 'hover', 'element' => 'btn-submit']))->toBe('hover');
        expect(testMultilineUnionParamWithVariableOnNewLine(['type' => 'keypress', 'key' => 'Enter']))->toBe('keypress');
    });

    test('throws TypeError when matching discriminator branch has an invalid property', function () {
        expect(fn () => testMultilineUnionParamWithVariableOnNewLine(['type' => 'hover', 'element' => '']))
            ->toThrow(
                TypeError::class,
                "Argument \$event['element'] must be of type non-empty-string, empty string ('') given"
            )
        ;
    });

    test('throws TypeError when discriminator does not match any union branch', function () {
        expect(fn () => testMultilineUnionParamWithVariableOnNewLine(['type' => 'scroll', 'delta' => 5]))
            ->toThrow(
                TypeError::class,
                'Argument $event must be of type (array{type: \'click\', x: int, y: int} | array{type: \'hover\', element: non-empty-string} | array{type: \'keypress\', key: non-empty-string})'
            )
        ;
    });

    test('validates multi-line union parameter with variable and description on new line', function () {
        expect(testMultilineUnionWithDescription(['type' => 'click', 'x' => 5, 'y' => 10]))->toBe('click');

        expect(fn () => testMultilineUnionWithDescription(['type' => 'hover', 'element' => '']))
            ->toThrow(
                TypeError::class,
                "['element'] must be of type non-empty-string"
            )
        ;
    });

    test('validates class methods when variable name is on a new line below multi-line union', function () {
        $service = new MultilineParamService();

        expect($service->handle(['mode' => 'sync', 'timeout' => 30]))->toBe('sync');
        expect($service->handle(['mode' => 'async', 'queue' => 'default']))->toBe('async');

        expect(fn () => $service->handle(['mode' => 'sync', 'timeout' => -5]))
            ->toThrow(TypeError::class, "['timeout'] must be of type positive-int")
        ;

        expect(fn () => $service->handle(['mode' => 'async', 'queue' => '']))
            ->toThrow(TypeError::class, "['queue'] must be of type non-empty-string")
        ;

        expect(fn () => $service->handle(['mode' => 'unknown', 'foo' => 'bar']))
            ->toThrow(TypeError::class, 'must be of type')
        ;
    });
});
