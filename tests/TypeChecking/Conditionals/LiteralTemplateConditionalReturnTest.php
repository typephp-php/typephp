<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

use TypePHP\Exception\TypeError;

/**
 * @template TFormat of 'raw'|'typed'
 *
 * @param TFormat $format
 *
 * @return (TFormat is 'raw' ? list<array{name: string}> : list<int>)
 */
function testStringLiteralTemplateConditionalReturn(mixed $format, ?array $overrideReturn = null): array
{
    if ($overrideReturn !== null) {
        return $overrideReturn;
    }

    if ($format === 'raw') {
        return [['name' => 'a']];
    }

    return [1, 2, 3];
}

/**
 * @template TCode of 1|2
 *
 * @param TCode $code
 * @param mixed $val
 *
 * @return (TCode is 1 ? non-empty-string : positive-int)
 */
function testIntegerLiteralTemplateConditionalReturn(int $code, mixed $val): mixed
{
    return $val;
}

/**
 * @template T
 *
 * @param T $mode
 * @param mixed $val
 *
 * @return (T is 'json' ? array{status: string} : non-empty-string)
 */
function testUnboundedTemplateLiteralConditionalReturn(mixed $mode, mixed $val): mixed
{
    return $val;
}

/**
 * @template T of 'raw'|'parsed'
 *
 * @param T $format
 * @param mixed $val
 *
 * @return (T is not 'raw' ? list<int> : list<string>)
 */
function testNegatedLiteralTemplateConditionalReturn(string $format, mixed $val): mixed
{
    return $val;
}

/**
 * @template TFormat of 'raw'|'typed'
 *
 * @param TFormat $format
 * @param (TFormat is 'raw' ? array{name: string} : int) $payload
 */
function testStringLiteralTemplateConditionalParam(mixed $format, mixed $payload): mixed
{
    return $payload;
}

/**
 * @template TCode of 1|2
 *
 * @param TCode $code
 * @param (TCode is 1 ? non-empty-string : positive-int) $payload
 */
function testIntegerLiteralTemplateConditionalParam(int $code, mixed $payload): mixed
{
    return $payload;
}

/**
 * @template T
 *
 * @param T $mode
 * @param (T is 'json' ? array{status: string} : non-empty-string) $payload
 */
function testUnboundedTemplateLiteralConditionalParam(mixed $mode, mixed $payload): mixed
{
    return $payload;
}

/**
 * @template T of 'raw'|'parsed'
 *
 * @param T $format
 * @param (T is not 'raw' ? list<int> : list<string>) $payload
 */
function testNegatedLiteralTemplateConditionalParam(string $format, mixed $payload): mixed
{
    return $payload;
}

