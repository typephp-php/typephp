<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use TypePHP\Tests\Fixtures\Generics\HookedCollection;

describe('Generic Template Substitution in PHP 8.4 Property Hooks', function () {
    test('substitutes object-bound templates inside property hook validation (internal mutation)', function () {
        /** @var HookedCollection<positive-int> $collection */
        $collection = new HookedCollection();

        $collection->add(10);
        $collection->add(20);
        expect($collection->items)->toBe([10, 20]);

        expect(fn () => $collection->add(-5))
            ->toThrow(TypeError::class, 'Argument $item (template T = positive-int) must be of type positive-int, negative int (-5) given')
        ;
    });

    test('substitutes object-bound templates inside property hook validation (direct external assignment)', function () {
        /** @var HookedCollection<positive-int> $collection */
        $collection = new HookedCollection();

        $collection->items = [100, 200];
        expect($collection->items)->toBe([100, 200]);

        expect(fn () => $collection->items = [100, -50])
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Generics\HookedCollection::\$items['1'] must be of type positive-int, negative int (-50) given")
        ;
    });
});
