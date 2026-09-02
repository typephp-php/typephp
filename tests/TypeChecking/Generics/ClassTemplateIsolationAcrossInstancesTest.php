<?php

declare(strict_types=1);

class FixtureConsoleArgument
{
    public function __construct(public string $name = 'test')
    {
    }
}

/**
 * Fixture reproducing Tempest\Support\Arr\ManipulatesArray trait and ImmutableArray
 *
 * @template TKey of array-key
 * @template TValue
 */
class FixtureIsolatedArray
{
    /** @var array<TKey, TValue> */
    public array $value;

    public function __construct(array $value = [])
    {
        $this->value = $value;
    }

    /**
     * @template TMapValue
     *
     * @param Closure(TValue, TKey): TMapValue $map
     *
     * @return self<TKey, TMapValue>
     */
    public function map(Closure $map): self
    {
        $result = [];
        foreach ($this->value as $key => $val) {
            $result[$key] = $map($val, $key);
        }

        return new self($result);
    }
}

describe('Class-Level Template Isolation Across Distinct Instances', function () {
    test('does not leak closure parameter template bindings between different collection instances', function () {
        $argumentsArray = new FixtureIsolatedArray([
            'arg1' => new FixtureConsoleArgument('verbose'),
        ]);

        $mappedArgs = $argumentsArray->map(
            fn (FixtureConsoleArgument $arg, string $key) => $arg->name
        );
        expect($mappedArgs->value)->toBe(['arg1' => 'verbose']);

        $versionArray = new FixtureIsolatedArray([
            'Tempest' => '3.19.0',
            'PHP' => '8.4.0',
        ]);

        $mappedVersions = $versionArray->map(
            fn (string $version, string $key) => "{$key}: {$version}"
        );

        expect($mappedVersions->value)->toBe([
            'Tempest' => 'Tempest: 3.19.0',
            'PHP' => 'PHP: 8.4.0',
        ]);
    });
});