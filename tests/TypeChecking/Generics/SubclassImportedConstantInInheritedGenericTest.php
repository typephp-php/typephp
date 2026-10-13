<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Commands\Actions\ClassLevelTraitLocationConsumer;
use TypePHP\Tests\Fixtures\Commands\Actions\ExecuteLocationsCommand;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationByStatus;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationDirectValue;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationMapKeyValue;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationMaskValue;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocations;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationsFqcn;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationsWithConstantKey;
use TypePHP\Tests\Fixtures\Commands\Actions\InlineTraitLocationConsumer;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

describe('Subclass Imported Class Constant in Inherited Generic Hierarchy', function () {
    describe('1. Class Inheritance (@extends)', function () {
        test('control: accepts valid value when FQCN is written in @extends', function () {
            $cmd = new GetLocationsFqcn(['statuses' => [Location::STATUS_ONLINE]]);
            expect($cmd->getOptions())->toBe(['statuses' => [1]]);
        });

        test('accepts valid values matching wildcard constant when class is imported in subclass', function () {
            $cmdOnline = new GetLocations(['statuses' => [Location::STATUS_ONLINE]]);
            expect($cmdOnline->getOptions())->toBe(['statuses' => [1]]);

            $cmdOffline = new GetLocations(['statuses' => [Location::STATUS_OFFLINE]]);
            expect($cmdOffline->getOptions())->toBe(['statuses' => [0]]);
        });

        test('rejects values that violate the wildcard constant constraint and displays the resolved FQCN in error', function () {
            expect(fn () => new GetLocations(['statuses' => [999]]))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });

        test('resolves specific imported class constant in inherited array shape', function () {
            $cmd = new GetLocationByStatus(['status' => 1]);
            expect($cmd->getOptions())->toBe(['status' => 1]);

            expect(fn () => new GetLocationByStatus(['status' => 0]))
                ->toThrow(TypeError::class, 'must be literal ' . Location::class . '::STATUS_ONLINE')
            ;
        });

        test('resolves imported class constant passed directly as generic template argument', function () {
            $cmd = new GetLocationDirectValue(1);
            expect($cmd->value)->toBe(1);

            expect(fn () => new GetLocationDirectValue(999))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });

        test('resolves imported class constant used as an array shape key in @extends', function () {
            $cmd = new GetLocationsWithConstantKey(['statuses' => [Location::STATUS_ONLINE]]);
            expect($cmd->getOptions())->toBe(['statuses' => [1]]);

            expect(fn () => new GetLocationsWithConstantKey(['statuses' => [999]]))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });

        test('resolves int-mask-of with imported constant in @extends', function () {
            $cmd = new GetLocationMaskValue(Location::STATUS_ONLINE);
            expect($cmd->value)->toBe(1);

            expect(fn () => new GetLocationMaskValue(4))
                ->toThrow(TypeError::class, 'must be a valid bitmask combination')
            ;
        });

        test('resolves key-of with imported constant map in @extends', function () {
            $cmd = new GetLocationMapKeyValue('online');
            expect($cmd->value)->toBe('online');

            expect(fn () => new GetLocationMapKeyValue('unknown_key'))
                ->toThrow(TypeError::class, 'must be a key of ' . Location::class . '::STATUS_MAP')
            ;
        });
    });

    describe('2. Interface Implementation (@implements)', function () {
        test('resolves imported class constant wildcard in @implements tag', function () {
            $executor = new ExecuteLocationsCommand();

            $result = $executor->execute(['statuses' => [Location::STATUS_ONLINE, Location::STATUS_OFFLINE]]);
            expect($result)->toBe(['statuses' => [1, 0]]);

            expect(fn () => $executor->execute(['statuses' => [999]]))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });
    });

    describe('3. Trait Usage (@use)', function () {
        test('resolves imported class constant wildcard in inline trait use statement (/** @use */ use Trait;)', function () {
            $consumer = new InlineTraitLocationConsumer();

            $result = $consumer->setLocationOptions(['statuses' => [Location::STATUS_ONLINE]]);
            expect($result)->toBe(['statuses' => [1]]);

            expect(fn () => $consumer->setLocationOptions(['statuses' => [999]]))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });

        test('resolves imported class constant wildcard in class-level trait use docblock', function () {
            $consumer = new ClassLevelTraitLocationConsumer();

            $result = $consumer->setLocationOptions(['statuses' => [Location::STATUS_OFFLINE]]);
            expect($result)->toBe(['statuses' => [0]]);

            expect(fn () => $consumer->setLocationOptions(['statuses' => [999]]))
                ->toThrow(TypeError::class, Location::class . '::STATUS_*')
            ;
        });
    });
});
