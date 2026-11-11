<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

/**
 * Single-template interface representing a generic container (like Set<E> or Collection<E>).
 *
 * @template TElement
 */
interface InheritedContainer
{
    /**
     * @return TElement
     */
    public function first(): mixed;
}

/**
 * Two-template data class (like MapEntry<K, V>).
 *
 * @template TKey of string|int
 * @template TValue
 */
class InheritedPair
{
    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function __construct(public mixed $key, public mixed $value)
    {
    }
}

/**
 * Generic class implementing an interface parameterized by a compound type that
 * forwards its own declared templates: @implements InheritedContainer<InheritedPair<K, V>>.
 *
 * (Mirrors MapEntrySet: @implements Set<MapEntry<K, V>>)
 *
 * @template K of string|int
 * @template V
 *
 * @implements InheritedContainer<InheritedPair<K, V>>
 */
class InheritedPairContainer implements InheritedContainer
{
    /**
     * @param K $key
     * @param V $value
     */
    public function __construct(public mixed $key, public mixed $value)
    {
    }

    /**
     * @return InheritedPair<K, V>
     */
    public function first(): InheritedPair
    {
        return new InheritedPair($this->key, $this->value);
    }
}

/**
 * Service holding a specialized container property (like Map::$entries).
 */
class InheritedConsumer
{
    /**
     * @var InheritedContainer<InheritedPair<string, int>>
     */
    public InheritedContainer $container;

    /**
     * @param InheritedContainer<InheritedPair<string, int>> $container
     */
    public function setContainer(InheritedContainer $container): void
    {
        $this->container = $container;
    }

    /**
     * @return InheritedContainer<InheritedPair<string, int>>
     */
    public function getContainer(): InheritedContainer
    {
        return $this->container;
    }
}

describe('Inherited Generic Template Forwarding (Compound Template Mapping)', function () {
    test('parameter check preserves specialized child templates on inherited interface', function () {
        $consumer = new InheritedConsumer();

        $container = new InheritedPairContainer('age', 30);

        $consumer->setContainer($container);

        expect($consumer->container->first()->key)->toBe('age');
        expect($consumer->container->first()->value)->toBe(30);
    });

    test('return check preserves specialized child templates on inherited interface', function () {
        $consumer = new InheritedConsumer();
        $consumer->container = new InheritedPairContainer('age', 30);

        $returned = $consumer->getContainer();

        expect($returned)->toBeInstanceOf(InheritedContainer::class);
    });
});
