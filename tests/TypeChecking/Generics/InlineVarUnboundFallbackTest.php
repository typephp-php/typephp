<?php

declare(strict_types=1);

if (PHP_VERSION_ID < 80400) {
    return;
}

use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\TypePHP;

require_once __DIR__ . '/../../Fixtures/Generics/NoctudReproFixture.php';

/**
 * Mirrors Noctud\Collection\Map\HashMap\ImmutableHashMap (static::of + inline @var + empty/lazy store).
 *
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @implements ReproMapInterface<K, V>
 */
final class NoctudStaticFactoryMapFixture implements ReproMapInterface
{
    /** @use ReproMapLogic<K, V> */
    use ReproMapLogic;

    public function __construct()
    {
        $this->store = ReproHashStore::fromAssoc([]);
    }

    /**
     * @template NK of string|int|bool|float|object
     * @template NV
     *
     * @param iterable<NK, NV>|Closure(): iterable<NK, NV> $data
     *
     * @return ReproMapInterface<NK, NV>
     */
    public static function of(iterable|Closure $data = []): ReproMapInterface
    {
        /** @var NoctudStaticFactoryMapFixture<NK, NV> $map */
        $map = new self();

        if ($data instanceof Closure) {
            $reflector = new ReflectionClass(ReproHashStore::class);
            $map->store = $reflector->newLazyProxy(
                fn (ReproHashStore $object): ReproHashStore => $object::fromAssoc($data())
            );
        } elseif ($data instanceof ReproHashStore) {
            $map->store = clone $data;
        } else {
            $map->store = ReproHashStore::fromAssoc($data);
        }

        return $map;
    }

    /**
     * Mirrors Noctud MapLogic::takeFirst(0) returning newMapOf([]).
     *
     * @return ReproMapInterface<K, V>
     */
    public function takeFirst(int $n = 1): ReproMapInterface
    {
        if ($n <= 0) {
            return self::of([]);
        }

        return self::of(clone $this->store);
    }

    /**
     * Mirrors Noctud MapLogic::sortedByKey() cloning $this->store and returning newMapOf($store).
     *
     * @return ReproMapInterface<K, V>
     */
    public function sortedByKey(): ReproMapInterface
    {
        $store = clone $this->store;

        return self::of($store);
    }

    /**
     * @return ReproKeyValueStore<K, V>
     */
    public function getStore(): ReproKeyValueStore
    {
        return $this->store;
    }
}

/**
 * Mirrors Noctud's ImmutableLazyHashMapTest::mapOf().
 *
 * @template K of string|int|bool|float|object
 * @template V
 *
 * @param iterable<K, V>|Closure(): iterable<K, V> $data
 *
 * @return ReproMapInterface<K, V>
 */
function reproNoctudLazyStaticMapOf(iterable|Closure $data): ReproMapInterface
{
    if (! \is_callable($data)) {
        return NoctudStaticFactoryMapFixture::of(fn () => $data);
    }

    return NoctudStaticFactoryMapFixture::of($data);
}

/**
 * Negative control: explicitly wide union bound (NOT an unbound template) must still fail invariant return.
 *
 * @return ReproMapInterface<string, int>
 */
function reproReturnExplicitlyWideMapAsNarrow(): ReproMapInterface
{
    /** @var NoctudStaticFactoryMapFixture<string|int|bool|float|object, mixed> $wide */
    $wide = new NoctudStaticFactoryMapFixture();

    return $wide;
}

describe('Noctud Static Factory Inline @var Unbound Template Fallback', function () {
    test('lazy mapOf() via static::of() refines both map and lazy store without initializing proxy', function () {
        $map = reproNoctudLazyStaticMapOf(['a' => 1, 'b' => 2]);
        \assert($map instanceof NoctudStaticFactoryMapFixture);

        $storeRef = new ReflectionClass(ReproHashStore::class);

        expect($storeRef->isUninitializedLazyObject($map->getStore()))->toBeTrue()
            ->and(TypePHP::getGenericTypes($map))->toBe(['K' => 'string', 'V' => 'int'])
            ->and(TypePHP::getGenericTypes($map->getStore()))->toBe(['K' => 'string', 'V' => 'int'])
        ;

        expect(iterator_to_array($map->keys))->toBe(['a', 'b']);

        $sorted = $map->sortedByKey();
        expect(iterator_to_array($sorted->keys))->toBe(['a', 'b']);
    });

    test('takeFirst(0) returning empty static::of([]) specializes to caller map types', function () {
        $map = NoctudStaticFactoryMapFixture::of(['a' => 1, 'b' => 2]);
        \assert($map instanceof NoctudStaticFactoryMapFixture);

        $empty = $map->takeFirst(0);

        expect(TypePHP::getGenericTypes($empty))->toBe(['K' => 'string', 'V' => 'int']);
    });

    test('inline @var narrowing on empty static::of([]) succeeds', function () {
        /** @var ReproMapInterface<string, string> $map */
        $map = NoctudStaticFactoryMapFixture::of([]);

        expect(TypePHP::getGenericTypes($map))->toBe(['K' => 'string', 'V' => 'string']);
    });

    test('keeps strict invariance for explicitly typed wide union bounds', function () {
        expect(fn () => reproReturnExplicitlyWideMapAsNarrow())
            ->toThrow(TypePHPTypeError::class, 'expects ReproMapInterface<invariant string>')
        ;
    });
});
