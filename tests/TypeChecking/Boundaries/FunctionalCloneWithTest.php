<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

if (PHP_VERSION_ID < 80500) {
    return;
}

require_once __DIR__ . '/../../Fixtures/Php85/CloneWithFunctionalFixtures.php';

use TypePHP\Exception\TypeError;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Php85\BoundedGenericBoxFixture;
use TypePHP\Tests\Fixtures\Php85\HookedCloneWithFixture;
use TypePHP\Tests\Fixtures\Php85\MagicCloneWithFixture;
use TypePHP\Tests\Fixtures\Php85\MultiTemplateMapFixture;
use TypePHP\Tests\Fixtures\Php85\NativeCloneWithRunner;
use TypePHP\Tests\Fixtures\Php85\Psr7ResponseWitherFixture;
use TypePHP\Tests\Fixtures\Php85\ShapeCloneWithFixture;
use TypePHP\TypePHP;

describe('Functional Type-Checking for PHP 8.5 "Clone With"', function () {
    describe('1. Generic Bound Preservation & Validation (@template T of Animal)', function () {
        test('preserves generic template T in WeakMap after wither method clone', function () {
            $dog = new Dog('Rover');
            $box = new BoundedGenericBoxFixture($dog);

            expect(TypePHP::getGenericType($box))->toBe(Dog::class);

            $newDog = new Dog('Max');
            $clonedBox = $box->withItem($newDog);

            expect($clonedBox)->not()->toBe($box)
                ->and($clonedBox->item)->toBe($newDog)
                ->and(TypePHP::getGenericType($clonedBox))->toBe(Dog::class)
            ;
        });

        test('throws TypeError when wither method attempts to clone with incompatible type', function () {
            $box = new BoundedGenericBoxFixture(new Dog('Rover'));

            expect(fn () => $box->withItem(new Car()))
                ->toThrow(TypeError::class, 'must be of type ' . Dog::class)
            ;
        });

        test('validates external clone with on public generic property', function () {
            $box = new BoundedGenericBoxFixture(new Dog('Rover'));

            $validClone = NativeCloneWithRunner::cloneWith($box, ['item' => new Dog('Buddy')]);
            expect(TypePHP::getGenericType($validClone))->toBe(Dog::class);

            expect(fn () => NativeCloneWithRunner::cloneWith($box, ['item' => new Car()]))
                ->toThrow(TypeError::class, 'must be of type ' . Dog::class)
            ;
        });
    });

    describe('2. Multi-Template Generic Maps (Map<K, V>)', function () {
        test('preserves both K and V templates and validates new array data on clone', function () {
            $map = new MultiTemplateMapFixture(['user_1' => 100, 'user_2' => 200]);

            expect(TypePHP::getGenericType($map, 'K'))->toBe('string')
                ->and(TypePHP::getGenericType($map, 'V'))->toBe('int')
            ;

            $updatedMap = $map->withData(['user_3' => 300]);

            expect(TypePHP::getGenericType($updatedMap, 'K'))->toBe('string')
                ->and(TypePHP::getGenericType($updatedMap, 'V'))->toBe('int')
                ->and($updatedMap->data)->toBe(['user_3' => 300])
            ;
        });

        test('throws TypeError when clone override violates generic value type V', function () {
            $map = new MultiTemplateMapFixture(['user_1' => 100]);

            expect(fn () => NativeCloneWithRunner::cloneWith($map, ['data' => ['user_1' => 'not_an_int']]))
                ->toThrow(TypeError::class, "['user_1'] must be of type int")
            ;
        });
    });

    describe('3. PSR-7 Readonly Class Wither Pattern', function () {
        test('allows modifying readonly properties via withStatus() inside class scope', function () {
            $res = new Psr7ResponseWitherFixture(200, 'OK');

            $notFound = $res->withStatus(404, 'Not Found');

            expect($notFound->statusCode)->toBe(404)
                ->and($notFound->reasonPhrase)->toBe('Not Found')
                ->and($res->statusCode)->toBe(200)
            ;
        });

        test('throws TypeError when withStatus() receives status code violating int<100, 599>', function () {
            $res = new Psr7ResponseWitherFixture();

            expect(fn () => $res->withStatus(999, 'Custom Status'))
                ->toThrow(TypeError::class, '<= 599')
            ;

            expect(fn () => $res->withStatus(50, 'Too Low'))
                ->toThrow(TypeError::class, '>= 100')
            ;
        });

        test('throws TypeError when withStatus() receives empty string for non-empty-string reasonPhrase', function () {
            $res = new Psr7ResponseWitherFixture();

            expect(fn () => $res->withStatus(200, ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('4. Magic __clone() Execution Precedence', function () {
        test('executes __clone() first, but applies clone-with property overrides as final state', function () {
            $model = new MagicCloneWithFixture();
            expect($model->counter)->toBe(10);

            $cloned = NativeCloneWithRunner::cloneWith($model, ['counter' => 50]);

            expect($cloned->magicCloneExecuted)->toBeTrue()
                ->and($cloned->counter)->toBe(50)
            ;
        });

        test('validates final overridden state even when __clone() mutated the object first', function () {
            $model = new MagicCloneWithFixture();

            expect(fn () => NativeCloneWithRunner::cloneWith($model, ['counter' => -100]))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });

    describe('5. PHP 8.4 Property Hooks with PHP 8.5 Clone With', function () {
        test('triggers set hook during property write and enforces contract', function () {
            $order = new HookedCloneWithFixture();

            $cloned = NativeCloneWithRunner::cloneWith($order, ['total' => 250]);
            expect($cloned->total)->toBe(250);

            expect(fn () => NativeCloneWithRunner::cloneWith($order, ['total' => -50]))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });

    describe('6. Array Shape Properties Overrides', function () {
        test('validates array shape properties on cloned instance', function () {
            $fixture = new ShapeCloneWithFixture();

            $cloned = NativeCloneWithRunner::cloneWith($fixture, [
                'session' => ['id' => 42, 'role' => 'admin'],
            ]);

            expect($cloned->session)->toBe(['id' => 42, 'role' => 'admin']);
        });

        test('throws TypeError when array shape id violates positive-int', function () {
            $fixture = new ShapeCloneWithFixture();

            expect(fn () => NativeCloneWithRunner::cloneWith($fixture, ['session' => ['id' => -1, 'role' => 'user']]))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int")
            ;
        });

        test('throws TypeError when array shape role violates literal union', function () {
            $fixture = new ShapeCloneWithFixture();

            expect(fn () => NativeCloneWithRunner::cloneWith($fixture, ['session' => ['id' => 10, 'role' => 'superuser']]))
                ->toThrow(TypeError::class, "['role'] must be of type ('admin' | 'user')")
            ;
        });
    });

    describe('7. Chained Withers & Nested Clones', function () {
        test('executes multi-step wither chain without state corruption', function () {
            $res = (new Psr7ResponseWitherFixture())
                ->withStatus(301, 'Moved Permanently')
                ->withStatus(200, 'OK')
            ;

            expect($res->statusCode)->toBe(200)
                ->and($res->reasonPhrase)->toBe('OK')
            ;
        });

        test('throws TypeError on second step of wither chain when invalid parameter is passed', function () {
            expect(fn () => (new Psr7ResponseWitherFixture())
                ->withStatus(200, 'OK')
                ->withStatus(750, 'Invalid Status'))
                ->toThrow(TypeError::class, '<= 599')
            ;
        });
    });

    describe('8. Audit Report Mode with Clone With (on_violation => report)', function () {
        test('records violation in report mode without throwing when clone-with property fails contract', function () {
            Config::set(['on_violation' => 'report']);

            $user = new ShapeCloneWithFixture();
            $cloned = NativeCloneWithRunner::cloneWith($user, ['session' => ['id' => -999, 'role' => 'user']]);

            expect($cloned)->toBeInstanceOf(ShapeCloneWithFixture::class);

            $violations = TypePHP::getViolations();
            expect($violations)->not()->toBeEmpty()
                ->and($violations[0]->kind)->toBe('property')
                ->and($violations[0]->target)->toBe('$session')
            ;
        });
    });
});
