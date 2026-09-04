<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

/**
 * Fixture reproducing Tempest\Support\Regex\get_match
 *
 * @template TMatch
 *
 * @param TMatch $match
 *
 * @return (
 *   TMatch is array
 *     ? array<string, string>
 *     : array{0: string, 1: int}
 * )
 */
function testListIsArrayConditional(mixed $match, mixed $returnVal): mixed
{
    return $returnVal;
}

describe('Template Conditional Subtype Evaluation (list is array)', function () {
    test('evaluates T is array as true when T is inferred as list from sequential array (Tempest get_match pattern)', function () {
        $match = ['a', 'b'];
        $result = testListIsArrayConditional($match, ['key1' => 'val1', 'key2' => 'val2']);

        expect($result)->toBe(['key1' => 'val1', 'key2' => 'val2']);
    });
});