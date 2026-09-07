<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;

/**
 * Invariant collection (standard @template T)
 *
 * @template T
 */
class InvariantTestBox
{
    /**
     * @var array<int, T>
     */
    public array $items = [];

    /**
     * @param T $item
     */
    public function add(mixed $item): void
    {
        $this->items[] = $item;
    }
}

/**
 * Covariant collection (@template-covariant T)
 *
 * @template-covariant T
 */
class CovariantTestBox
{
    /**
     * @param T $item
     */
    public function __construct(public mixed $item)
    {
    }
}

/**
 * Function returning InvariantTestBox<Animal>
 *
 * @return InvariantTestBox<Animal>
 */
function produceAnimalBox(): InvariantTestBox
{
    /** @var InvariantTestBox<Dog> $box */
    $box = new InvariantTestBox();
    $box->add(new Dog());

    return $box;
}

/**
 * Function returning CovariantTestBox<Animal>
 *
 * @return CovariantTestBox<Animal>
 */
function produceCovariantAnimalBox(): CovariantTestBox
{
    return new CovariantTestBox(new Dog());
}

/**
 * Function returning InvariantTestBox<covariant Animal> using use-site variance
 *
 * @return InvariantTestBox<covariant Animal>
 */
function produceUseSiteCovariantAnimalBox(): InvariantTestBox
{
    /** @var InvariantTestBox<Dog> $box */
    $box = new InvariantTestBox();
    $box->add(new Dog());

    return $box;
}

describe('Strict Return Generic Invariance Configuration', function () {
    afterEach(function () {
        Config::reset();
    });

    test('default strict mode rejects returning InvariantTestBox<Dog> for InvariantTestBox<Animal> (PHPStan Parity)', function () {
        expect(fn () => produceAnimalBox())
            ->toThrow(TypeError::class, 'expects TypePHP\Tests\TypeChecking\Generics\InvariantTestBox<invariant TypePHP\Tests\Fixtures\Domain\Animal>, but TypePHP\Tests\TypeChecking\Generics\InvariantTestBox<TypePHP\Tests\Fixtures\Domain\Dog> was returned')
        ;
    });

    test('default strict mode allows returning CovariantTestBox<Dog> when class declares @template-covariant T', function () {
        $result = produceCovariantAnimalBox();

        expect($result)->toBeInstanceOf(CovariantTestBox::class)
            ->and($result->item)->toBeInstanceOf(Dog::class)
        ;
    });

    test('default strict mode allows returning InvariantTestBox<Dog> when method specifies use-site <covariant Animal>', function () {
        $result = produceUseSiteCovariantAnimalBox();

        expect($result)->toBeInstanceOf(InvariantTestBox::class);
    });

    test('pragmatic mode allows returning InvariantTestBox<Dog> and WeakMap still prevents illegal caller mutations', function () {
        Config::set(['strict_return_generic_invariance' => false]);

        $box = produceAnimalBox();
        expect($box)->toBeInstanceOf(InvariantTestBox::class);

        expect(fn () => $box->add(new Cat()))
            ->toThrow(TypeError::class, 'Argument $item (template T = TypePHP\Tests\Fixtures\Domain\Dog) must be of type TypePHP\Tests\Fixtures\Domain\Dog, TypePHP\Tests\Fixtures\Domain\Cat given')
        ;
    });
});
