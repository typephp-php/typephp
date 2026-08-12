<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;
use TypePHP\Tests\Fixtures\Types\MagicMethodFixture;

beforeEach(function () {
    Config::reset();
});

afterEach(function () {
    Config::reset();
});

describe('Class-Level Magic Methods (@method) with Complex Types', function () {
    describe('Basic Parameters & Variadics', function () {
        test('validates arguments passed into dynamic instance method', function () {
            $fixture = new MagicMethodFixture();

            expect($fixture->processId(42, 'Alice'))->toBe(42);

            expect(fn () => $fixture->processId(-5, 'Alice'))
                ->toThrow(TypeError::class, 'TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::processId(): Argument $id must be of type positive-int, negative int (-5) given')
            ;

            expect(fn () => $fixture->processId(42, ''))
                ->toThrow(TypeError::class, "TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::processId(): Argument \$name must be of type non-empty-string, empty string ('') given")
            ;
        });

        test('validates variadic arguments passed into dynamic static method', function () {
            expect(MagicMethodFixture::fetchList(1, 2, 3))->toBe([1, 2, 3]);

            expect(fn () => MagicMethodFixture::fetchList(1, 2, 'hello'))
                ->toThrow(TypeError::class, "TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::fetchList(): Argument \$items[2] must be of type int, string 'hello' given")
            ;
        });
    });

    describe('Array Shapes & Lists in @method', function () {
        test('validates list arguments and array shape returns on dynamic method', function () {
            $fixture = new MagicMethodFixture();

            $result = $fixture->buildPayload([10, 20], 'active');
            expect($result)->toBe(['id' => 10, 'tags' => ['php', 'typephp']]);

            expect(fn () => $fixture->buildPayload([10, -5], 'active'))
                ->toThrow(TypeError::class, 'TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::buildPayload(): Argument $ids[1] must be of type positive-int')
            ;

            expect(fn () => $fixture->buildPayload([10, 20], 'archived'))
                ->toThrow(TypeError::class, "TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::buildPayload(): Argument \$status must be of type ('active' | 'pending')")
            ;
        });
    });

    describe('Generics in @method', function () {
        test('validates generic object instances passed to dynamic method', function () {
            $fixture = new MagicMethodFixture();
            $dogProducer = new Producer(new Dog());

            expect($fixture->getProducer($dogProducer))->toBe($dogProducer);

            $carProducer = new Producer(new Car());
            expect(fn () => $fixture->getProducer($carProducer))
                ->toThrow(TypeError::class, 'TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::getProducer(): Argument $producer expects TypePHP\\Tests\\Fixtures\\Generics\\Producer<covariant TypePHP\\Tests\\Fixtures\\Domain\\Dog>')
            ;
        });
    });

    describe('Intersections & Nullable Types in @method', function () {
        test('validates intersection types and nullable null on dynamic method', function () {
            $fixture = new MagicMethodFixture();

            expect($fixture->checkCollection(null))->toBeTrue();
            expect($fixture->checkCollection(new CountableArrayAccess()))->toBeTrue();
            expect(fn () => $fixture->checkCollection(new CountableOnly()))
                ->toThrow(TypeError::class, 'TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::checkCollection(): Argument $collection must be of type ((Countable & ArrayAccess) | null)')
            ;
        });
    });

    describe('Type Aliases (@phpstan-type) in @method', function () {
        test('resolves local class-level type aliases inside @method definitions', function () {
            $fixture = new MagicMethodFixture();

            $validUser = ['id' => 10, 'role' => 'admin'];
            expect($fixture->saveUser($validUser))->toBe($validUser);

            $badUser = ['id' => 10, 'role' => 'superadmin'];
            expect(fn () => $fixture->saveUser($badUser))
                ->toThrow(TypeError::class, "TypePHP\\Tests\\Fixtures\\Types\\MagicMethodFixture::saveUser(): Argument \$user['role'] must be of type ('admin' | 'user')")
            ;
        });
    });

    describe('Configuration Control', function () {
        test('ignores magic method validation when magic_methods config is false', function () {
            Config::set(['magic_methods' => false]);

            $fixture = new MagicMethodFixture();

            $result = $fixture->processId(-5, '');
            expect($result)->toBe(-5);
        });
    });
});
