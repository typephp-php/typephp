<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

require_once __DIR__ . '/../../Fixtures/PropertyHooks/HookedPropertyDefaultFixtures.php';

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\PropertyHooks\BackedPropertyWithViolatingGetHook;
use TypePHP\Tests\Fixtures\PropertyHooks\BasicTransformingHooks;
use TypePHP\Tests\Fixtures\PropertyHooks\HookedPropertyAccessingConstructorState;
use TypePHP\Tests\Fixtures\PropertyHooks\MixedHookedAndRegularPropertiesClass;
use TypePHP\Tests\Fixtures\PropertyHooks\MixedSetOnlyAndUnprotectedPropertyModel;
use TypePHP\Tests\Fixtures\PropertyHooks\SetOnlyHookedModel;
use TypePHP\Tests\Fixtures\PropertyHooks\SetOnlyHookTransformingModel;

describe('PHP 8.4 Hooked Property Default Value Validation', function () {
    describe('Get Hooks with Defaults', function () {
        test('does not invoke get hook during instantiation for backed properties with defaults', function () {
            $broken = new BackedPropertyWithViolatingGetHook();

            expect($broken)->toBeInstanceOf(BackedPropertyWithViolatingGetHook::class);

            expect(fn () => $broken->count)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\BackedPropertyWithViolatingGetHook::$count must be of type positive-int, negative int (-1) given')
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
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\MixedHookedAndRegularPropertiesClass::$invalidDefault must be of type positive-int')
            ;
        });

        test('executes get and set transformations accurately without constructor interference', function () {
            $basic = new BasicTransformingHooks();

            expect($basic->greeting)->toBe('HELLO');

            $basic->greeting = '  world  ';
            expect($basic->greeting)->toBe('WORLD');

            expect(fn () => $basic->greeting = '')
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\BasicTransformingHooks::$greeting must be of type non-empty-string')
            ;
        });
    });

    describe('Set-Only Hooks with Defaults', function () {
        test('does not throw false positive on constructor entry when set-only hook has transient sentinel default', function () {
            $model = new SetOnlyHookedModel('  hello  ');

            expect($model)->toBeInstanceOf(SetOnlyHookedModel::class)
                ->and($model->label)->toBe('hello')
            ;
        });

        test('still enforces type contract on set hook when constructor assigns invalid value', function () {
            expect(fn () => new SetOnlyHookedModel(''))
                ->toThrow(TypeError::class, 'Argument $label must be of type non-empty-string, empty string (\'\') given')
            ;

            $model = new SetOnlyHookedModel('valid');
            expect(fn () => $model->label = '')
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\SetOnlyHookedModel::$label must be of type non-empty-string, empty string (\'\') given')
            ;
        });

        test('validates positive-int set-only hook with zero default when constructor assigns valid integer', function () {
            $model = new SetOnlyHookTransformingModel(42);

            expect($model->score)->toBe(42);

            expect(fn () => new SetOnlyHookTransformingModel(-5))
                ->toThrow(TypeError::class, 'Argument $score must be of type positive-int, negative int (-5) given')
            ;

            expect(fn () => $model->score = -5)
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\SetOnlyHookTransformingModel::$score must be of type positive-int, negative int (-5) given')
            ;
        });

        test('still catches invalid defaults on regular unhooked properties in set-only class', function () {
            expect(fn () => new MixedSetOnlyAndUnprotectedPropertyModel('valid'))
                ->toThrow(TypeError::class, 'Property TypePHP\Tests\Fixtures\PropertyHooks\MixedSetOnlyAndUnprotectedPropertyModel::$invalidDefault must be of type positive-int')
            ;
        });
    });
});
