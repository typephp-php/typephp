<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
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

/**
 * Method declaring return type of GenericCollection<covariant Animal>
 *
 * @return GenericCollection<covariant Animal>
 */
function testReturnUseSiteCovariantCollection(GenericCollection $collection): GenericCollection
{
    return $collection;
}

describe('Generic Return Invariance & Covariance', function () {
    afterEach(function () {
        Config::reset();
    });

    describe('Strict Mode (strict_return_generic_invariance => true [Default])', function () {
        test('rejects GenericCollection<Dog> returned where GenericCollection<Animal> is expected (PHPStan Invariance Parity)', function () {
            /** @var GenericCollection<Dog> $dogCollection */
            $dogCollection = new GenericCollection();
            $dogCollection->add(new Dog());

            expect(fn () => testReturnGenericCollection($dogCollection))
                ->toThrow(TypeError::class, 'expects TypePHP\Tests\Fixtures\Generics\GenericCollection<invariant TypePHP\Tests\Fixtures\Domain\Animal>, but TypePHP\Tests\Fixtures\Generics\GenericCollection<TypePHP\Tests\Fixtures\Domain\Dog> was returned')
            ;
        });

        test('accepts GenericCollection<Dog> when return contract specifies use-site covariance (<covariant Animal>)', function () {
            /** @var GenericCollection<Dog> $dogCollection */
            $dogCollection = new GenericCollection();
            $dogCollection->add(new Dog());

            $result = testReturnUseSiteCovariantCollection($dogCollection);

            expect($result)->toBe($dogCollection);
        });
    });

    describe('Pragmatic Mode (strict_return_generic_invariance => false [Framework Compatibility])', function () {
        test('accepts GenericCollection holding Dog subclass when strict return invariance is disabled', function () {
            try {
                Config::set(['strict_return_generic_invariance' => false]);

                /** @var GenericCollection<Dog> $dogCollection */
                $dogCollection = new GenericCollection();
                $dogCollection->add(new Dog());

                $result = testReturnGenericCollection($dogCollection);

                expect($result)->toBe($dogCollection);
            } finally {
                Config::reset();
            }
        });

        test('throws TypeError when GenericCollection returned holds an unrelated type even in pragmatic mode', function () {
            try {
                Config::set(['strict_return_generic_invariance' => false]);

                /** @var GenericCollection<Car> $carCollection */
                $carCollection = new GenericCollection();
                $carCollection->add(new Car());

                expect(fn () => testReturnGenericCollection($carCollection))
                    ->toThrow(TypeError::class)
                ;
            } finally {
                Config::reset();
            }
        });
    });
});
