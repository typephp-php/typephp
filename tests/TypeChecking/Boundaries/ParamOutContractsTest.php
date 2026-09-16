<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

/**
 * 1. Basic scalar @param-out on void function
 *
 * @param mixed &$value
 *
 * @param-out positive-int $value
 */
function tddParamOutBasic(mixed &$value, bool $makeInvalid = false): void
{
    $value = $makeInvalid ? -50 : 42;
}

/**
 * 2. Tooling priority: @phpstan-param-out overrides @param-out
 *
 * @param mixed &$code
 *
 * @param-out int $code
 *
 * @phpstan-param-out positive-int $code
 */
function tddParamOutPriority(mixed &$code): void
{
    $code = -10; // Fails positive-int (from @phpstan-param-out)
}

/**
 * 3. @param-out on function returning a value
 *
 * @param mixed &$status
 *
 * @param-out 'active'|'pending' $status
 *
 * @return non-empty-string
 */
function tddParamOutWithReturn(mixed &$status, bool $makeInvalid = false): string
{
    $status = $makeInvalid ? 'archived' : 'active';

    return 'operation_complete';
}

/**
 * 4. @param-out with Array Shape
 *
 * @param array<string, mixed> &$payload
 *
 * @param-out array{id: positive-int, token: non-empty-string} $payload
 */
function tddParamOutArrayShape(array &$payload, bool $makeInvalid = false): void
{
    $payload = [
        'id' => $makeInvalid ? -1 : 10,
        'token' => 'sec_token_xyz',
    ];
}

/**
 * 5. Multiple parameters with @param-out
 *
 * @param mixed &$id
 * @param mixed &$name
 *
 * @param-out positive-int $id
 * @param-out non-empty-string $name
 */
function tddMultipleParamOut(mixed &$id, mixed &$name, bool $invalidId = false, bool $invalidName = false): void
{
    $id = $invalidId ? -1 : 100;
    $name = $invalidName ? '' : 'Alice';
}

/**
 * 6. Generics combined with @param-out
 *
 * @template T of Animal
 *
 * @param T $sample
 * @param mixed &$result
 *
 * @param-out T $result
 */
function tddGenericParamOut(Animal $sample, mixed &$result, bool $passCar = false): void
{
    $result = $passCar ? new Car() : $sample;
}

/**
 * 7. Class method @param-out
 */
class FixtureParamOutService
{
    /**
     * @param mixed &$data
     *
     * @param-out positive-int $data
     */
    public function mutate(mixed &$data, bool $invalid = false): void
    {
        $data = $invalid ? 0 : 999;
    }
}

/**
 * 8. Union in @param-out
 *
 * @param mixed &$val
 *
 * @param-out positive-int|non-empty-string $val
 */
function tddParamOutUnion(mixed &$val, mixed $newVal): void
{
    $val = $newVal;
}

/**
 * 9. Discriminated Union of Shapes in @param-out
 *
 * @param mixed &$payload
 *
 * @param-out array{type: 'A', id: positive-int} | array{type: 'B', code: non-empty-string} $payload
 */
function tddParamOutDiscriminatedUnion(mixed &$payload, mixed $newVal): void
{
    $payload = $newVal;
}

/**
 * 10. Interface Intersection in @param-out
 *
 * @param mixed &$collection
 *
 * @param-out Countable&ArrayAccess $collection
 */
function tddParamOutIntersection(mixed &$collection, mixed $newCollection): void
{
    $collection = $newCollection;
}

/**
 * 11. Shape Composition (Intersection of Shapes) in @param-out
 *
 * @param mixed &$data
 *
 * @param-out array{id: positive-int} & array{name: non-empty-string} $data
 */
function tddParamOutShapeComposition(mixed &$data, mixed $newData): void
{
    $data = $newData;
}

/**
 * Standalone @psalm-param-out
 *
 * @param mixed &$token
 *
 * @psalm-param-out non-empty-string $token
 */
function tddPsalmParamOutStandalone(mixed &$token, bool $makeInvalid = false): void
{
    $token = $makeInvalid ? '' : 'tok_valid_123';
}

/**
 * 3-Tier Priority: @phpstan-param-out > @psalm-param-out > @param-out
 *
 * @param mixed &$id
 *
 * @param-out mixed $id
 *
 * @psalm-param-out int $id
 *
 * @phpstan-param-out positive-int $id
 */
