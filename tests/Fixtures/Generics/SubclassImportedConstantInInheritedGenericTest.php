<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationByStatus;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationDirectValue;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocations;
use TypePHP\Tests\Fixtures\Commands\Actions\GetLocationsFqcn;
use TypePHP\Tests\Fixtures\Commands\Model\Location;

describe('Subclass Imported Class Constant in Inherited Generic (@extends Command<array{statuses: list<Location::STATUS_*>}>)', function () {
    test('control: accepts valid value when FQCN is written in @extends', function () {
        $cmd = new GetLocationsFqcn(['statuses' => [Location::STATUS_ONLINE]]);

        expect($cmd->getOptions())->toBe(['statuses' => [1]]);
    });

    test('reproduces bug: accepts valid values matching wildcard constant when class is imported in subclass', function () {
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
            ->toThrow(TypeError::class, 'must be literal 1')
        ;
    });

    test('resolves imported class constant passed directly as generic template argument', function () {
        $cmd = new GetLocationDirectValue(1);
        expect($cmd->value)->toBe(1);

        expect(fn () => new GetLocationDirectValue(999))
            ->toThrow(TypeError::class, Location::class . '::STATUS_*')
        ;
    });
});
