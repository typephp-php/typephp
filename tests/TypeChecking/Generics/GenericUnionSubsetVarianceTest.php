<?php

declare(strict_types=1);

class StmtA
{
}
class StmtB
{
}
class StmtC
{
}
class StmtUnrelated
{
}

/**
 * @template T
 */
class GenericUnionHolderFixture
{
    /**
     * @var array<int, T>
     */
    public array $items = [];
}

describe('Generic Union Subset Assignability (Collection<A|B> into Collection<A|B|C>)', function () {
    test('allows assigning subset union generic collection into broader superset union variable (Tempest WhereGroupBuilder pattern)', function () {
        /** @var GenericUnionHolderFixture<StmtA|StmtB|StmtC> $container */
        $container = new GenericUnionHolderFixture();

        /** @var GenericUnionHolderFixture<StmtA|StmtB> $subsetContainer */
        $subsetContainer = new GenericUnionHolderFixture();

        // Assigning Collection<A|B> into variable expecting Collection<A|B|C>
        $container = $subsetContainer;

        expect($container)->toBe($subsetContainer);
    });

    test('strictly rejects assigning collection with an incompatible type not in the superset union', function () {
        /** @var GenericUnionHolderFixture<StmtA|StmtB|StmtC> $container */
        $container = new GenericUnionHolderFixture();

        /** @var GenericUnionHolderFixture<StmtA|StmtUnrelated> $incompatibleContainer */
        $incompatibleContainer = new GenericUnionHolderFixture();

        expect(function () use (&$container, $incompatibleContainer) {
            $container = $incompatibleContainer;
        })->toThrow(TypeError::class);
    });
});
