<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;

/**
 * @template TOutput of array
 */
abstract class ReproInheritedQueryBase
{
    /**
     * Parent method returning template TOutput
     *
     * @return TOutput
     */
    public function run(): array
    {
        return $this->fetch();
    }

    /**
     * Abstract method returning template TOutput
     *
     * @return TOutput
     */
    abstract public function fetch(): array;
}

/**
 * @phpstan-type Row array{user_id: int, total: float}
 *
 * @extends ReproInheritedQueryBase<list<Row>>
 */
final class ReproTotalsQuery extends ReproInheritedQueryBase
{
    /**
     * Inherits @return TOutput from parent without local docblock
     */
    public function fetch(): array
    {
        return [
            ['user_id' => 1, 'total' => 2.5],
            ['user_id' => 2, 'total' => 10.0],
        ];
    }
}

/**
 * @phpstan-type InvalidRow array{user_id: positive-int, total: float}
 *
 * @extends ReproInheritedQueryBase<list<InvalidRow>>
 */
final class ReproInvalidTotalsQuery extends ReproInheritedQueryBase
{
    public function fetch(): array
    {
        return [
            ['user_id' => -1, 'total' => 2.5],
        ];
    }
}

/**
 * @template TResult
 */
interface ReproResultInterface
{
    /**
     * @return TResult
     */
    public function getResult(): array;
}

/**
 * @phpstan-type UserPayload array{id: positive-int, name: non-empty-string}
 *
 * @implements ReproResultInterface<UserPayload>
 */
final class ReproUserResultService implements ReproResultInterface
{
    public function getResult(): array
    {
        return ['id' => 42, 'name' => 'Alice'];
    }
}

describe('Subclass Type Alias in Inherited Tag (@extends and @implements with @phpstan-type)', function () {
    test('expands subclass type alias used in @extends tag when parent method returns template (Bug Report Reproduction)', function () {
        $query = new ReproTotalsQuery();

        $result = $query->run();

        expect($result)->toBe([
            ['user_id' => 1, 'total' => 2.5],
            ['user_id' => 2, 'total' => 10.0],
        ]);
    });

    test('expands subclass type alias when calling inherited method directly on subclass', function () {
        $query = new ReproTotalsQuery();

        $result = $query->fetch();

        expect($result)->toBe([
            ['user_id' => 1, 'total' => 2.5],
            ['user_id' => 2, 'total' => 10.0],
        ]);
    });

    test('still enforces the alias shape constraints when values violate the expanded type', function () {
        $invalidQuery = new ReproInvalidTotalsQuery();

        expect(fn () => $invalidQuery->run())
            ->toThrow(TypeError::class, "['user_id'] must be of type positive-int")
        ;
    });

    test('expands type alias used in @implements tag on interface methods', function () {
        $service = new ReproUserResultService();

        $result = $service->getResult();

        expect($result)->toBe(['id' => 42, 'name' => 'Alice']);
    });
});
