<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\RuntimeTypeChecker;

/**
 * @template K of array-key
 * @template V
 */
interface UnionStore
{
}

/**
 * @template K of array-key
 * @template V
 *
 * @implements UnionStore<K, V>
 */
class ConcreteUnionStore implements UnionStore
{
}

/**
 * @template K of array-key
 * @template V
 */
class UnionView
{
    /**
     * @param UnionStore<K, V>|ConcreteUnionStore<K, V> $store
     */
    public function __construct(public mixed $store)
    {
    }

    /**
     * @param K $key
     */
    public function putKey(mixed $key): void
    {
    }
}

/**
 * @template K
 * @template V
 */
class MyMapEntry
{
}

/**
 * @template T
 */
interface MyElementStore
{
}

/**
 * @template K of array-key
 * @template V
 *
 * @implements MyElementStore<MyMapEntry<K, V>>
 */
class MyMapEntryStore implements MyElementStore
{
    public function __construct()
    {
    }
}

describe('Generics: Union Type Inference & Unbound Template Fallbacks', function () {

    test('infers generic types correctly when constructor parameter uses a union of generic types', function () {
        $store = new ConcreteUnionStore();

        RuntimeTypeChecker::bindInstanceFromNode(
            $store,
            new GenericTypeNode(new IdentifierTypeNode(ConcreteUnionStore::class), [
                new IdentifierTypeNode('string'),
                new IdentifierTypeNode('int'),
            ]),
            '',
            true
        );

        $view = new UnionView($store);

        expect(function () use ($view) {
            $view->putKey(123);
        })->toThrow(TypeError::class, 'must be of type string');
    });

    test('unbound templates in implemented interfaces fall back to their declared bounds instead of literal strings', function () {
        $set = new class () {
            /**
             * @var MyElementStore<MyMapEntry<array-key, mixed>>
             */
            public mixed $store;

            public function assign(): void
            {
                $this->store = new MyMapEntryStore();
            }
        };

        $set->assign();

        expect(true)->toBeTrue();
    });

});
