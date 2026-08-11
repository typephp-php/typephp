<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\CarRepository;
use TypePHP\Tests\Fixtures\Generics\Container;
use TypePHP\Tests\Fixtures\Generics\DogRepository;
use TypePHP\Tests\Fixtures\Generics\MultiLevelDogRepository;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Generics\ProducerDogRepository;
use TypePHP\Tests\Fixtures\Generics\Repository;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\UserApi;

/**
 * @param Repository<covariant Animal> $repo
 */
function handleAnimalRepoFixture(Repository $repo): mixed
{
    return $repo->item;
}

/**
 * @param Repository<covariant Producer<covariant Animal>> $repo
 */
function handleNestedProducerRepoFixture(Repository $repo): mixed
{
    return $repo->item;
}

describe('Generics, Bounds & Variance Contracts', function () {
    test('generic container respects template bounds', function () {
        $container = new Container(new Dog());
        expect($container->item)->toBeInstanceOf(Dog::class);

        expect(fn () => new Container(new Car()))
            ->toThrow(TypeError::class)
        ;
    });

    test('validates covariance on inherited generic class @extends', function () {
        $repo = new DogRepository();
        expect(handleAnimalRepoFixture($repo))->toBeInstanceOf(Dog::class);

        expect(fn () => handleAnimalRepoFixture(new CarRepository()))
            ->toThrow(TypeError::class, 'Repository<TypePHP\Tests\Fixtures\Domain\Car> was given')
        ;
    });

    test('validates nested generic structures in @extends', function () {
        $repo = new ProducerDogRepository();
        expect(handleNestedProducerRepoFixture($repo))->toBeInstanceOf(Producer::class);
    });

    test('validates multi-level generic inheritance', function () {
        $repo = new MultiLevelDogRepository();
        expect(handleAnimalRepoFixture($repo))->toBeInstanceOf(Dog::class);
    });
});

describe('Non-Generic DocBlock Method Contract Inheritance (LSP)', function () {
    test('child method inherits param and return contracts from parent class', function () {
        $service = new UserService();

        expect($service->find(10))->toBe(['id' => 10, 'name' => 'Alice']);

        // Invalid param
        expect(fn () => $service->find(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        // Invalid return
        expect(fn () => $service->find(999))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});

describe('Imported Type Aliases (@phpstan-import-type ... as)', function () {
    test('validates imported type alias with local rename', function () {
        $api = new UserApi();

        expect($api->saveUser(['id' => 1, 'name' => 'Alice']))->toBeTrue();

        expect(fn () => $api->saveUser(['id' => -1, 'name' => 'Alice']))
            ->toThrow(TypeError::class, "['id']")
        ;
    });
});
