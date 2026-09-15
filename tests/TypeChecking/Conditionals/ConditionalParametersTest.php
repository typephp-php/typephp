<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Types\CountableArrayAccess;
use TypePHP\Tests\Fixtures\Types\CountableOnly;

/**
 * 1. Basic boolean parameter conditional
 *
 * @param ($asInt is true ? positive-int : non-empty-string) $value
 */
function tddBasicParamConditional(bool $asInt, mixed $value): mixed
{
    return $value;
}

/**
 * 2. String literal discriminator parameter conditional
 *
 * @param 'json'|'xml' $format
 * @param ($format is 'json' ? array<string, mixed> : non-empty-string) $payload
 */
function tddFormatParamConditional(string $format, mixed $payload): mixed
{
    return $payload;
}

/**
 * 3. Negated parameter conditional ($mode is not 'raw')
 *
 * @param ($mode is not 'raw' ? array{id: positive-int} : string) $data
 */
function tddNegatedParamConditional(string $mode, mixed $data): mixed
{
    return $data;
}

/**
 * 4. Multi-branch 4-level nested parameter conditional
 *
 * @param ($type is 'int' ? positive-int : ($type is 'float' ? positive-float : ($type is 'bool' ? bool : non-empty-string))) $val
 */
function tddMultiBranchParamConditional(string $type, mixed $val): mixed
{
    return $val;
}

/**
 * 5. Generics inside conditional parameter (T is Dog ? list<T> : T)
 *
 * @template T of Animal
 *
 * @param T $animal
 * @param (T is Dog ? list<T> : T) $output
 */
function tddGenericConditionalParam(Animal $animal, mixed $output): mixed
{
    return $output;
}

/**
 * 6. Union inside conditional branch ($asScalar is true ? (positive-int|non-empty-string) : array{id: positive-int})
 *
 * @param ($asScalar is true ? (positive-int|non-empty-string) : array{id: positive-int}) $item
 */
function tddUnionParamConditional(bool $asScalar, mixed $item): mixed
{
    return $item;
}

/**
 * 7. Intersection inside conditional branch ($mode is 'complex' ? (Countable&ArrayAccess) : Countable)
 *
 * @param ($mode is 'complex' ? (Countable&ArrayAccess) : Countable) $collection
 */
function tddIntersectionParamConditional(string $mode, object $collection): object
{
    return $collection;
}

/**
 * 8. Parameter conditional on method with default argument
 */
class FixtureConditionalParamService
{
    /**
     * @param ($strict is true ? positive-int : int) $code
     */
    public function executeAction(int $code, bool $strict = false): int
    {
        return $code;
    }
}

