<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\Tests\Fixtures\Services\HelperService;
use TypePHP\Tests\Fixtures\Services\InvokableFormatterService;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

/**
 * 1. Standard Callable Parameter Contract
 *
 * @param callable(positive-int, non-empty-string): bool $callback
 */
function testProcessUserCallback(callable $callback): bool
{
    return $callback(10, 'alice');
}

/**
 * 2. Named Parameter Callable Contract
 *
 * @param callable(positive-int $id, non-empty-string $username): string $callback
 */
function testNamedParamCallback(callable $callback): string
{
    return $callback(username: 'Alice', id: 42);
}

/**
 * 3. Named Parameter Callable Contract with Invalid Value
 *
 * @param callable(positive-int $id, non-empty-string $username): string $callback
 */
function testExecuteBadNamedParamCallback(callable $callback): string
{
    return $callback(username: '', id: 42);
}

/**
 * 4. Strict Closure Instance Parameter Contract
 *
 * @param Closure(positive-int): non-empty-string $closure
 */
function testProcessClosureOnly(Closure $closure): string
{
    return $closure(42);
}

/**
 * 5. Array Callable Parameter Contract
 *
 * @param callable(positive-int): non-empty-string $callback
 */
function testProcessArrayCallable(callable $callback): string
{
    return $callback(100);
}

/**
 * 6. Variadic Callback Parameters
 *
 * @param callable(positive-int ...$ids): void $callback
 */
function testVariadicCallbackParam(callable $callback): void
{
    $callback(10, 20, 30);
}

/**
 * 7. Optional Callback Parameters
 *
 * @param callable(positive-int, non-empty-string=): bool $callback
 */
function testOptionalCallbackParam(callable $callback, bool $passSecond = false): bool
{
    if ($passSecond) {
        return $callback(10, 'custom_name');
    }

    return $callback(10);
}

/**
 * 8. Static Closure Contracts
 *
 * @param static-closure(int): string $closure
 */
function testStaticClosureParam(Closure $closure): string
{
    return $closure(100);
}

/**
 * 9. Generic Container in Callable Contract (Valid)
 *
 * @param callable(Producer<Dog>): Dog $processor
 */
function testGenericCallableParam(callable $processor): Dog
{
    return $processor(new Producer(new Dog()));
}

/**
 * 10. Generic Container in Callable Contract (Invalid Subtype)
 *
 * @param callable(Producer<Dog>): Dog $processor
 */
function testExecuteBadGenericCallableParam(callable $processor): Dog
{
    return $processor(new Producer(new Car())); // Passes Producer<Car> where Producer<Dog> is required!
}

/**
 * 11. Typed Arrays and Shapes in Callable Contract
 *
 * @param callable(list<positive-int>, string[]): array{count: positive-int} $processor
 */
function testTypedArrayCallableParam(callable $processor): array
{
    return $processor([10, 20], ['tag1', 'tag2']);
}

/**
 * 12. Union Types in Callable Contract
 *
 * @param callable(positive-int|'active'): ('success'|'error') $processor
 */
function testUnionCallableParam(callable $processor, mixed $input): string
{
    return $processor($input);
}

/**
 * 13. Intersection Types in Callable Contract
 *
 * @param callable(Countable&ArrayAccess): bool $processor
 */
function testIntersectionCallableParam(callable $processor, object $collection): bool
{
    return $processor($collection);
}

describe('Standard Callable Contracts (callable(T1, T2): R)', function () {
    test('executes valid callback and validates arguments and return values', function () {
        $validCallback = fn (int $id, string $name): bool => $id > 0 && \strlen($name) > 0;

        expect(testProcessUserCallback($validCallback))->toBeTrue();
    });

    test('throws TypeError when callback returns a value violating PHPDoc return type', function () {
        $badReturnCallback = fn (int $id, string $name): int => 123;

        expect(fn () => testProcessUserCallback($badReturnCallback))
            ->toThrow(TypeError::class, 'return value must be of type bool')
        ;
    });
});

describe('PHP 8.0+ Named Arguments on Callables', function () {
    test('validates named arguments passed in swapped order to a wrapped callable', function () {
        $callback = fn (int $id, string $username): string => "{$id}_{$username}";

        expect(testNamedParamCallback($callback))->toBe('42_Alice');
    });

    test('throws TypeError when named argument passed to callable violates type contract', function () {
        $callback = fn ($id, $username) => 'ok';

        expect(fn () => testExecuteBadNamedParamCallback($callback))
            ->toThrow(TypeError::class, 'must be of type non-empty-string')
        ;
    });
});

