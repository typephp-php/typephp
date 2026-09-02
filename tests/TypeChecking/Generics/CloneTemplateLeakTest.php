<?php

declare(strict_types=1);

/**
 * @template TKey of array-key
 * @template TValue
 */
trait FixtureCloneLeakTrait
{
    /** @var array<TKey, TValue> */
    public array $items = [];

    public function __construct(array $items = [])
    {
        $this->items = $items;
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
        foreach ($this->items as $k => $v) {
            $res[$k] = $map($v, $k);
        }

        return new static($res);
    }
}

class FixtureCloneLeakArray
{
    use FixtureCloneLeakTrait;
}

class FixtureCloneArgument
{
    public function __construct(public string $name = 'test')
    {
    }
}

describe('Clone Template Leak Prevention (Tempest tempest about reproduction)', function () {
    test('does not leak generic template from a cloned instance to newly instantiated collections', function () {
        $argsArray = new FixtureCloneLeakArray([
            'arg1' => new FixtureCloneArgument('verbose'),
        ]);

        $argsArray->map(fn (FixtureCloneArgument $arg) => $arg->name);

        $cloned = clone $argsArray;
        
        $versionArray = new FixtureCloneLeakArray([
            'Tempest' => '3.19.0',
            'PHP' => '8.4.0',
        ]);

        $result = $versionArray->map(function ($version) {
            return (string) $version;
        });

        expect($result->items)->toBe([
            'Tempest' => '3.19.0',
            'PHP' => '8.4.0',
        ]);
    });
});