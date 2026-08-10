<?php

declare(strict_types=1);

/**
 * Custom annotation with invalid class syntax (contains hyphens)
 *
 * @param madeup-type $specialType
 */
function testUnsupportedTypeSyntax(string $specialType): string
{
    return $specialType;
}

/**
 * Valid PHP class name syntax for a class that does not exist at runtime
 *
 * @param NonExistentClass $param
 */
function testNonExistentClassType(mixed $param): mixed
{
    return $param;
}

test('ignores custom unsupported type syntax with hyphens gracefully', function () {
    $result = testUnsupportedTypeSyntax('special-type');

    expect($result)->toBe('special-type');
});

test('strictly validates valid class syntax even if class does not exist at runtime', function () {
    expect(fn () => testNonExistentClassType('hello'))
        ->toThrow(TypeError::class, 'must be of type NonExistentClass, string \'hello\' given')
    ;

    expect(fn () => testNonExistentClassType(new stdClass()))
        ->toThrow(TypeError::class, 'must be of type NonExistentClass, stdClass given')
    ;
});
