<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Tests\Fixtures\Probe\Route;
use TypePHP\Tests\Fixtures\Probe\RouteClass;
use TypePHP\Tests\Fixtures\Probe\UnparameterizedRoute;

describe('Trait-Supplied Interface Method Namespace Resolution', function () {
    test('resolves interface template bound in interface namespace when method is fulfilled by trait in sub-namespace', function () {
        $route = Route::fromName('a');

        expect($route)->toBe(Route::A);
    });

    test('resolves interface template on standard classes fulfilling interface via trait', function () {
        $route = RouteClass::fromName('x');

        expect($route)->toBeInstanceOf(RouteClass::class);
    });

    test('resolves interface template bound when class implements interface via trait without explicit @implements tag', function () {
        $route = UnparameterizedRoute::fromName('a');

        expect($route)->toBe(UnparameterizedRoute::A);
    });
});