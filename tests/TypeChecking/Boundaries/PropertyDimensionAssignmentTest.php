<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

class PropertyDimBox
{
    /**
     * @var list<int>
     */
    public array $numbers = [1, 2, 3];

    /**
     * @var array{id: positive-int, name: non-empty-string}
     */
    public array $user = [
        'id' => 1,
        'name' => 'Alice',
    ];

    /**
     * @var array{string, int}
     */
    public array $tuple = ['Alice', 30];

    /**
     * @var list<list<int>>
     */
    public array $matrix = [
        [1, 2],
        [3, 4],
    ];

    /**
     * @var list<positive-int>
     */
    public array $scores = [10, 20];
}

class PropertyDimStaticBox
{
    /**
     * @var list<int>
     */
    public static array $numbers = [1, 2, 3];

    public static function reset(): void
    {
        self::$numbers = [1, 2, 3];
    }
}

describe('Property Dimension & Array Append Validation ($obj->prop[] = $val)', function () {
    describe('1. Object Property List Appends & Index Assignments', function () {
        test('throws TypeError when appending an invalid value to an object array property ($box->numbers[] = "wrong")', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->numbers[] = 'wrong';
            })->toThrow(TypeError::class, 'must be of type int');
        });

        test('allows appending a valid value to an object array property', function () {
            $box = new PropertyDimBox();

            $box->numbers[] = 4;
            expect($box->numbers)->toBe([1, 2, 3, 4]);
        });

        test('throws TypeError when assigning an invalid value to an existing index ($box->numbers[0] = "wrong")', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->numbers[0] = 'wrong';
            })->toThrow(TypeError::class, 'must be of type int');
        });
    });

    describe('2. Object Property Array Shapes & Sealed Violations', function () {
        test('throws TypeError when mutating an array shape key on an object property ($box->user["name"] = 123)', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->user['name'] = 123;
            })->toThrow(TypeError::class, 'must be of type non-empty-string');
        });

        test('throws TypeError when assigning an unexpected key to a sealed array shape property ($box->user["extra"] = "forbidden")', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->user['extra'] = 'forbidden';
            })->toThrow(TypeError::class, "contains unsealed unexpected key 'extra'");
        });
    });

    describe('3. Positional Tuples on Object Properties', function () {
        test('throws TypeError when assigning invalid type to tuple index ($box->tuple[0] = 123)', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->tuple[0] = 123;
            })->toThrow(TypeError::class, 'must be of type string');
        });

        test('throws TypeError when appending extra element to a tuple ($box->tuple[] = "extra")', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->tuple[] = 'extra';
            })->toThrow(TypeError::class, "contains unsealed unexpected key '2'");
        });
    });

    describe('4. Nested Multi-Dimensional Property Arrays', function () {
        test('throws TypeError on nested multi-dimensional property array mutation ($box->matrix[0][1] = "wrong")', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->matrix[0][1] = 'wrong';
            })->toThrow(TypeError::class, 'must be of type int');
        });
    });

    describe('5. Static Property Array Dimensions', function () {
        beforeEach(function () {
            PropertyDimStaticBox::reset();
        });

        afterEach(function () {
            PropertyDimStaticBox::reset();
        });

        test('throws TypeError when appending an invalid value to a static array property (StaticBox::$numbers[] = "wrong")', function () {
            expect(function () {
                PropertyDimStaticBox::$numbers[] = 'wrong';
            })->toThrow(TypeError::class, 'must be of type int');
        });

        test('throws TypeError when assigning an invalid value to a static array index (StaticBox::$numbers[0] = "wrong")', function () {
            expect(function () {
                PropertyDimStaticBox::$numbers[0] = 'wrong';
            })->toThrow(TypeError::class, 'must be of type int');
        });
    });

    describe('6. Compound Assignments & Atomic State Preservation', function () {
        test('throws TypeError on compound assignment violating property element contract ($box->scores[0] += -100)', function () {
            $box = new PropertyDimBox();

            expect(function () use ($box) {
                $box->scores[0] += -100;
            })->toThrow(TypeError::class, 'must be of type positive-int');
        });

        test('preserves property array state when dimension assignment throws TypeError (atomic failure)', function () {
            $box = new PropertyDimBox();

            try {
                $box->numbers[] = 'wrong';
            } catch (TypeError) {
            }

            expect($box->numbers)->toBe([1, 2, 3]);
        });
    });
});
