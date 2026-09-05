<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Conditionals;

/**
 * Fixture reproducing Tempest\Support\Random\secure_string
 * where a parameter is mutated inside a loop before the return statement.
 *
 * @param int<0, max> $length
 *
 * @return ($length is 0 ? '' : non-empty-string)
 */
function testMutatedParameterConditional(int $length): string
{
    if ($length === 0) {
        return '';
    }

    $result = '';
    while ($length > 0) {
        $result .= 'x';
        --$length; // Mutates parameter down to 0 before return!
    }

    return $result;
}

describe('Parameter Conditional Returns with In-Body Parameter Mutation', function () {
    test('evaluates parameter conditional return using initial argument value, not mutated local variable (Tempest secure_string pattern)', function () {
        $result = testMutatedParameterConditional(10);

        expect($result)->toBe('xxxxxxxxxx');
    });

    test('evaluates empty string branch when initial argument is 0', function () {
        $result = testMutatedParameterConditional(0);

        expect($result)->toBe('');
    });
});
