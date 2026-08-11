<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\ArraysAndShapes;

/**
 * @param array{id: int, tags: list<string|int>}|null $payload
 */
function testDeepUnionError(array|null $payload): bool
{
    return true;
}

/**
 * @return array{name: string, args: list<string|int|false>}|null
 */
function testAttributeCompilerSim(): ?array
{
    return [
        'name' => 'Field',
        'args' => ['column', 'property', new \stdClass()],
    ];
}

describe('Union Deep Error Bubbling', function () {
    test('surfaces deep array shape error instead of generic union error', function () {
        expect(fn() => testDeepUnionError(['id' => 10, 'tags' => ['hello', false]]))
            ->toThrow(\TypeError::class, "Argument \$payload['tags'][1] must be of type (string | int)");
    });

    test('surfaces deep return shape error mimicking AttributeEntityCompiler', function () {
        expect(fn() => testAttributeCompilerSim())
            ->toThrow(\TypeError::class, "Return value['args'][2] must be of type (string | int | false)");
    });
    
    test('surfaces missing key error from array shape inside union', function () {
        expect(fn() => testDeepUnionError(['id' => 10]))
            ->toThrow(\TypeError::class, "Argument \$payload is missing required key 'tags'");
    });
});