function tddTriplePriorityParamOut(mixed &$id): void
{
    $id = -5; // Fails @phpstan-param-out (positive-int), even though @psalm-param-out (int) would have allowed -5!
}

/**
 * Psalm overrides standard tag when phpstan tag is absent
 *
 * @param mixed &$code
 *
 * @param-out mixed $code
 *
 * @psalm-param-out non-empty-string $code
 */
function tddPsalmOverridesStandardParamOut(mixed &$code): void
{
    $code = ''; // Fails @psalm-param-out (non-empty-string)
}

describe('@param-out & @phpstan-param-out Contracts', function () {
    describe('1. Basic Scalar @param-out', function () {
        test('accepts valid post-condition mutation on by-ref parameter', function () {
            $val = 'initial_string';
            tddParamOutBasic($val, makeInvalid: false);

            expect($val)->toBe(42);
        });

        test('throws TypeError when function mutates by-ref parameter to invalid value', function () {
            $val = 'initial_string';

            expect(fn () => tddParamOutBasic($val, makeInvalid: true))
                ->toThrow(TypeError::class, 'Argument &$value (param-out) must be of type positive-int, negative int (-50) given')
            ;
        });
    });

    describe('2. Tooling Priority (@phpstan-param-out > @psalm-param-out > @param-out)', function () {
        test('enforces standalone @psalm-param-out tag', function () {
            $token = 'initial';

            tddPsalmParamOutStandalone($token, makeInvalid: false);
            expect($token)->toBe('tok_valid_123');

            expect(fn () => tddPsalmParamOutStandalone($token, makeInvalid: true))
                ->toThrow(TypeError::class, 'Argument &$token (param-out) must be of type non-empty-string')
            ;
        });

        test('prioritizes @psalm-param-out over @param-out when @phpstan-param-out is absent', function () {
            $code = 'init';

            expect(fn () => tddPsalmOverridesStandardParamOut($code))
                ->toThrow(TypeError::class, 'Argument &$code (param-out) must be of type non-empty-string')
            ;
        });

        test('prioritizes @phpstan-param-out over both @psalm-param-out and @param-out', function () {
            $id = 0;

            expect(fn () => tddTriplePriorityParamOut($id))
                ->toThrow(TypeError::class, 'Argument &$id (param-out) must be of type positive-int, negative int (-5) given')
            ;
        });
    });

    describe('3. Functions Returning Values with @param-out', function () {
        test('validates both return value and @param-out mutation', function () {
            $status = 'initial';
            $res = tddParamOutWithReturn($status, makeInvalid: false);

            expect($res)->toBe('operation_complete')
                ->and($status)->toBe('active')
            ;

            expect(fn () => tddParamOutWithReturn($status, makeInvalid: true))
                ->toThrow(TypeError::class, "Argument &\$status (param-out) must be of type ('active' | 'pending')")
            ;
        });
    });

    describe('4. Array Shapes in @param-out', function () {
        test('validates mutated array shape upon function exit', function () {
            $payload = [];
            tddParamOutArrayShape($payload, makeInvalid: false);

            expect($payload)->toBe([
                'id' => 10,
                'token' => 'sec_token_xyz',
            ]);

            $badPayload = [];
            expect(fn () => tddParamOutArrayShape($badPayload, makeInvalid: true))
                ->toThrow(TypeError::class, "Argument &\$payload (param-out)['id'] must be of type positive-int")
            ;
        });
    });

    describe('5. Multiple By-Ref Parameters with @param-out', function () {
        test('validates all mutated parameters upon exit', function () {
            $id = 0;
            $name = 'placeholder';

            tddMultipleParamOut($id, $name, invalidId: false, invalidName: false);
            expect($id)->toBe(100)->and($name)->toBe('Alice');

            expect(fn () => tddMultipleParamOut($id, $name, invalidId: true, invalidName: false))
                ->toThrow(TypeError::class, 'Argument &$id (param-out) must be of type positive-int')
            ;

            expect(fn () => tddMultipleParamOut($id, $name, invalidId: false, invalidName: true))
                ->toThrow(TypeError::class, 'Argument &$name (param-out) must be of type non-empty-string')
            ;
        });
    });

    describe('6. Generics Combined with @param-out', function () {
        test('enforces bound generic template on mutated by-ref parameter', function () {
            $dog = new Dog();
            $result = null;

            tddGenericParamOut($dog, $result, passCar: false);
            expect($result)->toBe($dog);

            expect(fn () => tddGenericParamOut($dog, $result, passCar: true))
                ->toThrow(TypeError::class, Dog::class)
            ;
        });
    });

    describe('7. Class Methods with @param-out', function () {
        test('validates by-ref mutations inside class methods upon exit', function () {
            $service = new FixtureParamOutService();
            $num = 5;

            $service->mutate($num, invalid: false);
            expect($num)->toBe(999);

            expect(fn () => $service->mutate($num, invalid: true))
                ->toThrow(TypeError::class, 'Argument &$data (param-out) must be of type positive-int')
            ;
        });
    });

    describe('8. Unions in @param-out', function () {
        test('accepts valid members matching scalar union in @param-out', function () {
            $val = null;
            tddParamOutUnion($val, 42);
            expect($val)->toBe(42);

            tddParamOutUnion($val, 'valid_code');
            expect($val)->toBe('valid_code');
        });

        test('rejects values violating all union members in @param-out', function () {
            $val = null;

            expect(fn () => tddParamOutUnion($val, -5))
                ->toThrow(TypeError::class, 'Argument &$val (param-out) must be of type (positive-int | non-empty-string)')
            ;

            expect(fn () => tddParamOutUnion($val, ''))
                ->toThrow(TypeError::class, 'Argument &$val (param-out) must be of type (positive-int | non-empty-string)')
            ;
        });

        test('diagnoses exact failing branch in discriminated union shape @param-out', function () {
            $payload = null;

            tddParamOutDiscriminatedUnion($payload, ['type' => 'A', 'id' => 10]);
            expect($payload)->toBe(['type' => 'A', 'id' => 10]);

            tddParamOutDiscriminatedUnion($payload, ['type' => 'B', 'code' => 'TOKEN']);
            expect($payload)->toBe(['type' => 'B', 'code' => 'TOKEN']);

            expect(fn () => tddParamOutDiscriminatedUnion($payload, ['type' => 'A', 'id' => -10]))
                ->toThrow(TypeError::class, "Argument &\$payload (param-out)['id'] must be of type positive-int")
            ;

            expect(fn () => tddParamOutDiscriminatedUnion($payload, ['type' => 'B', 'code' => '']))
                ->toThrow(TypeError::class, "Argument &\$payload (param-out)['code'] must be of type non-empty-string")
            ;
        });
    });

    describe('9. Intersections in @param-out', function () {
        test('accepts object implementing both interfaces for @param-out Countable&ArrayAccess', function () {
            $collection = null;
            $fixture = new CountableArrayAccess();

            tddParamOutIntersection($collection, $fixture);
            expect($collection)->toBe($fixture);
        });

        test('rejects object failing one interface in @param-out intersection with full contract message', function () {
            $collection = null;
            $onlyCountable = new CountableOnly();

            expect(fn () => tddParamOutIntersection($collection, $onlyCountable))
                ->toThrow(TypeError::class, 'Argument &$collection (param-out) must be of type (Countable & ArrayAccess)')
            ;
        });

        test('merges and validates composed array shapes in @param-out (ShapeA & ShapeB)', function () {
            $data = null;

            tddParamOutShapeComposition($data, ['id' => 10, 'name' => 'Alice']);
            expect($data)->toBe(['id' => 10, 'name' => 'Alice']);

            expect(fn () => tddParamOutShapeComposition($data, ['id' => -1, 'name' => 'Alice']))
                ->toThrow(TypeError::class, "Argument &\$data (param-out)['id'] must be of type positive-int")
            ;
            expect(fn () => tddParamOutShapeComposition($data, ['id' => 10, 'name' => '']))
                ->toThrow(TypeError::class, "Argument &\$data (param-out)['name'] must be of type non-empty-string")
            ;

            expect(fn () => tddParamOutShapeComposition($data, ['id' => 10, 'name' => 'Alice', 'extra' => true]))
                ->toThrow(TypeError::class, "Argument &\$data (param-out) contains unsealed unexpected key 'extra'")
            ;
        });
    });
});
