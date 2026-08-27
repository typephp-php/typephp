<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\TypePHP;

/**
 * Function executed inside a Fiber with strict contracts
 *
 * @param positive-int $id
 * @param non-empty-string $name
 *
 * @return array{id: positive-int, name: non-empty-string}
 */
function fiberTaskWithContracts(int $id, string $name): array
{
    $step = Fiber::suspend('paused_at_step_1');

    return [
        'id' => $id,
        'name' => "{$name}_{$step}",
    ];
}

/**
 * Fiber function with invalid return type
 *
 * @return positive-int
 */
function fiberTaskWithBadReturn(): int
{
    Fiber::suspend();

    return -99;
}

describe('PHP 8.1+ Fibers & Concurrent Coroutine Execution', function () {

    describe('Basic Fiber Parameter and Return Contracts', function () {
        test('validates parameter contracts on fiber start and return shape on completion', function () {
            $fiber = new Fiber(function (int $id, string $name) {
                return fiberTaskWithContracts($id, $name);
            });

            $state1 = $fiber->start(42, 'Alice');
            expect($state1)->toBe('paused_at_step_1')
                ->and($fiber->isSuspended())->toBeTrue()
            ;

            $fiber->resume('step_2');

            expect($fiber->isTerminated())->toBeTrue()
                ->and($fiber->getReturn())->toBe([
                    'id' => 42,
                    'name' => 'Alice_step_2',
                ])
            ;
        });

        test('throws TypeError immediately when starting a fiber with invalid parameters', function () {
            $fiber = new Fiber(function (int $id, string $name) {
                return fiberTaskWithContracts($id, $name);
            });

            expect(fn () => $fiber->start(-5, 'Alice'))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('throws TypeError upon fiber completion when return value violates contract', function () {
            $fiber = new Fiber(function () {
                return fiberTaskWithBadReturn();
            });

            $fiber->start();
            expect($fiber->isSuspended())->toBeTrue();

            expect(fn () => $fiber->resume())
                ->toThrow(TypeError::class, 'Return value must be of type positive-int')
            ;
        });
    });

    describe('Interleaved Fiber Execution & Reified Generics Isolation (\WeakMap)', function () {
        test('isolates generic template states across concurrent interleaved fibers without cross-contamination', function () {
            $fiberDog = new Fiber(function () {
                /** @var GenericCollection<Dog> $dogs */
                $dogs = new GenericCollection();
                $dogs->add(new Dog());

                Fiber::suspend($dogs);

                $dogs->add(new Dog());

                return $dogs;
            });

            $fiberCat = new Fiber(function () {
                /** @var GenericCollection<Cat> $cats */
                $cats = new GenericCollection();
                $cats->add(new Cat());

                Fiber::suspend($cats);
                $cats->add(new Cat());

                return $cats;
            });

            /** @var GenericCollection<Dog> $dogCollection */
            $dogCollection = $fiberDog->start();
            /** @var GenericCollection<Cat> $catCollection */
            $catCollection = $fiberCat->start();

            expect($dogCollection)->toBeInstanceOf(GenericCollection::class)
                ->and(TypePHP::getGenericType($dogCollection))->toBe(Dog::class)
            ;

            expect($catCollection)->toBeInstanceOf(GenericCollection::class)
                ->and(TypePHP::getGenericType($catCollection))->toBe(Cat::class)
            ;

            $fiberDog->resume();
            $fiberCat->resume();

            $finalDogs = $fiberDog->getReturn();
            $finalCats = $fiberCat->getReturn();

            expect($finalDogs->count())->toBe(2)
                ->and($finalCats->count())->toBe(2)
            ;

            expect(fn () => $dogCollection->add(new Cat()))
                ->toThrow(TypeError::class, 'must be of type ' . Dog::class)
            ;

            expect(fn () => $catCollection->add(new Dog()))
                ->toThrow(TypeError::class, 'must be of type ' . Cat::class)
            ;
        });
    });

    describe('Multi-Fiber Concurrency Stress Test', function () {
        test('executes 50 concurrent interleaved fibers with generic collections and shape contracts', function () {
            $fibers = [];

            for ($i = 1; $i <= 50; $i++) {
                $fibers[$i] = new Fiber(function (int $fiberId) {
                    $service = new UserService();
                    $user = $service->find($fiberId);

                    Fiber::suspend("fiber_{$fiberId}_paused");

                    /** @var GenericCollection<Dog> $dogs */
                    $dogs = new GenericCollection();
                    $dogs->add(new Dog());

                    return [
                        'user' => $user,
                        'dog_count' => $dogs->count(),
                    ];
                });
            }

            foreach ($fibers as $id => $fiber) {
                $status = $fiber->start($id);
                expect($status)->toBe("fiber_{$id}_paused")
                    ->and($fiber->isSuspended())->toBeTrue()
                ;
            }

            foreach ($fibers as $id => $fiber) {
                $fiber->resume();
                $data = $fiber->getReturn();

                expect($fiber->isTerminated())->toBeTrue()
                    ->and($data['user']['id'])->toBe($id)
                    ->and($data['dog_count'])->toBe(1)
                ;
            }
        });
    });

    describe('Fiber Error Injections and Cleanup ($fiber->throw())', function () {
        test('cleans up execution state cleanly when an exception is injected into a suspended fiber', function () {
            $fiber = new Fiber(function () {
                /** @var GenericCollection<Dog> $dogs */
                $dogs = new GenericCollection();
                $dogs->add(new Dog());

                Fiber::suspend();

                return $dogs;
            });

            $fiber->start();
            expect($fiber->isSuspended())->toBeTrue();

            expect(function () use ($fiber) {
                $fiber->throw(new RuntimeException('Injected coroutine cancellation'));
            })->toThrow(RuntimeException::class, 'Injected coroutine cancellation');

            expect($fiber->isTerminated())->toBeTrue();
        });
    });
});
