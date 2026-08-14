<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Services\GrandChildEntityFactory;
use TypePHP\Tests\Fixtures\Services\UserEntityFactory;
use TypePHP\Tests\Fixtures\Services\UserGenericFactory;
use TypePHP\Tests\Fixtures\Services\UserGenericMap;
use TypePHP\TypePHP;

describe('Late Static Binding Return Contracts (@return static)', function () {
    describe('Static Factory Methods', function () {
        test('accepts valid late-static-bound instance returned from parent static factory method', function () {
            $user = UserEntityFactory::create();

            expect($user)->toBeInstanceOf(UserEntityFactory::class);
        });

        test('throws TypeError when parent static factory method returns stdClass instead of late-static-bound child class', function () {
            expect(fn () => UserEntityFactory::createWrongInstance())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory')
            ;
        });

        test('throws TypeError when static factory method returns a sibling class instead of the called late-static class', function () {
            expect(fn () => UserEntityFactory::createSibling())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory')
            ;
        });
    });

    describe('Multi-Level 3-Tier Late Static Binding (GrandParent -> Parent -> GrandChild)', function () {
        test('resolves late-static-bound class to the deepest 3rd-tier descendant', function () {
            $grandChild = GrandChildEntityFactory::create();

            expect($grandChild)->toBeInstanceOf(GrandChildEntityFactory::class);
        });

        test('throws TypeError when 3rd-tier descendant returns an instance that violates the deepest child type', function () {
            expect(fn () => GrandChildEntityFactory::createSibling())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\GrandChildEntityFactory')
            ;
        });
    });

    describe('Collections of Late-Static-Bound Instances (list<static>)', function () {
        test('accepts list containing matching late-static-bound instances', function () {
            $batch = UserEntityFactory::createBatch(3);

            expect($batch)->toHaveCount(3)
                ->and($batch[0])->toBeInstanceOf(UserEntityFactory::class)
            ;
        });

        test('throws TypeError when list contains an item violating late-static-bound type', function () {
            expect(fn () => UserEntityFactory::createBadBatch())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory')
            ;
        });
    });

    describe('Instance Methods Returning static ($this)', function () {
        test('accepts valid $this instance returned from fluent instance method', function () {
            $user = new UserEntityFactory();

            expect($user)->toBe($user->withSetting('theme'));
        });

        test('throws TypeError when fluent instance method returns sibling instance', function () {
            $user = new UserEntityFactory();

            expect(fn () => $user->withBadSetting())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Services\UserEntityFactory')
            ;
        });
    });

    describe('Generics Combined with Late Static Binding (static<T> and Producer<static<T>>)', function () {
        test('creates generic static instance and binds template parameter dynamically', function () {
            $dog = new Dog();
            $factory = UserGenericFactory::of($dog);

            expect($factory)->toBeInstanceOf(UserGenericFactory::class)
                ->and($factory->item)->toBe($dog)
                ->and(TypePHP::getGenericType($factory))->toBe(Dog::class)
            ;
        });

        test('throws TypeError when generic static factory returns instance violating generic template T', function () {
            expect(fn () => UserGenericFactory::ofBadItem(new Dog()))
                ->toThrow(TypeError::class, 'UserGenericFactory<invariant TypePHP\Tests\Fixtures\Domain\Dog>, but TypePHP\Tests\Fixtures\Services\UserGenericFactory<stdClass> was returned')
            ;
        });

        test('validates nested generic containers holding late-static instances (Producer<static<T>>)', function () {
            $dog = new Dog();
            $factory = new UserGenericFactory($dog);
            $producer = $factory->toProducer();

            expect($producer)->toBeInstanceOf(Producer::class)
                ->and($producer->item)->toBe($factory)
            ;
        });

        test('throws TypeError when nested generic container holds sibling class instead of late-static class', function () {
            $dog = new Dog();
            $factory = new UserGenericFactory($dog);

            expect(fn () => $factory->toBadProducer())
                ->toThrow(TypeError::class, 'Producer<covariant TypePHP\Tests\Fixtures\Services\UserGenericFactory<TypePHP\Tests\Fixtures\Domain\Dog>>')
            ;
        });
    });

    describe('Multi-Template Generics Combined with Late Static Binding (static<K, V>)', function () {
        test('creates multi-template generic static instance and binds K and V accurately', function () {
            $dog = new Dog();
            $map = UserGenericMap::fromEntry('user_primary', $dog);

            expect($map)->toBeInstanceOf(UserGenericMap::class)
                ->and(TypePHP::getGenericType($map, 'K'))->toBe('string')
                ->and(TypePHP::getGenericType($map, 'V'))->toBe(Dog::class)
                ->and(TypePHP::getGenericTypes($map))->toBe(['K' => 'string', 'V' => Dog::class])
            ;
        });

        test('validates array shapes containing generic late-static instances array{instance: static<K, V>, count: int}', function () {
            $dog = new Dog();
            $shape = UserGenericMap::toShape('user_1', $dog);

            expect($shape['instance'])->toBeInstanceOf(UserGenericMap::class)
                ->and(TypePHP::getGenericType($shape['instance'], 'V'))->toBe(Dog::class)
                ->and($shape['count'])->toBe(1)
            ;
        });

        test('throws TypeError when array shape containing generic late-static instance violates inner shape contract', function () {
            expect(fn () => UserGenericMap::toBadShape('user_1', new Dog()))
                ->toThrow(TypeError::class, "Return value['count'] must be of type positive-int")
            ;
        });

        test('throws TypeError on inline @var invariant generic mismatch when assigning static generic factory result', function () {
            expect(function () {
                /** @var UserGenericFactory<Cat> $box */
                $box = UserGenericFactory::of(new Dog());
            })->toThrow(TypeError::class, 'UserGenericFactory<invariant TypePHP\Tests\Fixtures\Domain\Cat>, but TypePHP\Tests\Fixtures\Services\UserGenericFactory<TypePHP\Tests\Fixtures\Domain\Dog> was given');
        });
    });
});
