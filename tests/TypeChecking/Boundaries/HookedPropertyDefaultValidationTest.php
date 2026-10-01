<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use TypePHP\Exception\TypeError;

class BackedPropertyWithViolatingGetHook
{
    /**
     * Backed property with default value 5 and a get hook returning -1.
     * The default (5) is valid, but the getter returns an invalid value.
     *
     * @var positive-int
     */
    public int $count = 5 {
        get => -1;
        set(int $value) {
            $this->count = $value;
        }
    }
}

class HookedPropertyAccessingConstructorState
{
    private string $prefix;

    /**
     * Backed property whose get hook accesses an uninitialized dependency.
     *
     * @var non-empty-string
     */
    public string $title = 'default' {
        get => $this->prefix . ': ' . $this->title;
    }

    public function __construct(string $prefix)
    {
        $this->prefix = $prefix;
    }
}

class MixedHookedAndRegularPropertiesClass
{
    /**
     * Hooked property with get hook (should skip constructor validation)
     *
     * @var positive-int
     */
    public int $hookedCount = 10 {
        get => $this->hookedCount * 2;
    }

    /**
     * Regular property with an invalid default value (must be caught in __construct)
     *
     * @var positive-int
     */
    public int $invalidDefault = -5;
}

class BasicTransformingHooks
{
    /**
     * @var non-empty-string
     */
    public string $greeting = 'hello' {
        get => strtoupper($this->greeting);
        set(string $value) {
            $this->greeting = trim($value);
        }
    }
}

describe('PHP 8.4 Backed Property Hook Default Initialization', function () {
    test('does not invoke get hook during instantiation for backed properties with defaults', function () {
        $broken = new BackedPropertyWithViolatingGetHook();

        expect($broken)->toBeInstanceOf(BackedPropertyWithViolatingGetHook::class);

        expect(fn () => $broken->count)
            ->toThrow(TypeError::class, 'Property BackedPropertyWithViolatingGetHook::$count must be of type positive-int, negative int (-1) given')
        ;
    });

    test('does not execute get hook before constructor initializes required object state', function () {
        $obj = new HookedPropertyAccessingConstructorState('ITEM');

        expect($obj)->toBeInstanceOf(HookedPropertyAccessingConstructorState::class)
            ->and($obj->title)->toBe('ITEM: default')
        ;
    });

    test('still enforces default value validation in constructor for regular properties in the same class', function () {
        expect(fn () => new MixedHookedAndRegularPropertiesClass())
            ->toThrow(TypeError::class, 'Property MixedHookedAndRegularPropertiesClass::$invalidDefault must be of type positive-int')
        ;
    });

    test('executes get and set transformations accurately without constructor interference', function () {
        $basic = new BasicTransformingHooks();

        expect($basic->greeting)->toBe('HELLO');

        $basic->greeting = '  world  ';
        expect($basic->greeting)->toBe('WORLD');

        expect(fn () => $basic->greeting = '')
            ->toThrow(TypeError::class, 'Property BasicTransformingHooks::$greeting must be of type non-empty-string')
        ;
    });
});
