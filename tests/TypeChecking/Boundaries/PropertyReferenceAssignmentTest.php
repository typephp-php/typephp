<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Properties\PropertyRefTargetFixture;

/**
 * Declared in the SAME file to prove AST-Level local property fallback
 * when Composer cannot autoload the class during compile-time.
 */
class SameFilePropertyRefFixture
{
    /**
     * @var positive-int
     */
    public int $localScore = 10;

    /**
     * @var list<int>
     */
    public array $localNumbers = [1, 2, 3];

    /**
     * @var array{id: positive-int, name: non-empty-string}
     */
    public array $localUser = [
        'id' => 1,
        'name' => 'Alice',
    ];

    public function mutateInternalScoreByRef(mixed $val): void
    {
        $ref = &$this->localScore;
        $ref = $val;
    }
}

describe('By-Reference Property Aliasing ($ref = &$obj->prop)', function () {
    beforeEach(function () {
        PropertyRefTargetFixture::reset();
    });

    describe('1. External Instance Property Reference Aliasing ($ref = &$box->prop)', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a scalar property', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->score;

            expect(function () use (&$ref) {
                $ref = -50;
            })->toThrow(TypeError::class, 'positive-int');
        });

        test('allows valid assignment to reference aliasing a scalar property and mutates property in place', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->score;
            $ref = 42;

            expect($box->score)->toBe(42)
                ->and($ref)->toBe(42)
            ;
        });
    });

    describe('2. External Property Array Dimension Reference ($ref = &$box->numbers[0])', function () {
        test('throws TypeError when assigning invalid value to reference aliasing an array property element', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->numbers[0];

            expect(function () use (&$ref) {
                $ref = 'not_an_int';
            })->toThrow(TypeError::class, 'must be of type int');
        });

        test('allows valid assignment to reference aliasing an array property element and mutates array in place', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->numbers[0];
            $ref = 999;

            expect($box->numbers[0])->toBe(999)
                ->and($ref)->toBe(999)
            ;
        });
    });

    describe('3. External Property Array Shape Key Reference ($ref = &$box->user[\'name\'])', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a shape property key', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->user['name'];

            expect(function () use (&$ref) {
                $ref = ''; // Violates non-empty-string
            })->toThrow(TypeError::class, 'non-empty-string');
        });

        test('throws TypeError when assigning invalid value to reference aliasing a positive-int shape key', function () {
            $box = new PropertyRefTargetFixture();

            $ref = &$box->user['id'];

            expect(function () use (&$ref) {
                $ref = -1; // Violates positive-int
            })->toThrow(TypeError::class, 'positive-int');
        });
    });

    describe('4. Internal $this Property Reference Aliasing ($ref = &$this->prop)', function () {
        test('throws TypeError when internal method mutates $this scalar property via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalScoreByRef(-99);
            })->toThrow(TypeError::class, 'positive-int');

            expect($box->score)->toBe(10);
        });

        test('throws TypeError when internal method mutates $this array element via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalNumberByRef('invalid_element');
            })->toThrow(TypeError::class, 'must be of type int');

            expect($box->numbers[0])->toBe(1);
        });

        test('throws TypeError when internal method mutates $this shape key via reference alias', function () {
            $box = new PropertyRefTargetFixture();

            expect(function () use ($box) {
                $box->mutateInternalUserByRef('');
            })->toThrow(TypeError::class, 'non-empty-string');

            expect($box->user['name'])->toBe('Alice');
        });
    });

    describe('5. Static Property Reference Aliasing ($ref = &Class::$prop)', function () {
        test('throws TypeError when assigning invalid value to reference aliasing a static property', function () {
            $ref = &PropertyRefTargetFixture::$staticScore;

            expect(function () use (&$ref) {
                $ref = -100;
            })->toThrow(TypeError::class, 'positive-int');

            expect(PropertyRefTargetFixture::$staticScore)->toBe(100);
        });
    });

    describe('6. Same-File Declared Class Property Reference Aliasing (AST-Level Fallback)', function () {
        test('throws TypeError on scalar reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localScore;

            expect(function () use (&$ref) {
                $ref = -25;
            })->toThrow(TypeError::class, 'positive-int');

            expect($local->localScore)->toBe(10);
        });

        test('throws TypeError on array dimension reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localNumbers[0];

            expect(function () use (&$ref) {
                $ref = 'wrong_type';
            })->toThrow(TypeError::class, 'must be of type int');

            expect($local->localNumbers[0])->toBe(1);
        });

        test('throws TypeError on shape key reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            $ref = &$local->localUser['name'];

            expect(function () use (&$ref) {
                $ref = '';
            })->toThrow(TypeError::class, 'non-empty-string');

            expect($local->localUser['name'])->toBe('Alice');
        });

        test('throws TypeError on $this reference mutation for class declared in the same file', function () {
            $local = new SameFilePropertyRefFixture();

            expect(function () use ($local) {
                $local->mutateInternalScoreByRef(-99);
            })->toThrow(TypeError::class, 'positive-int');

            expect($local->localScore)->toBe(10);
        });
    });
});
