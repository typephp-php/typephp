<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use Closure;
use Stringable;

/**
 * Fixture reproducing Tempest\Support\Arr\ManipulatesArray trait and ImmutableArray
 *
 * @template TKey of array-key
 * @template TValue
 */
trait ConsoleTestManipulatesArray
{
    /** @var array<TKey, TValue> */
    public array $storage = [];

    public function __construct(mixed $input = [])
    {
        $this->storage = is_array($input) ? $input : [$input];
    }

    /**
     * @param null|Closure(TValue, TKey): bool $filter
     *
     * @return static<TKey, TValue>
     */
    public function filter(?Closure $filter = null): self
    {
        $res = [];
        foreach ($this->storage as $k => $v) {
            if ($filter === null || $filter($v, $k)) {
                $res[$k] = $v;
            }
        }

        return new static($res);
    }

    /**
     * @template TMapValue
     *
     * @param Closure(TValue, TKey): TMapValue $map
     *
     * @return static<TKey, TMapValue>
     */
    public function map(Closure $map): self
    {
        $res = [];
        foreach ($this->storage as $k => $v) {
            $res[$k] = $map($v, $k);
        }

        return new static($res);
    }
}

class ConsoleTestArray
{
    use ConsoleTestManipulatesArray;
}

class ConsoleInputArgumentFixture
{
    public function __construct(public ?string $name = null)
    {
    }
}

describe('Console Middleware to AboutCommand Template Leak Reproduction', function () {
    test('reproduces exact tempest about error when filter with closure is called before map with version string', function () {
        $arguments = new ConsoleTestArray([
            new ConsoleInputArgumentFixture('help'),
        ]);

        $arguments->filter(fn (ConsoleInputArgumentFixture $arg) => $arg->name !== null);

        $versions = new ConsoleTestArray('3.19.0');

        $result = $versions
            ->filter()
            ->map(function (Stringable|string $val) {
                return (string) $val;
            });

        expect($result->storage)->toBe(['3.19.0']);
    });
});