<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\CallablesAndIterators;

use Closure;
use TypePHP\Exception\TypeError;

/**
 * Higher-order function: callback must return string.
 * Native return is mixed so only the PHPDoc callback contract is tested.
 *
 * @param Closure(int): string $callback
 *
 * @return mixed
 */
function invokeCallbackWithReturnContract(Closure $callback): mixed
{
    return $callback(42);
}

/**
 * Higher-order function: callback parameter must be string.
 *
 * @param Closure(string): void $callback
 * @param mixed $arg
 */
function invokeCallbackWithParamContract(Closure $callback, mixed $arg): void
{
    $callback($arg);
}

/**
 * Higher-order function accepting an iterable with value contract.
 *
 * @param iterable<int, string> $items
 *
 * @return array<int, string>
 */
function consumeIterableWithContract(iterable $items): array
{
    $result = [];
    foreach ($items as $k => $v) {
        $result[$k] = $v;
    }

    return $result;
}

class IgnoredCallableCaller
{
    /**
     * Deliberately annotated with @typephp-ignore to test bypass.
     *
     * @typephp-ignore
     */
    public function runIgnoredCallbackReturn(): mixed
    {
        return invokeCallbackWithReturnContract(function (int $n): int {
            return 100;
        });
    }

    /**
     * Deliberately annotated with @typephp-ignore to test bypass.
     *
     * @typephp-ignore
     */
    public function runIgnoredCallbackParam(): void
    {
        invokeCallbackWithParamContract(function ($s): void {
        }, 555);
    }

    /**
     * Deliberately annotated with @typephp-ignore to test bypass.
     *
     * @typephp-ignore
     */
    public function runIgnoredIterableParam(): array
    {
        return consumeIterableWithContract([1 => 999]);
    }
}

class UnignoredCallableCaller
{
    public function runUnignoredCallbackReturn(): mixed
    {
        return invokeCallbackWithReturnContract(function (int $n): int {
            return 100;
        });
    }

    public function runUnignoredCallbackParam(): void
    {
        invokeCallbackWithParamContract(function ($s): void {
        }, 555);
    }

    public function runUnignoredIterableParam(): array
    {
        return consumeIterableWithContract([1 => 999]);
    }
}

describe('Ignore Tag Support in Callables and Iterators (@typephp-ignore)', function () {
    test('unignored caller throws TypeError when callback return type is violated', function () {
        $caller = new UnignoredCallableCaller();

        expect(fn () => $caller->runUnignoredCallbackReturn())
            ->toThrow(TypeError::class)
        ;
    });

    test('unignored caller throws TypeError when callback parameter type is violated', function () {
        $caller = new UnignoredCallableCaller();

        expect(fn () => $caller->runUnignoredCallbackParam())
            ->toThrow(TypeError::class)
        ;
    });

    test('unignored caller throws TypeError when iterable item type is violated', function () {
        $caller = new UnignoredCallableCaller();

        expect(fn () => $caller->runUnignoredIterableParam())
            ->toThrow(TypeError::class)
        ;
    });

    test('caller with @typephp-ignore bypasses callback return type violation', function () {
        $caller = new IgnoredCallableCaller();

        expect($caller->runIgnoredCallbackReturn())->toBe(100);
    });

    test('caller with @typephp-ignore bypasses callback parameter type violation', function () {
        $caller = new IgnoredCallableCaller();

        $caller->runIgnoredCallbackParam();
        expect(true)->toBeTrue();
    });

    test('caller with @typephp-ignore bypasses iterable wrapper item type violation', function () {
        $caller = new IgnoredCallableCaller();

        $items = $caller->runIgnoredIterableParam();
        expect($items)->toBe([1 => 999]);
    });
});