describe('Conditional Types Comparing Templates Against Literal Types (T is "literal")', function () {
    describe('A. Conditional Return Types', function () {
        test('selects if-branch return when argument matches target literal "raw"', function () {
            expect(testStringLiteralTemplateConditionalReturn('raw'))->toBe([['name' => 'a']]);
        });

        test('selects else-branch return when argument matches non-target literal "typed"', function () {
            expect(testStringLiteralTemplateConditionalReturn('typed'))->toBe([1, 2, 3]);
        });

        test('throws TypeError when if-branch return is selected but returns invalid shape for "raw"', function () {
            expect(fn () => testStringLiteralTemplateConditionalReturn('raw', overrideReturn: [1, 2, 3]))
                ->toThrow(TypeError::class, 'Return value[0] must be of type array, int (1) returned')
            ;
        });

        test('throws TypeError when else-branch return is selected but returns invalid type for "typed"', function () {
            expect(fn () => testStringLiteralTemplateConditionalReturn('typed', overrideReturn: [['name' => 'a']]))
                ->toThrow(TypeError::class, 'Return value[0] must be of type int')
            ;
        });

        test('selects return branch for integer literal template (TCode is 1)', function () {
            expect(testIntegerLiteralTemplateConditionalReturn(1, 'valid_string'))->toBe('valid_string');
            expect(fn () => testIntegerLiteralTemplateConditionalReturn(1, ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string')
            ;

            expect(testIntegerLiteralTemplateConditionalReturn(2, 42))->toBe(42);
            expect(fn () => testIntegerLiteralTemplateConditionalReturn(2, -5))
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });

        test('selects return branch for unbounded template (T is "json")', function () {
            $valid = ['status' => 'success'];
            expect(testUnboundedTemplateLiteralConditionalReturn('json', $valid))->toBe($valid);

            expect(fn () => testUnboundedTemplateLiteralConditionalReturn('json', 'plain_string'))
                ->toThrow(TypeError::class, 'Return value must be of type array')
            ;

            expect(testUnboundedTemplateLiteralConditionalReturn('xml', 'valid_xml'))->toBe('valid_xml');
            expect(fn () => testUnboundedTemplateLiteralConditionalReturn('xml', ''))
                ->toThrow(TypeError::class, 'Return value must be of type non-empty-string')
            ;
        });

        test('selects return branch for negated literal template (T is not "raw")', function () {
            expect(testNegatedLiteralTemplateConditionalReturn('raw', ['a', 'b']))->toBe(['a', 'b']);
            expect(fn () => testNegatedLiteralTemplateConditionalReturn('raw', [1, 2]))
                ->toThrow(TypeError::class, 'Return value[0] must be of type string')
            ;

            expect(testNegatedLiteralTemplateConditionalReturn('parsed', [10, 20]))->toBe([10, 20]);
            expect(fn () => testNegatedLiteralTemplateConditionalReturn('parsed', ['not_int']))
                ->toThrow(TypeError::class, 'Return value[0] must be of type int')
            ;
        });
    });

    describe('B. Conditional Parameter Types', function () {
        test('enforces array shape payload parameter when format is "raw"', function () {
            $validPayload = ['name' => 'Alice'];
            expect(testStringLiteralTemplateConditionalParam('raw', $validPayload))->toBe($validPayload);

            expect(fn () => testStringLiteralTemplateConditionalParam('raw', 123))
                ->toThrow(TypeError::class, 'Argument $payload must be of type array, int (123) given')
            ;

            expect(fn () => testStringLiteralTemplateConditionalParam('raw', []))
                ->toThrow(TypeError::class, "Argument \$payload is missing required key 'name'")
            ;
        });

        test('enforces int payload parameter when format is "typed"', function () {
            expect(testStringLiteralTemplateConditionalParam('typed', 42))->toBe(42);

            expect(fn () => testStringLiteralTemplateConditionalParam('typed', ['name' => 'Alice']))
                ->toThrow(TypeError::class, 'Argument $payload must be of type int')
            ;
        });

        test('enforces non-empty-string vs positive-int for integer literal template parameter', function () {
            expect(testIntegerLiteralTemplateConditionalParam(1, 'valid_code'))->toBe('valid_code');
            expect(fn () => testIntegerLiteralTemplateConditionalParam(1, ''))
                ->toThrow(TypeError::class, 'Argument $payload must be of type non-empty-string')
            ;
            expect(fn () => testIntegerLiteralTemplateConditionalParam(1, 100))
                ->toThrow(TypeError::class, 'Argument $payload must be of type non-empty-string')
            ;

            expect(testIntegerLiteralTemplateConditionalParam(2, 50))->toBe(50);
            expect(fn () => testIntegerLiteralTemplateConditionalParam(2, -10))
                ->toThrow(TypeError::class, 'Argument $payload must be of type positive-int')
            ;
            expect(fn () => testIntegerLiteralTemplateConditionalParam(2, 'valid_code'))
                ->toThrow(TypeError::class, 'Argument $payload must be of type positive-int')
            ;
        });

        test('enforces conditional parameter on unbounded template (T is "json")', function () {
            $jsonPayload = ['status' => 'ready'];
            expect(testUnboundedTemplateLiteralConditionalParam('json', $jsonPayload))->toBe($jsonPayload);

            expect(fn () => testUnboundedTemplateLiteralConditionalParam('json', 'string_payload'))
                ->toThrow(TypeError::class, 'Argument $payload must be of type array')
            ;

            expect(testUnboundedTemplateLiteralConditionalParam('text', 'hello_world'))->toBe('hello_world');
            expect(fn () => testUnboundedTemplateLiteralConditionalParam('text', ''))
                ->toThrow(TypeError::class, 'Argument $payload must be of type non-empty-string')
            ;
        });

        test('enforces conditional parameter on negated literal template (T is not "raw")', function () {
            expect(testNegatedLiteralTemplateConditionalParam('raw', ['x', 'y']))->toBe(['x', 'y']);

            expect(fn () => testNegatedLiteralTemplateConditionalParam('raw', [10, 20]))
                ->toThrow(TypeError::class, 'Argument $payload[0] must be of type string')
            ;

            expect(testNegatedLiteralTemplateConditionalParam('parsed', [10, 20]))->toBe([10, 20]);

            expect(fn () => testNegatedLiteralTemplateConditionalParam('parsed', ['x', 'y']))
                ->toThrow(TypeError::class, 'Argument $payload[0] must be of type int')
            ;
        });
    });
});
