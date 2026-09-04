<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

interface TestNamingStrategyInterface
{
    /**
     * @param class-string $model
     */
    public function getName(string $model): string;
}

class TestPluralizedSnakeCaseStrategy implements TestNamingStrategyInterface
{
    public function getName(string $model): string
    {
        return $model;
    }
}

/**
 * @param class-string $class
 */
function testDirectClassStringParam(string $class): string
{
    return $class;
}

/**
 * @return class-string
 */
function testSyntheticClassStringReturn(string $class): string
{
    return $class;
}

describe('Synthetic & Unloaded class-string Parameter & Return Contracts', function () {
    test('reproduces tempest PluralizedSnakeCaseStrategy with synthetic un-instantiated model class name', function () {
        $strategy = new TestPluralizedSnakeCaseStrategy();

        $result = $strategy->getName('App\Models\PersonalAccessToken');

        expect($result)->toBe('App\Models\PersonalAccessToken');
    });

    test('accepts synthetic class names on standalone functions with class-string docblock', function () {
        expect(testDirectClassStringParam('App\Models\User'))->toBe('App\Models\User');
        expect(testDirectClassStringParam('Vendor\Package\CustomDummyModel'))->toBe('Vendor\Package\CustomDummyModel');
    });

    test('accepts synthetic class names on functions returning class-string', function () {
        expect(testSyntheticClassStringReturn('App\Models\Order'))->toBe('App\Models\Order');
    });

    test('strictly rejects non-string and syntactically invalid class names', function () {
        expect(fn () => testDirectClassStringParam(''))
            ->toThrow(TypeError::class, 'must be of type class-string')
        ;

        expect(fn () => testDirectClassStringParam('Invalid Class Name With Spaces'))
            ->toThrow(TypeError::class, 'must be of type class-string')
        ;

        expect(fn () => testDirectClassStringParam('Foo-Bar-Baz'))
            ->toThrow(TypeError::class, 'must be of type class-string')
        ;

        expect(fn () => testDirectClassStringParam('123InvalidStart'))
            ->toThrow(TypeError::class, 'must be of type class-string')
        ;

        expect(fn () => testDirectClassStringParam('App\Models\\'))
            ->toThrow(TypeError::class, 'must be of type class-string')
        ;
    });
});
