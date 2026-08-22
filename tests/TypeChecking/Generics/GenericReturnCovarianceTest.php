<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;

/**
 * Method declaring return type of GenericCollection<Animal>
 *
 * @return GenericCollection<Animal>
 */
function testReturnGenericCollection(GenericCollection $collection): GenericCollection
{
    return $collection;
}

describe('Generic Return Covariance (Liskov Subtyping on Return Types)', function () {
    test('accepts GenericCollection holding Dog subclass when return contract specifies Animal', function () {
        /** @var GenericCollection<Dog> $dogCollection */
        $dogCollection = new GenericCollection();
        $dogCollection->add(new Dog());

        $result = testReturnGenericCollection($dogCollection);

        expect($result)->toBe($dogCollection);
    });

    test('throws TypeError when GenericCollection returned holds an unrelated type', function () {
        /** @var GenericCollection<Car> $carCollection */
        $carCollection = new GenericCollection();
        $carCollection->add(new Car());

        expect(fn () => testReturnGenericCollection($carCollection))
            ->toThrow(TypeError::class)
        ;
    });
});