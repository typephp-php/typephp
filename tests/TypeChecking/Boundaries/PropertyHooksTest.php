<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Types\HookedInterfaceImplementation;
use TypePHP\Tests\Fixtures\Types\HookedUser;
use TypePHP\Tests\Fixtures\Types\PropertyHooks;

beforeEach(function () {
    Config::reset();

    Config::set([
        'inline_vars' => [
            'properties' => true,
            'generics' => true,
            'callables' => true,
            'scalars' => true,
            'shapes' => true,
            'objects' => true,
        ],
    ]);
});

afterEach(function () {
    Config::reset();
});

describe('PHP 8.4 Property Hooks Validation', function () {
    test('validates return values of short get property hooks (get => $expr)', function () {
        $fixture = new PropertyHooks();

        expect(fn () => $fixture->shortGetNumbers)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$shortGetNumbers[0] must be of type int, string 'hello' given")
        ;
    });

    test('validates return values of block get property hooks (get { return $expr; })', function () {
        $fixture = new PropertyHooks();

        expect(fn () => $fixture->blockGetNumbers)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$blockGetNumbers[2] must be of type int, string 'invalid' given")
        ;
    });

    test('validates incoming values of short set property hooks (set => $expr)', function () {
        $fixture = new PropertyHooks();

        $fixture->shortSetNumber = 42;
        expect($fixture->_shortSetNumber)->toBe(42);

        expect(fn () => $fixture->shortSetNumber = -5)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$shortSetNumber must be of type positive-int, negative int (-5) given")
        ;
    });

    test('validates incoming values of block set property hooks (set($val) { ... })', function () {
        $fixture = new PropertyHooks();

        $fixture->blockSetNumber = 100;
        expect($fixture->_blockSetNumber)->toBe(100);

        expect(fn () => $fixture->blockSetNumber = -10)
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\PropertyHooks::\$blockSetNumber must be of type positive-int, negative int (-10) given")
        ;
    });

    test('skips type-checking on PHP 8.4 property hook marked with @typephp-ignore', function () {
        $fixture = new PropertyHooks();

        $fixture->unvalidatedHook = -50;
        expect($fixture->unvalidatedHook)->toBe(-50);
    });

    test('validates asymmetric visibility properties combined with property hooks', function () {
        $profile = new HookedUser();

        $profile->updateProfile(100, 'Bob');
        expect($profile->id)->toBe(100);
        expect($profile->username)->toBe('Bob');

        expect(fn () => $profile->updateProfile(-5, 'Bob'))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $profile->updateProfile(100, ''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });
});

describe('PHP 8.4 Interface Property Inheritance', function () {
    test('inherits @var docblock contracts from PHP 8.4 interface properties ({get;}, {get; set;}, {set;})', function () {
        $fixture = new HookedInterfaceImplementation();

        expect($fixture->readOnlyProp)->toBe(10);

        $fixture->_readOnlyVal = -5;
        expect(fn () => $fixture->readOnlyProp)
            ->toThrow(TypeError::class, 'positive-int')
        ;

        $fixture->readWriteProp = 'Bob';
        expect($fixture->readWriteProp)->toBe('Bob');

        expect(fn () => $fixture->readWriteProp = '')
            ->toThrow(TypeError::class, 'non-empty-string')
        ;

        $fixture->writeOnlyProp = 500;
        expect($fixture->_writeOnlyVal)->toBe(500);

        expect(fn () => $fixture->writeOnlyProp = -10)
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
