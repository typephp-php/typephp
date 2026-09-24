<?php

declare(strict_types=1);

namespace TypePHP\Tests\Unit;

use stdClass;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\DogRepository;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\Tests\Fixtures\Generics\Producer;
use TypePHP\TypePHP;

/**
 * @template ItemType
 */
class CustomTemplateNameBox
{
    /**
     * @var ItemType
     */
    public mixed $item = null;
}

/**
 * @template K
 * @template V
 */
class MultiTemplateDictionary
{
    /**
     * @var array<K, V>
     */
    public array $map = [];
}

/**
 * @template T
 * @template U
 */
class MultiTemplatePair
{
}

/**
 * @template A
 * @template B
 */
class MultiTemplateNoDefaults
{
}

/**
 * @template-covariant T
 *
 * @template-contravariant U
 */
class MultiVariancePair
{
}

/**
 * @template-covariant A
 *
 * @template-contravariant B
 */
class MultiVarianceNoDefaults
{
}

describe('TypePHP Public Facade Unit Tests', function () {
    afterEach(function () {
        TypePHP::resetConfig();
    });

    test('boots stream wrapper cleanly', function () {
        TypePHP::boot();
        expect(TypePHP::getConfig())->toBeArray();
    });

    test('gets resolved configuration using getConfig', function () {
        $config = TypePHP::getConfig();

        expect($config)->toBeArray()
            ->and($config)->toHaveKey('cache')
            ->and($config)->toHaveKey('inline_vars')
        ;
    });

    test('dynamically overrides configuration settings using setConfig', function () {
        TypePHP::setConfig([
            'inline_vars' => [
                'scalars' => false,
            ],
        ]);

        $config = TypePHP::getConfig();

        expect($config['inline_vars']['scalars'])->toBeFalse();
    });

    test('resets configuration using resetConfig', function () {
        TypePHP::setConfig(['cache' => false]);
        expect(TypePHP::getConfig()['cache'])->toBeFalse();

        TypePHP::resetConfig();
        expect(TypePHP::getConfig()['cache'])->toBeTrue();
    });

    test('inspects runtime reified generic types across single, custom, multi, and inherited template instances', function () {
        /** @var GenericCollection<Dog> $dogCollection */
        $dogCollection = new GenericCollection();

        /** @var GenericCollection<Cat> $catCollection */
        $catCollection = new GenericCollection();

        expect(TypePHP::getGenericType($dogCollection))->toBe(Dog::class)
            ->and(TypePHP::getGenericType($catCollection))->toBe(Cat::class)
            ->and(TypePHP::getGenericTypes($dogCollection))->toBe(['T' => Dog::class])
        ;

        /** @var CustomTemplateNameBox<Dog> $box */
        $box = new CustomTemplateNameBox();

        expect(TypePHP::getGenericType($box))->toBe(Dog::class)
            ->and(TypePHP::getGenericType($box, 'ItemType'))->toBe(Dog::class)
            ->and(TypePHP::getGenericTypes($box))->toBe(['ItemType' => Dog::class])
        ;

        /** @var MultiTemplateDictionary<string, Dog> $dict */
        $dict = new MultiTemplateDictionary();

        expect(TypePHP::getGenericType($dict, 'K'))->toBe('string')
            ->and(TypePHP::getGenericType($dict, 'V'))->toBe(Dog::class)
            ->and(TypePHP::getGenericTypes($dict))->toBe(['K' => 'string', 'V' => Dog::class])
        ;

        $dogRepo = new DogRepository();

        expect(TypePHP::getGenericType($dogRepo))->toBe(Dog::class)
            ->and(TypePHP::getGenericTypes($dogRepo))->toBe(['T' => Dog::class])
        ;

        $mystery = new GenericCollection();

        expect(TypePHP::getGenericType($mystery))->toBeNull()
            ->and(TypePHP::getGenericTypes($mystery))->toBeEmpty()
        ;

        $mystery->add(new Dog());

        expect(TypePHP::getGenericType($mystery))->toBe(Dog::class)
            ->and(TypePHP::getGenericTypes($mystery))->toBe(['T' => Dog::class])
        ;

        $pair = new MultiTemplatePair();
        TemplateManager::bindInstance($pair, MultiTemplatePair::class . '<' . Dog::class . ', ' . Cat::class . '>');
        expect(TypePHP::getGenericType($pair))->toBe(Dog::class);

        $noDefault = new MultiTemplateNoDefaults();
        TemplateManager::bindInstance($noDefault, MultiTemplateNoDefaults::class . '<' . Dog::class . ', ' . Cat::class . '>');
        expect(TypePHP::getGenericType($noDefault))->toBeNull();
    });

    test('inspects declared template variances on object instances', function () {
        expect(TypePHP::getGenericVariance(new stdClass()))->toBe('invariant');

        /** @var Producer<Dog> $producer */
        $producer = new Producer(new Dog());

        expect(TypePHP::getGenericVariance($producer))->toBe('covariant')
            ->and(TypePHP::getGenericVariance($producer, 'T'))->toBe('covariant')
            ->and(TypePHP::getGenericVariances($producer))->toBe(['T' => 'covariant'])
        ;

        $varPair = new MultiVariancePair();
        expect(TypePHP::getGenericVariance($varPair, 'U'))->toBe('contravariant');
        expect(TypePHP::getGenericVariance($varPair))->toBe('covariant');

        $noDefVar = new MultiVarianceNoDefaults();
        expect(TypePHP::getGenericVariance($noDefVar))->toBe('invariant');
    });
});
