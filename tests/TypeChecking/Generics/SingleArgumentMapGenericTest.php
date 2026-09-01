<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
use TypePHP\TypePHP;

/**
 * 2-Template Map fixture mirroring Tempest ImmutableArray<TKey of array-key, TValue>
 *
 * @template TKey of array-key = array-key
 * @template TValue = mixed
 */
class TestTwoTemplateMap
{
    /**
     * @var array<TKey, TValue>
     */
    public array $items = [];

    /**
     * @param TKey $key
     * @param TValue $value
     */
    public function put(mixed $key, mixed $value): void
    {
        $this->items[$key] = $value;
    }
}

class TestWhereStatement
{
}

class TestOtherEntity
{
}

class TestCountStatement
{
    /**
     * @param TestTwoTemplateMap<TestWhereStatement> $where
     */
    public function __construct(
        public TestTwoTemplateMap $where
    ) {
    }
}

describe('Single Generic Argument Shorthand on 2-Template Maps (Tempest ImmutableArray pattern)', function () {
    test('reproduces tempest CountStatement single generic argument on 2-template map', function () {
        $where = new TestTwoTemplateMap();

        $statement = new TestCountStatement($where);

        expect($statement->where)->toBe($where)
            ->and(TypePHP::getGenericType($where, 'TKey'))->toBe('array-key')
            ->and(TypePHP::getGenericType($where, 'TValue'))->toBe(TestWhereStatement::class)
        ;
    });

    test('enforces inferred TValue contract on single-argument map instance', function () {
        $where = new TestTwoTemplateMap();
        new TestCountStatement($where);

        $valid = new TestWhereStatement();
        $where->put('clause_1', $valid);
        expect($where->items['clause_1'])->toBe($valid);

        expect(fn () => $where->put('clause_2', new TestOtherEntity()))
            ->toThrow(TypeError::class, 'must be of type ' . TestWhereStatement::class)
        ;
    });
});
