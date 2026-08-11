<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * 1. Default template without explicit bound (@template T = string)
 *
 * @template T = string
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultTemplateString(mixed $value): mixed
{
    return $value;
}

/**
 * 2. Default template with explicit bound (@template T of object = stdClass)
 *
 * @template T of object = stdClass
 *
 * @param mixed $value
 *
 * @return T
 */
function testDefaultTemplateObject(mixed $value): mixed
{
    return $value;
}

describe('Default Template Annotations (@template T = DefaultType)', function () {
    test('uses default template type string when template T is unbound', function () {
        expect(testDefaultTemplateString('hello'))->toBe('hello');

        expect(fn () => testDefaultTemplateString(12345))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });

    test('uses default template type stdClass when template has both bound and default', function () {
        $std = new stdClass();
        expect(testDefaultTemplateObject($std))->toBe($std);

        // Dog is an object (satisfies bound 'object'), but NOT stdClass (violates default 'stdClass')
        expect(fn () => testDefaultTemplateObject(new Dog()))
            ->toThrow(TypeError::class, 'Return value')
        ;
    });
});
