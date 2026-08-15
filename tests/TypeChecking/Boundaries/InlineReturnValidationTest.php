<?php

declare(strict_types=1);

/**
 * Function with broad return type, but specific inline @var on return statement
 */
function testInlineVarOnReturnArray(): array
{
    /** @var list<string> */
    return [1, 2, 3]; 
}

/**
 * Valid inline @var on return statement
 */
function testValidInlineVarOnReturn(): array
{
    /** @var list<string> */
    return ['apple', 'banana', 'cherry'];
}

/**
 * Named @var tag on return statement
 */
function testNamedInlineVarOnReturn(int $id): int
{
    /** @var positive-int $id */
    return $id;
}

/**
 * Inline @var array shape on return inside a closure
 */
function testInlineVarOnReturnInClosure(): array
{
    $closure = function (): array {
        /** @var array{id: positive-int, name: non-empty-string} */
        return ['id' => -10, 'name' => 'Alice']; 
    };

    return $closure();
}

describe('Inline @var Validation on Direct Return Statements', function () {
    test('validates and accepts valid return expression with inline @var', function () {
        expect(testValidInlineVarOnReturn())->toBe(['apple', 'banana', 'cherry']);
    });

    test('throws TypeError when direct return expression violates unnamed inline @var contract', function () {
        expect(fn () => testInlineVarOnReturnArray())
            ->toThrow(TypeError::class, 'must be of type string');
    });

    test('throws TypeError when direct return expression violates named inline @var contract', function () {
        expect(fn () => testNamedInlineVarOnReturn(-5))
            ->toThrow(TypeError::class, 'positive-int');
    });

    test('throws TypeError when closure return expression violates inline @var array shape', function () {
        expect(fn () => testInlineVarOnReturnInClosure())
            ->toThrow(TypeError::class, 'positive-int');
    });
});