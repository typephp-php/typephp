<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class TestBaseEntity
{
}
class TestSpecificEntity extends TestBaseEntity
{
}
class TestUnrelatedEntity
{
}

/**
 * @template T of TestBaseEntity
 */
class TestBaseEntityCollection
{
}

/**
 * Child collection extending generic parent (like SalesChannelCollection extends EntityCollection)
 *
 * @extends TestBaseEntityCollection<TestSpecificEntity>
 */
class TestSpecificEntityCollection extends TestBaseEntityCollection
{
}

class TestUnrelatedCollection
{
}

/**
 * Covariant Repository (like EntityRepository with @template-covariant TCollection)
 *
 * @template-covariant TCollection of TestBaseEntityCollection
 */
class TestCovariantEntityRepository
{
    /**
     * @param TCollection $collection
     */
    public function __construct(public mixed $collection)
    {
    }
}

/**
 * Function mirroring CustomerLanguageSalesChannelSubscriber constructor
 *
 * @param TestCovariantEntityRepository<TestBaseEntityCollection<TestBaseEntity>> $repo
 */
function consumeCovariantEntityRepository(TestCovariantEntityRepository $repo): bool
{
    return true;
}

describe('Covariant Generic Subclass Parameter Assignability (Shopware DAL Pattern)', function () {
    test('accepts covariant repository holding child collection class without explicit generic args', function () {
        $specificCollection = new TestSpecificEntityCollection();
        $repo = new TestCovariantEntityRepository($specificCollection);

        expect(consumeCovariantEntityRepository($repo))->toBeTrue();
    });

    test('throws TypeError when repository holds an unrelated collection class', function () {
        $unrelatedCollection = new TestUnrelatedCollection();

        expect(fn () => new TestCovariantEntityRepository($unrelatedCollection))
            ->toThrow(TypeError::class, 'must be of type TestBaseEntityCollection')
        ;
    });
});
