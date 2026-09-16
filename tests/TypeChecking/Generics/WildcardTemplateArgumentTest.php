<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

interface WildcardEntityInterface
{
    public function getId(): string;
}

/**
 * @template TEntity of WildcardEntityInterface
 */
interface WildcardEntityIdInterface
{
    public function getValue(): string;
}

class WildcardOrderEntity implements WildcardEntityInterface
{
    public function getId(): string
    {
        return 'order-1';
    }
}

/**
 * @implements WildcardEntityIdInterface<WildcardOrderEntity>
 */
class WildcardOrderEntityId implements WildcardEntityIdInterface
{
    public function getValue(): string
    {
        return 'order-1';
    }
}

/**
 * Circular / Self-Referential Generic Interfaces
 *
 * @template TId of WildcardCircularIdInterface<*>
 */
interface WildcardCircularEntityInterface
{
}

/**
 * @template TEntity of WildcardCircularEntityInterface<*>
 */
interface WildcardCircularIdInterface
{
    public function getValue(): string;
}

class WildcardCircularOrder implements WildcardCircularEntityInterface
{
}

/**
 * @implements WildcardCircularIdInterface<WildcardCircularOrder>
 */
class WildcardCircularOrderId implements WildcardCircularIdInterface
{
    public function getValue(): string
    {
        return 'ord-123';
    }
}

/**
 * Multi-template generic class
 *
 * @template K of array-key
 * @template V of WildcardEntityInterface
 */
class WildcardDictionary
{
    /**
     * @var array<K, V>
     */
    public array $items = [];

    /**
     * @param K $key
     * @param V $val
     */
    public function put(mixed $key, mixed $val): void
    {
        $this->items[$key] = $val;
    }
}

class WildcardProbeService
{
    /**
     * Single wildcard parameter: EntityIdInterface<*>
     *
     * @param WildcardEntityIdInterface<*> $id
     */
    public function processWildcardId(WildcardEntityIdInterface $id): string
    {
        return $id->getValue();
    }

    /**
     * Self-referential circular wildcard parameter
     *
     * @param WildcardCircularIdInterface<*> $id
     */
    public function processCircularWildcardId(WildcardCircularIdInterface $id): string
    {
        return $id->getValue();
    }

    /**
     * Multi-template wildcard parameter: Dictionary<*, *>
     *
     * @param WildcardDictionary<*, *> $dict
     */
    public function processAnyDictionary(WildcardDictionary $dict): int
    {
        return \count($dict->items);
    }

    /**
     * Partial wildcard parameter: Dictionary<string, *>
     *
     * @param WildcardDictionary<string, *> $dict
     */
    public function processStringKeyDictionary(WildcardDictionary $dict): int
    {
        return \count($dict->items);
    }

    /**
     * Partial wildcard parameter: Dictionary<int, *>
     *
     * @param WildcardDictionary<int, *> $dict
     */
    public function processIntKeyDictionary(WildcardDictionary $dict): int
    {
        return \count($dict->items);
    }

    /**
     * Wildcard return type
     *
     * @return WildcardEntityIdInterface<*>
     */
    public function getWildcardId(): WildcardEntityIdInterface
    {
        return new WildcardOrderEntityId();
    }
}

describe('Wildcard Generic Template Arguments (Class<*>)', function () {
    describe('1. Single-Template Wildcard Parameters (EntityIdInterface<*>)', function () {
        test('accepts concrete instance satisfying template upper bound when parameter specifies <*>', function () {
            $probe = new WildcardProbeService();
            $orderId = new WildcardOrderEntityId();

            $result = $probe->processWildcardId($orderId);

            expect($result)->toBe('order-1');
        });

        test('accepts self-referential circular generic instance when parameter specifies <*>', function () {
            $probe = new WildcardProbeService();
            $circularId = new WildcardCircularOrderId();

            $result = $probe->processCircularWildcardId($circularId);

            expect($result)->toBe('ord-123');
        });
    });

    describe('2. Multi-Template Wildcards (Dictionary<*, *> and Dictionary<string, *>)', function () {
        test('accepts dictionary with any key and value type when parameter specifies <*, *>', function () {
            $probe = new WildcardProbeService();

            /** @var WildcardDictionary<string, WildcardOrderEntity> $dict */
            $dict = new WildcardDictionary();
            $dict->put('first', new WildcardOrderEntity());

            expect($probe->processAnyDictionary($dict))->toBe(1);
        });

        test('accepts dictionary matching specified key type with wildcard value type <string, *>', function () {
            $probe = new WildcardProbeService();

            /** @var WildcardDictionary<string, WildcardOrderEntity> $dict */
            $dict = new WildcardDictionary();
            $dict->put('order_key', new WildcardOrderEntity());

            expect($probe->processStringKeyDictionary($dict))->toBe(1);
        });

        test('rejects dictionary when concrete key type conflicts with specified key type in <int, *>', function () {
            $probe = new WildcardProbeService();

            /** @var WildcardDictionary<string, WildcardOrderEntity> $dict */
            $dict = new WildcardDictionary();
            $dict->put('order_key', new WildcardOrderEntity());

            expect(fn () => $probe->processIntKeyDictionary($dict))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('3. Return Types & Inline @var with Wildcards', function () {
        test('accepts concrete generic instance returned from method promising Class<*>', function () {
            $probe = new WildcardProbeService();

            $result = $probe->getWildcardId();

            expect($result)->toBeInstanceOf(WildcardOrderEntityId::class);
        });

        test('enforces inline @var assignment with wildcard Class<*>', function () {
            $orderId = new WildcardOrderEntityId();

            /** @var WildcardEntityIdInterface<*> $id */
            $id = $orderId;

            expect($id)->toBe($orderId);
        });
    });
});
