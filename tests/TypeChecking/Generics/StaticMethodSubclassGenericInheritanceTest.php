<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

interface StaticGenericPBase
{
}

final class StaticGenericPA implements StaticGenericPBase
{
    public function __construct(public string $name = 'PA')
    {
    }
}

final class StaticGenericPB implements StaticGenericPBase
{
    public function __construct(public string $name = 'PB')
    {
    }
}

final class StaticGenericUnrelated
{
}

/**
 * @template TEntity of StaticGenericPBase
 */
abstract class StaticGenericIdBase
{
    /**
     * @param non-empty-string|TEntity|StaticGenericIdBase<TEntity> $value
     *
     * @return static
     */
    public static function from(string|StaticGenericPBase|StaticGenericIdBase $value): static
    {
        return new static();
    }

    /**
     * @param TEntity $entity
     *
     * @return static
     */
    public static function fromEntity(StaticGenericPBase $entity): static
    {
        return new static();
    }

    /**
     * @return TEntity
     */
    public static function produceA(): StaticGenericPBase
    {
        return new StaticGenericPA();
    }

    /**
     * @return TEntity
     */
    public static function produceB(): StaticGenericPBase
    {
        return new StaticGenericPB();
    }

    /**
     * @return list<TEntity>
     */
    public static function produceList(): array
    {
        return [new StaticGenericPA()];
    }
}

/**
 * @extends StaticGenericIdBase<StaticGenericPA>
 */
final class StaticGenericAId extends StaticGenericIdBase
{
}

/**
 * @extends StaticGenericIdBase<StaticGenericPBase>
 */
final class StaticGenericAnyId extends StaticGenericIdBase
{
}

describe('Static Methods Resolving Subclass @extends Generic Bindings', function () {
    describe('Static Parameter Contracts', function () {
        test('resolves TEntity as PA from @extends StaticGenericIdBase<PA> on static method calls (Case a)', function () {
            $first = StaticGenericAId::from('sample_id');
            expect($first)->toBeInstanceOf(StaticGenericAId::class);

            $second = StaticGenericAId::from($first);
            expect($second)->toBeInstanceOf(StaticGenericAId::class);
        });

        test('resolves TEntity as PBase from @extends StaticGenericIdBase<PBase> and accepts multiple valid subclasses across calls (Case b)', function () {
            $first = StaticGenericAnyId::fromEntity(new StaticGenericPA());
            expect($first)->toBeInstanceOf(StaticGenericAnyId::class);

            $second = StaticGenericAnyId::fromEntity(new StaticGenericPB());
            expect($second)->toBeInstanceOf(StaticGenericAnyId::class);
        });

        test('strictly rejects entity not matching the subclass @extends binding', function () {
            expect(fn () => StaticGenericAId::fromEntity(new StaticGenericPB()))
                ->toThrow(TypeError::class)
            ;
        });
    });

    describe('Static Return Type Contracts', function () {
        test('validates static method @return TEntity matches subclass @extends binding', function () {
            $result = StaticGenericAId::produceA();
            expect($result)->toBeInstanceOf(StaticGenericPA::class);
            expect(StaticGenericAnyId::produceA())->toBeInstanceOf(StaticGenericPA::class);
            expect(StaticGenericAnyId::produceB())->toBeInstanceOf(StaticGenericPB::class);
        });

        test('throws TypeError when static method @return TEntity violates subclass @extends binding', function () {
            expect(fn () => StaticGenericAId::produceB())
                ->toThrow(
                    TypeError::class,
                    'Return value must be of type ' . StaticGenericPA::class . ', ' . StaticGenericPB::class . ' returned'
                )
            ;
        });

        test('validates list return types list<TEntity> on static methods', function () {
            $list = StaticGenericAId::produceList();
            expect($list)->toHaveCount(1)
                ->and($list[0])->toBeInstanceOf(StaticGenericPA::class)
            ;
        });
    });
});