describe('Conditional Parameter Contracts (@param ($condition ? A : B))', function () {
    describe('1. Basic Parameter Conditionals', function () {
        test('validates positive-int when asInt is true', function () {
            expect(tddBasicParamConditional(true, 42))->toBe(42);

            expect(fn () => tddBasicParamConditional(true, -5))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => tddBasicParamConditional(true, 'not_an_int'))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('validates non-empty-string when asInt is false', function () {
            expect(tddBasicParamConditional(false, 'active_user'))->toBe('active_user');

            expect(fn () => tddBasicParamConditional(false, ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;

            expect(fn () => tddBasicParamConditional(false, 123))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('2. String Literal / Format Discriminators', function () {
        test('enforces array payload when format is json', function () {
            expect(tddFormatParamConditional('json', ['status' => 'ok']))->toBe(['status' => 'ok']);

            expect(fn () => tddFormatParamConditional('json', '<xml></xml>'))
                ->toThrow(TypeError::class, 'array')
            ;
        });

        test('enforces string payload when format is xml', function () {
            expect(tddFormatParamConditional('xml', '<xml></xml>'))->toBe('<xml></xml>');

            expect(fn () => tddFormatParamConditional('xml', ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;

            expect(fn () => tddFormatParamConditional('xml', ['status' => 'ok']))
                ->toThrow(TypeError::class, 'string')
            ;
        });
    });

    describe('3. Negated Parameter Conditionals ($mode is not "raw")', function () {
        test('enforces array shape when mode is not raw', function () {
            expect(tddNegatedParamConditional('structured', ['id' => 10]))->toBe(['id' => 10]);

            expect(fn () => tddNegatedParamConditional('structured', ['id' => -10]))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => tddNegatedParamConditional('structured', 'raw_string'))
                ->toThrow(TypeError::class, 'array')
            ;
        });

        test('enforces string when mode is raw', function () {
            expect(tddNegatedParamConditional('raw', 'raw_binary_data'))->toBe('raw_binary_data');

            expect(fn () => tddNegatedParamConditional('raw', 12345))
                ->toThrow(TypeError::class, 'string')
            ;
        });
    });

    describe('4. Multi-Branch Nested Parameter Conditionals', function () {
        test('validates positive-int for type int', function () {
            expect(tddMultiBranchParamConditional('int', 100))->toBe(100);

            expect(fn () => tddMultiBranchParamConditional('int', -10))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });

        test('validates positive-float for type float', function () {
            expect(tddMultiBranchParamConditional('float', 3.14))->toBe(3.14);

            expect(fn () => tddMultiBranchParamConditional('float', -2.5))
                ->toThrow(TypeError::class, 'positive-float')
            ;
        });

        test('validates bool for type bool', function () {
            expect(tddMultiBranchParamConditional('bool', true))->toBeTrue();

            expect(fn () => tddMultiBranchParamConditional('bool', 'not_a_bool'))
                ->toThrow(TypeError::class, 'bool')
            ;
        });

        test('validates non-empty-string fallback for unknown type', function () {
            expect(tddMultiBranchParamConditional('custom', 'valid_text'))->toBe('valid_text');

            expect(fn () => tddMultiBranchParamConditional('custom', ''))
                ->toThrow(TypeError::class, 'non-empty-string')
            ;
        });
    });

    describe('5. Generics Combined with Conditional Parameters', function () {
        test('enforces list<Dog> when animal is Dog', function () {
            $dog = new Dog();
            $dogList = [$dog, new Dog()];

            expect(tddGenericConditionalParam($dog, $dogList))->toBe($dogList);

            expect(fn () => tddGenericConditionalParam($dog, $dog))
                ->toThrow(TypeError::class, 'list')
            ;
        });

        test('enforces single Cat instance when animal is Cat', function () {
            $cat = new Cat();

            expect(tddGenericConditionalParam($cat, $cat))->toBe($cat);

            expect(fn () => tddGenericConditionalParam($cat, [$cat]))
                ->toThrow(TypeError::class, Cat::class)
            ;
        });
    });

    describe('6. Unions in Conditional Branches', function () {
        test('accepts positive-int or non-empty-string when asScalar is true', function () {
            expect(tddUnionParamConditional(true, 10))->toBe(10);
            expect(tddUnionParamConditional(true, 'code_10'))->toBe('code_10');

            expect(fn () => tddUnionParamConditional(true, -5))
                ->toThrow(TypeError::class, '(positive-int | non-empty-string)')
            ;
        });

        test('enforces array shape when asScalar is false', function () {
            expect(tddUnionParamConditional(false, ['id' => 10]))->toBe(['id' => 10]);

            expect(fn () => tddUnionParamConditional(false, ['id' => -10]))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int")
            ;
        });
    });

    describe('7. Intersections in Conditional Branches', function () {
        test('enforces Countable & ArrayAccess when mode is complex', function () {
            $both = new CountableArrayAccess();
            expect(tddIntersectionParamConditional('complex', $both))->toBe($both);

            $onlyCountable = new CountableOnly();
            expect(fn () => tddIntersectionParamConditional('complex', $onlyCountable))
                ->toThrow(TypeError::class, '(Countable & ArrayAccess)')
            ;
        });

        test('enforces Countable only when mode is simple', function () {
            $onlyCountable = new CountableOnly();
            expect(tddIntersectionParamConditional('simple', $onlyCountable))->toBe($onlyCountable);
        });
    });

    describe('8. PHP 8.0+ Named Arguments in Swapped Order', function () {
        test('resolves conditional parameter when passed in swapped order by name', function () {
            expect(tddFormatParamConditional(payload: ['key' => 'val'], format: 'json'))->toBe(['key' => 'val']);
            expect(tddFormatParamConditional(payload: 'text', format: 'xml'))->toBe('text');

            expect(fn () => tddFormatParamConditional(payload: 'text', format: 'json'))
                ->toThrow(TypeError::class, 'array')
            ;
        });
    });

    describe('9. Class Methods with Default Arguments', function () {
        test('evaluates condition against default argument when omitted', function () {
            $service = new FixtureConditionalParamService();

            expect($service->executeAction(-50))->toBe(-50);
            expect(fn () => $service->executeAction(-50, strict: true))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });
});
