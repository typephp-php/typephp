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
trait FixtureLeakTrait
{
    /** @var array<TKey, TValue> */
    public array $storage = [];

    public function __construct(array $items = [])
    {
        $this->storage = $items;
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

class FixtureLeakArray
{
    use FixtureLeakTrait;
}

class FixtureConsoleInputArgument
{
    public function __construct(public string $name = 'test')
    {
    }
}

describe('Trait Class-Level Template Leak Across Calls (Tempest tempest about reproduction)', function () {
    test('reproduces tempest about failure where map() closure types leak to subsequent calls on different instances', function () {
        $argsArray = new FixtureLeakArray([
            'arg1' => new FixtureConsoleInputArgument('verbose'),
        ]);

        $argsArray->map(fn (FixtureConsoleInputArgument $arg, string $key) => $arg->name);

        $versionArray = new FixtureLeakArray([
            'Tempest' => '3.19.0',
            'PHP' => '8.4.0',
        ]);

        $result = $versionArray->map(
            function (Stringable|string $version, string $key): string {
                return (string) $version;
            }
        );

        expect($result->storage)->toBe([
            'Tempest' => '3.19.0',
            'PHP' => '8.4.0',
        ]);
    });
});