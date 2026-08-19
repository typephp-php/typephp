<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Tests\Fixtures\Callables\GenericCallableService;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Services\ShiftedParamService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\ClassStringFactoryContainer;
use TypePHP\Tests\Fixtures\Types\MagicMethodFixture;
use TypePHP\Validator\TypeValidatorRegistry;

describe('ParamChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
    });

    afterEach(function () {
        Config::reset();
    });

    describe('Basic Parameter Contracts', function () {
        test('accepts valid parameters matching function contract', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $err = ParamChecker::checkParams($target, ['id' => 10], new UserService(), $registry);

            expect($err)->toBeNull();
        });

        test('returns ErrorMessage on invalid parameter type', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $err = ParamChecker::checkParams($target, ['id' => -5], new UserService(), $registry);

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('positive-int')
            ;
        });

        test('handles omitted optional parameters gracefully without error', function () {
            $registry = new TypeValidatorRegistry();
            $target = UserService::class . '::find';

            $err = ParamChecker::checkParams($target, [], new UserService(), $registry);

            expect($err)->toBeNull();
        });

        test('returns null immediately when params checking is disabled in config', function () {
            try {
                Config::set(['params' => false]);
                $registry = new TypeValidatorRegistry();
                $target = UserService::class . '::find';

                $err = ParamChecker::checkParams($target, ['id' => -5], new UserService(), $registry);

                expect($err)->toBeNull();
            } finally {
                Config::reset();
            }
        });
    });

    describe('Generic Array Template Pre-Inference (array<K, V>, list<T>, T[])', function () {
        test('pre-infers K and V from generic array argument before validation', function () {
            $registry = new TypeValidatorRegistry();
            $service = new GenericCallableService();
            $target = GenericCallableService::class . '::mapArray';

            $err = ParamChecker::checkParams($target, [
                'callback' => fn (int $x) => "val_{$x}",
                'array' => ['a' => 10, 'b' => 20],
            ], $service, $registry);

            expect($err)->toBeNull();
        });

        test('returns ErrorMessage when second array item violates pre-inferred template V', function () {
            $registry = new TypeValidatorRegistry();
            $service = new GenericCallableService();
            $target = GenericCallableService::class . '::mapArray';

            $err = ParamChecker::checkParams($target, [
                'callback' => fn (int $x) => "val_{$x}",
                'array' => ['a' => 10, 'b' => 'not_an_int'],
            ], $service, $registry);

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain("['b']")
            ;
        });

        test('pre-infers template T from list<T> parameter', function () {
            $registry = new TypeValidatorRegistry();
            $service = new GenericCallableService();
            $target = GenericCallableService::class . '::mapList';

            $err = ParamChecker::checkParams($target, [
                'callback' => fn (int $x) => $x * 2,
                'items' => [1, 'bad_int', 3],
            ], $service, $registry);

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('[1]')
            ;
        });
    });

    describe('Bare Generic Template Resolution (@template T)', function () {
        test('infers template T from first argument and validates subsequent arguments against T', function () {
            $registry = new TypeValidatorRegistry();
            $service = new GenericCallableService();
            $target = GenericCallableService::class . '::transform';

            $err = ParamChecker::checkParams($target, [
                'transformer' => fn (int $x) => $x * 2,
                'input' => 21,
            ], $service, $registry);

            expect($err)->toBeNull();
        });

        test('enforces class upper bound on template T of Animal', function () {
            $registry = new TypeValidatorRegistry();
            $service = new GenericCallableService();
            $target = GenericCallableService::class . '::formatAnimal';

            $validDog = new Dog();
            $err = ParamChecker::checkParams($target, [
                'formatter' => fn (Dog $d) => 'dog_tag',
                'animal' => $validDog,
            ], $service, $registry);

            expect($err)->toBeNull();
        });
    });

    describe('class-string<T> Validation', function () {
        test('accepts valid class-string implementing bound interface', function () {
            $registry = new TypeValidatorRegistry();
            $target = ClassStringFactoryContainer::class . '::makeCountable';

            $err = ParamChecker::checkParams($target, [
                'class' => ArrayObject::class,
            ], null, $registry);

            expect($err)->toBeNull();
        });

        test('returns ErrorMessage when class-string does not satisfy bound interface', function () {
            $registry = new TypeValidatorRegistry();
            $target = ClassStringFactoryContainer::class . '::makeCountable';

            $err = ParamChecker::checkParams($target, [
                'class' => stdClass::class,
            ], null, $registry);

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('must be a class-string of Countable')
            ;
        });

        test('returns ErrorMessage when class-string is not a valid class name', function () {
            $registry = new TypeValidatorRegistry();
            $target = ClassStringFactoryContainer::class . '::makeCountable';

            $err = ParamChecker::checkParams($target, [
                'class' => 'NonExistentClass12345',
            ], null, $registry);

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('must be a valid class-string')
            ;
        });
    });

    describe('Magic Method Interception (__call and __callStatic)', function () {
        test('validates parameters on dynamic instance @method calls routed via __call', function () {
            $registry = new TypeValidatorRegistry();
            $fixture = new MagicMethodFixture();
            $target = MagicMethodFixture::class . '::__call';

            $validErr = ParamChecker::checkParams($target, [
                'name' => 'processId',
                'arguments' => [42, 'Alice'],
            ], $fixture, $registry);
            expect($validErr)->toBeNull();

            $invalidErr = ParamChecker::checkParams($target, [
                'name' => 'processId',
                'arguments' => [-5, 'Alice'],
            ], $fixture, $registry);
            expect($invalidErr)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalidErr->getMessage())->toContain('positive-int')
            ;
        });

        test('validates variadic parameters on dynamic static @method calls routed via __callStatic', function () {
            $registry = new TypeValidatorRegistry();
            $target = MagicMethodFixture::class . '::__callStatic';

            $validErr = ParamChecker::checkParams($target, [
                'name' => 'fetchList',
                'arguments' => [1, 2, 3],
            ], MagicMethodFixture::class, $registry);
            expect($validErr)->toBeNull();

            $invalidErr = ParamChecker::checkParams($target, [
                'name' => 'fetchList',
                'arguments' => [1, 2, 'invalid_int'],
            ], MagicMethodFixture::class, $registry);
            expect($invalidErr)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalidErr->getMessage())->toContain('$items[2]')
            ;
        });
    });

    describe('Parameter Renaming & Position Shift Resolution', function () {
        test('validates inherited contracts when subclass renames parameters ($id -> $userId)', function () {
            $registry = new TypeValidatorRegistry();
            $service = new ShiftedParamService();
            $target = ShiftedParamService::class . '::registerUser';

            $validErr = ParamChecker::checkParams($target, [
                'userId' => 10,
                'userName' => 'Alice',
                'userRole' => 'admin',
            ], $service, $registry);
            expect($validErr)->toBeNull();

            $invalidErr = ParamChecker::checkParams($target, [
                'userId' => -5,
                'userName' => 'Alice',
                'userRole' => 'admin',
            ], $service, $registry);
            expect($invalidErr)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalidErr->getMessage())->toContain('positive-int')
            ;
        });
    });
});