describe('Generics in Callables (callable(Producer<Dog>): Dog)', function () {
    test('accepts callback taking generic Producer<Dog> and returning Dog', function () {
        $cb = fn (Producer $p): Dog => $p->item;

        expect(testGenericCallableParam($cb))->toBeInstanceOf(Dog::class);
    });

    test('throws TypeError when callback receives Producer with wrong generic subtype', function () {
        $cb = fn (Producer $p): Dog => $p->item;

        expect(fn () => testExecuteBadGenericCallableParam($cb))
            ->toThrow(TypeError::class, 'Producer<covariant TypePHP\Tests\Fixtures\Domain\Dog>')
        ;
    });
});

describe('Typed Arrays and Lists in Callables', function () {
    test('accepts callback taking list<positive-int> and string[], returning array shape', function () {
        $cb = fn (array $ids, array $tags): array => ['count' => \count($ids)];

        expect(testTypedArrayCallableParam($cb))->toBe(['count' => 2]);
    });

    test('throws TypeError when callback returns invalid array shape value', function () {
        $badCb = fn (array $ids, array $tags): array => ['count' => -5]; // -5 violates positive-int!

        expect(fn () => testTypedArrayCallableParam($badCb))
            ->toThrow(TypeError::class, "['count'] must be of type positive-int")
        ;
    });
});

describe('Unions in Callables', function () {
    test('accepts callback handling union arguments and union return types', function () {
        $cb = fn (int|string $val): string => 'success';

        expect(testUnionCallableParam($cb, 100))->toBe('success');
        expect(testUnionCallableParam($cb, 'active'))->toBe('success');
    });

    test('throws TypeError when callback receives argument outside union contract', function () {
        $cb = fn (int|string $val): string => 'success';

        expect(fn () => testUnionCallableParam($cb, -50))
            ->toThrow(TypeError::class, "must be of type (positive-int | 'active')")
        ;
    });

    test('throws TypeError when callback returns value outside return union contract', function () {
        $badCb = fn (int|string $val): string => 'invalid_return';

        expect(fn () => testUnionCallableParam($badCb, 100))
            ->toThrow(TypeError::class, "must be of type ('success' | 'error')")
        ;
    });
});

describe('Intersections in Callables (Countable & ArrayAccess)', function () {
    test('accepts callback taking object satisfying intersection contract', function () {
        $cb = fn (object $c): bool => \count($c) >= 0;
        $collection = new CountableArrayAccess();

        expect(testIntersectionCallableParam($cb, $collection))->toBeTrue();
    });

    test('throws TypeError when callback receives object failing intersection contract', function () {
        $cb = fn (object $c): bool => true;
        $onlyCountable = new CountableOnly();

        expect(fn () => testIntersectionCallableParam($cb, $onlyCountable))
            ->toThrow(TypeError::class, 'must be of type ArrayAccess')
        ;
    });
});

describe('Invokable Objects (__invoke) vs Closure Instances', function () {
    test('accepts invokable class instance for callable(T): R', function () {
        $invokable = new InvokableFormatterService();

        expect(testProcessArrayCallable($invokable))->toBe('invoked_100');
    });

    test('throws TypeError when invokable class instance is passed where Closure is strictly required', function () {
        $invokable = new InvokableFormatterService();

        expect(fn () => testProcessClosureOnly($invokable))
            ->toThrow(TypeError::class, 'must be of type Closure')
        ;
    });
});

describe('Optional Callback Parameters (callable(T1, T2=): R)', function () {
    test('accepts invocation when optional second argument is omitted', function () {
        $callback = fn (int $id, ?string $name = null): bool => $id > 0;

        expect(testOptionalCallbackParam($callback, false))->toBeTrue();
    });

    test('accepts invocation when optional second argument is provided with valid value', function () {
        $callback = fn (int $id, ?string $name = null): bool => $id > 0;

        expect(testOptionalCallbackParam($callback, true))->toBeTrue();
    });
});

describe('Array & First-Class Callables', function () {
    test('accepts valid instance method array callable', function () {
        $service = new HelperService();

        expect(testProcessArrayCallable([$service, 'formatUser']))->toBe('user_100');
    });

    test('accepts valid PHP 8.1+ first-class callable syntax', function () {
        $service = new HelperService();

        expect(testProcessArrayCallable($service->formatUser(...)))->toBe('user_100');
    });
});

describe('Static Closures (static-closure)', function () {
    test('accepts static closures and rejects non-static closures for static-closure', function () {
        $staticClosure = static fn (int $id): string => "static_{$id}";
        expect(testStaticClosureParam($staticClosure))->toBe('static_100');

        $nonStaticClosure = fn (int $id): string => "bound_{$id}";
        expect(fn () => testStaticClosureParam($nonStaticClosure))
            ->toThrow(TypeError::class, 'must be a static Closure')
        ;
    });
});
