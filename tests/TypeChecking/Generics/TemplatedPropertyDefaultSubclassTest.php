<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

/**
 * @template TOptions of array<string, mixed>
 */
abstract class ReproTemplatedDefaultCommand
{
    /**
     * @var TOptions
     */
    protected array $options = [];

    public function __construct()
    {
    }

    /**
     * @param TOptions $options
     */
    public function init(array $options): static
    {
        $this->options = $options;

        return $this;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}

/**
 * Subclass specializing TOptions with a required shape key
 *
 * @extends ReproTemplatedDefaultCommand<array{name: string}>
 */
final class ReproTemplatedDefaultGetAgency extends ReproTemplatedDefaultCommand
{
}

/**
 * Control fixture: Non-templated class with an actually invalid default
 */
class ReproNonTemplatedInvalidDefaultFixture
{
    /**
     * @var positive-int
     */
    public int $count = -1;

    public function __construct()
    {
    }
}

describe('Templated Property Default Value in Subclass Instantiation (Bug Report Reproduction)', function () {
    test('allows constructing subclass when base property default is [] for template TOptions (Bug Report Reproduction)', function () {
        $agency = new ReproTemplatedDefaultGetAgency();

        expect($agency)->toBeInstanceOf(ReproTemplatedDefaultGetAgency::class)
            ->and($agency->getOptions())->toBe([])
        ;
    });

    test('still enforces subclass shape contract when init() or assignment runs after construction', function () {
        $agency = new ReproTemplatedDefaultGetAgency();

        $agency->init(['name' => 'Agency One']);
        expect($agency->getOptions())->toBe(['name' => 'Agency One']);

        expect(fn () => $agency->init(['invalid_key' => 123]))
            ->toThrow(TypeError::class, "is missing required key 'name'")
        ;
    });

    test('still enforces default value checks on non-templated properties with invalid defaults', function () {
        expect(fn () => new ReproNonTemplatedInvalidDefaultFixture())
            ->toThrow(TypeError::class, 'must be of type positive-int')
        ;
    });
});
