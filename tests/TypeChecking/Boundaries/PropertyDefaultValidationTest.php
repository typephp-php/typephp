<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class StaticPropertyDefaultInvalid
{
    /**
     * @var positive-int
     */
    public static int $score = -1;
}

class StaticPropertyDefaultListInvalid
{
    /**
     * @var list<string>
     */
    public static array $protected = [
        'name',
        'email',
        12345,
    ];
}

class InstancePropertyDefaultInvalid
{
    /**
     * @var positive-int
     */
    public int $score = -1;
}

class InstancePropertyDefaultValid
{
    /**
     * @var positive-int
     */
    public int $score = 100;
}

describe('Property Default Value Validation at Declaration & Instantiation', function () {
    test('rejects invalid static property default integer upon access or loading', function () {
        expect(fn () => StaticPropertyDefaultInvalid::$score)
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('rejects invalid static property default array list upon access or loading', function () {
        expect(fn () => StaticPropertyDefaultListInvalid::$protected)
            ->toThrow(TypeError::class, 'string')
        ;
    });

    test('rejects invalid instance property default value upon instantiation', function () {
        expect(fn () => new InstancePropertyDefaultInvalid())
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });

    test('accepts valid instance property default values cleanly', function () {
        $user = new InstancePropertyDefaultValid();

        expect($user->score)->toBe(100);
    });
});
