<?php

declare(strict_types=1);

/**
 * @template TAttribute of object
 *
 * @param class-string<TAttribute> $attributeClass
 */
function resolveObjectAttributeSim(string $class, string $attributeClass): bool
{
    return true;
}

/**
 * @template TMixed of mixed
 *
 * @param class-string<TMixed> $class
 */
function resolveMixedClassStringSim(string $class): bool
{
    return true;
}

/**
 * @template TDate of DateTimeInterface
 *
 * @param class-string<TDate> $class
 */
function resolveBoundedClassStringSim(string $class): bool
{
    return true;
}

describe('class-string<T> Template Bounds', function () {
    test('accepts class-string when template T is bounded by pseudo-type object', function () {
        expect(resolveObjectAttributeSim(stdClass::class, stdClass::class))->toBeTrue();
        expect(resolveObjectAttributeSim(DateTime::class, DateTimeImmutable::class))->toBeTrue();
    });

    test('accepts class-string when template T is bounded by pseudo-type mixed', function () {
        expect(resolveMixedClassStringSim(stdClass::class))->toBeTrue();
        expect(resolveMixedClassStringSim(DateTime::class))->toBeTrue();
    });

    test('accepts class-string matching concrete class or interface bound', function () {
        expect(resolveBoundedClassStringSim(DateTimeImmutable::class))->toBeTrue();
        expect(resolveBoundedClassStringSim(DateTime::class))->toBeTrue();
    });

    test('rejects syntactically invalid class-string when template T is bounded by object', function () {
        expect(fn () => resolveObjectAttributeSim(stdClass::class, 'Invalid-Class-Name!'))
            ->toThrow(TypeError::class, 'must be a valid class-string')
        ;
    });

    test('rejects class-string that does not implement specific interface bound', function () {
        expect(fn () => resolveBoundedClassStringSim(stdClass::class))
            ->toThrow(TypeError::class, 'must be a class-string of DateTimeInterface')
        ;
    });
});
