<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Domain\User;
use TypePHP\TypePHP;

/**
 * @template-covariant K of array-key
 *
 * @template V
 */
class MixedVarianceKeyValueCatalog
{
    /**
     * @param K $k
     * @param V $v
     */
    public function __construct(
        public mixed $k,
        public mixed $v,
    ) {
    }

    /**
     * @param K $k
     */
    public function setKey(mixed $k): void
    {
        $this->k = $k;
    }

    /**
     * @param V $v
     */
    public function setValue(mixed $v): void
    {
        $this->v = $v;
    }
}

/**
 * 3-Template class mixing contravariant, covariant, and invariant in order
 *
 * @template-contravariant TIn of Animal
 *
 * @template-covariant TOut of Animal
 *
 * @template TState
 */
class MixedThreeVariancePipeline
{
    /**
     * @param TIn $input
     * @param TOut $output
     * @param TState $state
     */
    public function __construct(
        public mixed $input,
        public mixed $output,
        public mixed $state,
    ) {
    }
}

/**
 * Mixed variance with @phpstan-template bound refinement on the first template
 *
 * @template-covariant K
 *
 * @template V
 *
 * @phpstan-template-covariant K of array-key
 */
class RefinedMixedVarianceCatalog
{
    /**
     * @param K $k
     * @param V $v
     */
    public function __construct(
        public mixed $k,
        public mixed $v,
    ) {
    }
}

describe('Mixed Variance Multi-Template Declaration Order', function () {
    test('preserves declaration order when @template-covariant precedes @template (Bug Reproduction)', function () {
        /** @var MixedVarianceKeyValueCatalog<string, User> $catalog */
        $catalog = new MixedVarianceKeyValueCatalog('prod_01', new User());

        expect(TypePHP::getGenericType($catalog, 'K'))->toBe('string')
            ->and(TypePHP::getGenericType($catalog, 'V'))->toBe(User::class)
            ->and(TypePHP::getGenericTypes($catalog))->toBe([
                'K' => 'string',
                'V' => User::class,
            ])
            ->and(TypePHP::getGenericVariance($catalog, 'K'))->toBe('covariant')
            ->and(TypePHP::getGenericVariance($catalog, 'V'))->toBe('invariant')
            ->and(TypePHP::getGenericVariances($catalog))->toBe([
                'K' => 'covariant',
                'V' => 'invariant',
            ])
        ;
    });

    test('enforces parameter contracts against the correct positional templates K and V', function () {
        /** @var MixedVarianceKeyValueCatalog<string, Dog> $catalog */
        $catalog = new MixedVarianceKeyValueCatalog('dog_01', new Dog());

        $catalog->setKey('dog_02');
        $catalog->setValue(new Dog());

        expect(fn () => $catalog->setKey(12345))
            ->toThrow(TypeError::class, 'Argument $k (template K = string) must be of type string')
        ;

        expect(fn () => $catalog->setValue(new Car()))
            ->toThrow(TypeError::class, 'Argument $v (template V = ' . Dog::class . ') must be of type ' . Dog::class)
        ;
    });

    test('enforces upper bound on K when first generic type argument violates array-key', function () {
        expect(function () {
            /** @var MixedVarianceKeyValueCatalog<User, string> $badCatalog */
            $badCatalog = new MixedVarianceKeyValueCatalog('valid_key', 'val');
        })->toThrow(
            TypeError::class,
            'Generic type argument ' . User::class . ' does not satisfy upper bound array-key of template K'
        );

        expect(function () {
            new MixedVarianceKeyValueCatalog(new User(), 'val');
        })->toThrow(
            TypeError::class,
            'Argument $k (template K) must be of type array-key, ' . User::class . ' given'
        );
    });

    test('enforces per-template variance rules (covariant K vs invariant V) on reassignment', function () {
        /** @var MixedVarianceKeyValueCatalog<non-empty-string, Dog> $narrowKeyCatalog */
        $narrowKeyCatalog = new MixedVarianceKeyValueCatalog('dog_01', new Dog());

        /** @var MixedVarianceKeyValueCatalog<string, Dog> $broadKeyCatalog */
        $broadKeyCatalog = $narrowKeyCatalog;
        expect($broadKeyCatalog)->toBe($narrowKeyCatalog);

        expect(function () use ($narrowKeyCatalog) {
            /** @var MixedVarianceKeyValueCatalog<string, Animal> $invalidValueVariance */
            $invalidValueVariance = $narrowKeyCatalog;
        })->toThrow(TypeError::class);
    });

    test('preserves declaration order across 3 templates with contravariant, covariant, and invariant tags', function () {
        /** @var MixedThreeVariancePipeline<Dog, Dog, positive-int> $pipeline */
        $pipeline = new MixedThreeVariancePipeline(new Dog(), new Dog(), 10);

        expect(TypePHP::getGenericTypes($pipeline))->toBe([
            'TIn' => Dog::class,
            'TOut' => Dog::class,
            'TState' => 'positive-int',
        ])->and(TypePHP::getGenericVariances($pipeline))->toBe([
            'TIn' => 'contravariant',
            'TOut' => 'covariant',
            'TState' => 'invariant',
        ]);

        // TIn is contravariant (Animal >: Dog), TOut is covariant (Dog <: Animal), TState is invariant (int === int)
        /** @var MixedThreeVariancePipeline<Animal, Dog, int> $source */
        $source = new MixedThreeVariancePipeline(new Dog(), new Dog(), 42);

        /** @var MixedThreeVariancePipeline<Dog, Animal, int> $target */
        $target = $source;
        expect($target)->toBe($source);

        // Violating contravariance on TIn: $pipeline has TIn = Dog, which is NOT a supertype of Animal or Cat
        expect(function () use ($pipeline) {
            /** @var MixedThreeVariancePipeline<Animal, Animal, positive-int> $badTarget */
            $badTarget = $pipeline;
        })->toThrow(TypeError::class);
    });

    test('preserves declaration order when @phpstan-template refines an earlier template', function () {
        /** @var RefinedMixedVarianceCatalog<string, User> $catalog */
        $catalog = new RefinedMixedVarianceCatalog('id_1', new User());

        expect(TypePHP::getGenericTypes($catalog))->toBe([
            'K' => 'string',
            'V' => User::class,
        ])->and(TypePHP::getGenericVariances($catalog))->toBe([
            'K' => 'covariant',
            'V' => 'invariant',
        ]);

        expect(function () {
            /** @var RefinedMixedVarianceCatalog<User, string> $bad */
            $bad = new RefinedMixedVarianceCatalog('id_1', 'val');
        })->toThrow(
            TypeError::class,
            'Generic type argument ' . User::class . ' does not satisfy upper bound array-key of template K'
        );
    });
});
