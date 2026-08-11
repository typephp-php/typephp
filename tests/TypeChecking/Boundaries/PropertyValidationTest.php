<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;

beforeEach(function () {
    Config::reset();

    Config::set([
        'inline_vars' => [
            'properties' => true,
            'generics' => true,
            'callables' => true,
            'scalars' => true,
            'arrays' => true,
            'objects' => true,
        ],
    ]);
});

afterEach(function () {
    Config::reset();
});

describe('Class Property Assignment Validations', function () {
    test('enforces scalar types on property assignments', function () {
        $fixture = new ConfiguredProperty();

        $fixture->assignNumbers([1, 2, 3]);
        expect($fixture->numbers)->toBe([1, 2, 3]);

        expect(fn () => $fixture->assignNumbers([1, 2, 'hello']))
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\ConfiguredProperty::\$numbers[2] must be of type int, string 'hello' given")
        ;
    });

    test('enforces object instances on property assignments', function () {
        $fixture = new ConfiguredProperty();

        $dog = new Dog();
        $fixture->assignAnimal($dog);
        expect($fixture->animal)->toBe($dog);

        expect(fn () => $fixture->assignAnimal(new Car()))
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\ConfiguredProperty::\$animal must be of type TypePHP\Tests\Fixtures\Domain\Dog")
        ;
    });

    test('enforces generic template constraints on property assignments', function () {
        $fixture = new ConfiguredProperty();

        $dogProducer = new Producer(new Dog());
        $fixture->assignProducer($dogProducer);
        expect($fixture->producer)->toBe($dogProducer);

        // A Producer containing a Car violates Producer<Dog>
        $carProducer = new Producer(new Car());
        expect(fn () => $fixture->assignProducer($carProducer))
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\ConfiguredProperty::\$producer expects TypePHP\Tests\Fixtures\Generics\Producer<covariant TypePHP\Tests\Fixtures\Domain\Dog>, but TypePHP\Tests\Fixtures\Generics\Producer<TypePHP\Tests\Fixtures\Domain\Car> was given")
        ;
    });

    test('enforces scalar types on static property assignments', function () {
        ConfiguredProperty::assignStaticTitle('New Title');
        expect(ConfiguredProperty::$staticTitle)->toBe('New Title');

        expect(fn () => ConfiguredProperty::assignStaticTitle(12345))
            ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\ConfiguredProperty::\$staticTitle must be of type string, int (12345) given")
        ;
    });

    test('ignores property validation when properties config is false', function () {
        Config::set(['inline_vars' => ['properties' => false]]);

        $fixture = new ConfiguredProperty();

        $fixture->assignNumbers([1, 2, 'hello']);
        expect($fixture->numbers)->toBe([1, 2, 'hello']);

        $car = new Car();
        $fixture->assignAnimal($car);
        expect($fixture->animal)->toBe($car);

        $fixture->assignProducer(new Producer(new Car()));
        expect($fixture->producer)->toBeInstanceOf(Producer::class);

        ConfiguredProperty::assignStaticTitle(999);
        expect(ConfiguredProperty::$staticTitle)->toBe(999);
    });
});
