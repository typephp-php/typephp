<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

/**
 * Fixture reproducing Tempest\Support\Regex\get_match
 *
 * @template TMatch of null|string
 *
 * @param TMatch $match
 *
 * @return (
 *   TMatch is null
 *     ? array<int, string>
 *     : string
 * )
 */
function testNullableTemplateConditional(?string $match = null, mixed $returnValue = null): mixed
{
    return $returnValue;
}

describe('Nullable Template Conditional Returns (T is null ? A : B)', function () {
    test('binds T to null when parameter defaults to null and evaluates conditional return (Tempest get_match pattern)', function () {
        $result = testNullableTemplateConditional(returnValue: [0 => 'first', 1 => 'second']);

        expect($result)->toBe([0 => 'first', 1 => 'second']);
    });

    test('binds T to string when non-null string is passed and evaluates else branch', function () {
        $result = testNullableTemplateConditional('id', 'matched_id_string');

        expect($result)->toBe('matched_id_string');
    });
});
