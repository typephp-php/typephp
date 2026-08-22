<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\ChildGenericBag;

describe('Multi-Tier Generic Placeholder Inheritance (TElement -> TElement)', function () {
    test('does not bind literal placeholder TElement as a concrete type when passing templates through inheritance', function () {
        $bag = new ChildGenericBag();
        $dog = new Dog();

        expect($bag->addItem($dog))->toBeTrue();
    });
});
