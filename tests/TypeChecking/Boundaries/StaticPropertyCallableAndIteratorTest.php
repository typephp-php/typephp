<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use ArrayIterator;
use Closure;
use Iterator;
use stdClass;
use Traversable;
use TypePHP\Exception\TypeError;

class InvokableCalculatorFixture
{
    public function __invoke(int $a, int $b): int
    {
        return $a + $b;
    }
}

class StaticCallbackAndIteratorHolder
{
    /**
     * @var Closure(positive-int): non-empty-string|null
     */
    public static ?Closure $closureHandler = null;

    /**
     * @var static-closure|null
     */
    public static ?Closure $staticClosure = null;

    /**
     * @var static-closure
     */
    public static Closure $nonNullableStaticClosure;

    /**
     * @var callable(int, int): int|null
     */
    public static mixed $callableHandler = null;

    /**
     * @var iterable<string, positive-int>|null
     */
    public static mixed $iterableData = null;

    /**
     * @var Traversable<positive-int>|null
     */
    public static ?Traversable $traversableData = null;

    /**
     * @var Iterator|null
     */
    public static ?Iterator $iteratorData = null;

    public static function reset(): void
    {
        self::$closureHandler = null;
        self::$staticClosure = null;
        self::$callableHandler = null;
        self::$iterableData = null;
        self::$traversableData = null;
        self::$iteratorData = null;
    }

    public static function checkClosureIsset(): bool
    {
        return isset(self::$closureHandler);
    }

    public static function checkClosureEmpty(): bool
    {
        return empty(self::$closureHandler);
    }

    public static function checkCallableIsset(): bool
    {
        return isset(self::$callableHandler);
    }

    public static function checkCallableEmpty(): bool
    {
        return empty(self::$callableHandler);
    }

    public static function checkIterableIsset(): bool
    {
        return isset(self::$iterableData);
    }

    public static function checkIterableEmpty(): bool
    {
        return empty(self::$iterableData);
    }

    public function getBoundClosure(): Closure
    {
        return fn () => $this;
    }
}

