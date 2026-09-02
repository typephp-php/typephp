<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;

class TestWhereStatement
{
}

class TestWhereGroupStatement
{
}

class TestOtherUnrelatedStatement
{
}

/**
 * @template T
 */
class TestGenericCollection
{
    /**
     * @var array<int, T>
     */
    public array $items = [];
}

class TestQueryBuilderWithUnionProperty
{
    /**
     * @var TestGenericCollection<TestWhereStatement|TestWhereGroupStatement>
     */
    public TestGenericCollection $wheres;

    public function __construct()
    {
        $this->wheres = new TestGenericCollection();
    }
}

describe('Generic Container Union Member Assignability (Collection<A> into property Collection<A|B>)', function () {
    test('reproduces tempest CountQueryBuilder error when assigning Collection<WhereStatement> to property expecting Collection<WhereStatement|WhereGroupStatement>', function () {
        $builder = new TestQueryBuilderWithUnionProperty();

        /** @var TestGenericCollection<TestWhereStatement> $singleTypeWheres */
        $singleTypeWheres = new TestGenericCollection();

        $builder->wheres = $singleTypeWheres;

        expect($builder->wheres)->toBe($singleTypeWheres);
    });

    test('rejects assigning Collection<Unrelated> to property expecting Collection<WhereStatement|WhereGroupStatement>', function () {
        $builder = new TestQueryBuilderWithUnionProperty();

        /** @var TestGenericCollection<TestOtherUnrelatedStatement> $badWheres */
        $badWheres = new TestGenericCollection();

        expect(function () use ($builder, $badWheres) {
            $builder->wheres = $badWheres;
        })->toThrow(TypeError::class);
    });
});
