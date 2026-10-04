<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class HolderWithStaticIsset
{
    private static ?string $name = null;

    /**
     * @var positive-int|null
     */
    public static ?int $counter = null;

    /**
     * @var array<string, int>
     */
    public static array $cache = [];

    public static function reset(): void
    {
        self::$name = null;
        self::$counter = null;
        self::$cache = [];
    }

    public function get(): string
    {
        if (! isset(self::$name)) {
            self::$name = self::class;
        }

        return self::$name;
    }

    public function checkMultipleIsset(): bool
    {
        return isset(self::$name, self::$counter);
    }

    public function checkCacheKey(string $key): bool
    {
        return isset(self::$cache[$key]);
    }

    public function checkEmpty(): bool
    {
        return empty(self::$name);
    }

    public function &getRef(): ?string
    {
        return self::$name;
    }
}

describe('Static Property isset(), empty(), and by-ref Assign', function () {
    beforeEach(function () {
        HolderWithStaticIsset::reset();
    });

    test('compiles and executes if (!isset(self::$prop)) without expression fatal error (User Bug Report)', function () {
        $holder = new HolderWithStaticIsset();

        expect($holder->get())->toBe(HolderWithStaticIsset::class);
    });

    test('supports multiple static properties in isset(self::$a, self::$b)', function () {
        $holder = new HolderWithStaticIsset();

        expect($holder->checkMultipleIsset())->toBeFalse();

        HolderWithStaticIsset::$counter = 10;
        $holder->get(); // initializes self::$name

        expect($holder->checkMultipleIsset())->toBeTrue();
    });

    test('supports isset(self::$cache[$key]) on static array dimensions', function () {
        $holder = new HolderWithStaticIsset();

        expect($holder->checkCacheKey('token'))->toBeFalse();

        HolderWithStaticIsset::$cache['token'] = 123;
        expect($holder->checkCacheKey('token'))->toBeTrue();
    });

    test('supports empty(self::$prop) on static property without compile error', function () {
        $holder = new HolderWithStaticIsset();

        expect($holder->checkEmpty())->toBeTrue();
        $holder->get();
        expect($holder->checkEmpty())->toBeFalse();
    });

    test('supports assigning static property by reference ($ref = &self::$prop)', function () {
        $holder = new HolderWithStaticIsset();
        $holder->get();

        $ref = &$holder->getRef();
        $ref = 'mutated_via_ref';

        expect($holder->get())->toBe('mutated_via_ref');
    });

    test('still enforces type validation on normal reads of the static property outside isset', function () {
        HolderWithStaticIsset::$counter = 42;
        expect(HolderWithStaticIsset::$counter)->toBe(42);

        expect(function () {
            HolderWithStaticIsset::$counter = -99;
        })->toThrow(TypeError::class, 'positive-int');
    });
});