describe('Static Properties with Closures, Callables, and Iterators', function () {
    beforeEach(function () {
        StaticCallbackAndIteratorHolder::reset();
    });

    afterEach(function () {
        StaticCallbackAndIteratorHolder::reset();
    });

    describe('Closures on Static Properties', function () {
        test('assigns valid closure and executes with isset and empty checks', function () {
            expect(StaticCallbackAndIteratorHolder::checkClosureIsset())->toBeFalse()
                ->and(StaticCallbackAndIteratorHolder::checkClosureEmpty())->toBeTrue()
            ;

            StaticCallbackAndIteratorHolder::$closureHandler = fn (int $id): string => "order_{$id}";

            expect(StaticCallbackAndIteratorHolder::checkClosureIsset())->toBeTrue()
                ->and(StaticCallbackAndIteratorHolder::checkClosureEmpty())->toBeFalse()
            ;

            $fn = StaticCallbackAndIteratorHolder::$closureHandler;
            expect($fn(42))->toBe('order_42');
        });

        test('throws TypeError when assigning non-closure to closure-typed static property', function () {
            expect(function () {
                StaticCallbackAndIteratorHolder::$closureHandler = 'strlen';
            })->toThrow(TypeError::class, 'Closure');
        });

        test('validates static-closure on static property and rejects bound closures', function () {
            StaticCallbackAndIteratorHolder::$staticClosure = static fn (): int => 100;
            expect(StaticCallbackAndIteratorHolder::$staticClosure)->toBeInstanceOf(Closure::class);

            $holder = new StaticCallbackAndIteratorHolder();
            $boundClosure = $holder->getBoundClosure();

            expect(function () use ($boundClosure) {
                StaticCallbackAndIteratorHolder::$staticClosure = $boundClosure;
            })->toThrow(TypeError::class, 'static-closure');

            expect(function () use ($boundClosure) {
                StaticCallbackAndIteratorHolder::$nonNullableStaticClosure = $boundClosure;
            })->toThrow(TypeError::class, 'must be a static Closure (not bound to $this)');
        });
    });

    describe('Callables on Static Properties', function () {
        test('accepts closures, invokables, and array callables on callable static property', function () {
            expect(StaticCallbackAndIteratorHolder::checkCallableIsset())->toBeFalse()
                ->and(StaticCallbackAndIteratorHolder::checkCallableEmpty())->toBeTrue()
            ;

            StaticCallbackAndIteratorHolder::$callableHandler = fn (int $a, int $b): int => $a * $b;
            expect(StaticCallbackAndIteratorHolder::checkCallableIsset())->toBeTrue();

            $fn = StaticCallbackAndIteratorHolder::$callableHandler;
            expect($fn(3, 4))->toBe(12);

            StaticCallbackAndIteratorHolder::$callableHandler = new InvokableCalculatorFixture();
            $invokable = StaticCallbackAndIteratorHolder::$callableHandler;
            expect($invokable(10, 20))->toBe(30);

            StaticCallbackAndIteratorHolder::$callableHandler = [StaticCallbackAndIteratorHolder::class, 'reset'];
            expect(StaticCallbackAndIteratorHolder::checkCallableIsset())->toBeTrue();
        });

        test('throws TypeError when assigning non-callable to callable static property', function () {
            expect(function () {
                StaticCallbackAndIteratorHolder::$callableHandler = 'non_existent_function_xyz';
            })->toThrow(TypeError::class, 'callable');

            expect(function () {
                StaticCallbackAndIteratorHolder::$callableHandler = new stdClass();
            })->toThrow(TypeError::class, 'callable');
        });
    });

    describe('Iterators, Traversables, and Iterables on Static Properties', function () {
        test('assigns valid typed iterable and validates iteration with isset and empty checks', function () {
            expect(StaticCallbackAndIteratorHolder::checkIterableIsset())->toBeFalse()
                ->and(StaticCallbackAndIteratorHolder::checkIterableEmpty())->toBeTrue()
            ;

            StaticCallbackAndIteratorHolder::$iterableData = [
                'first' => 10,
                'second' => 20,
            ];

            expect(StaticCallbackAndIteratorHolder::checkIterableIsset())->toBeTrue()
                ->and(StaticCallbackAndIteratorHolder::checkIterableEmpty())->toBeFalse()
            ;

            $collected = [];
            foreach (StaticCallbackAndIteratorHolder::$iterableData as $k => $v) {
                $collected[$k] = $v;
            }
            expect($collected)->toBe(['first' => 10, 'second' => 20]);
        });

        test('accepts ArrayIterator for iterable and Traversable static properties', function () {
            $iterator = new ArrayIterator([
                'alpha' => 100,
                'beta' => 200,
            ]);

            StaticCallbackAndIteratorHolder::$iterableData = $iterator;
            expect(StaticCallbackAndIteratorHolder::$iterableData)->toBe($iterator);

            $traversable = new ArrayIterator([10, 20, 30]);
            StaticCallbackAndIteratorHolder::$traversableData = $traversable;
            expect(StaticCallbackAndIteratorHolder::$traversableData)->toBe($traversable);
        });

        test('throws TypeError when assigning array violating typed iterable elements', function () {
            expect(function () {
                StaticCallbackAndIteratorHolder::$iterableData = [
                    'valid' => 10,
                    'invalid' => -5,
                ];
            })->toThrow(TypeError::class, "['invalid'] must be of type positive-int");
        });

        test('throws TypeError when assigning non-iterable to iterable static property', function () {
            expect(function () {
                StaticCallbackAndIteratorHolder::$iterableData = 'not_iterable';
            })->toThrow(TypeError::class, 'iterable');

            expect(function () {
                StaticCallbackAndIteratorHolder::$traversableData = new stdClass();
            })->toThrow(TypeError::class);
        });
    });
});
