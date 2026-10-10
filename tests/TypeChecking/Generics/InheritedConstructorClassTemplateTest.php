<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use stdClass;
use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;

abstract class ReproInheritedCtorBase
{
    public function __construct()
    {
    }
}

/**
 * @template TEntity
 */
abstract class ReproInheritedCtorField extends ReproInheritedCtorBase
{
    /**
     * @var TEntity
     */
    public mixed $entity = null;

    /**
     * @var TEntity|null
     */
    public static mixed $staticEntity = null;
}

/**
 * @template TEntity
 *
 * @extends ReproInheritedCtorField<TEntity>
 */
abstract class ReproInheritedCtorInputField extends ReproInheritedCtorField
{
}

/**
 * Leaf class with inherited constructor from ReproInheritedCtorBase
 */
final class ReproInheritedCtorTextField extends ReproInheritedCtorInputField
{
}

/**
 * Specialized leaf class binding TEntity to Dog with inherited constructor
 *
 * @extends ReproInheritedCtorField<Dog>
 */
final class ReproInheritedCtorDogField extends ReproInheritedCtorField
{
}

/**
 * Strict non-nullable static property fixture
 *
 * @template TEntity of object
 */
abstract class ReproStaticStrictHolder extends ReproInheritedCtorBase
{
    /**
     * @var TEntity
     */
    public static object $strictStaticEntity;
}

/**
 * @extends ReproStaticStrictHolder<Dog>
 */
final class ReproStaticStrictDogHolder extends ReproStaticStrictHolder
{
}

describe('Inherited Constructor Class Template Property Assignment (Bug Report Reproduction)', function () {
    beforeEach(function () {
        ReproInheritedCtorTextField::$staticEntity = null;
        ReproInheritedCtorDogField::$staticEntity = null;
    });

    afterEach(function () {
        ReproInheritedCtorTextField::$staticEntity = null;
        ReproInheritedCtorDogField::$staticEntity = null;
    });

    describe('Instance Property Assignments', function () {
        test('allows assigning any value to unbound generic template property when constructor is inherited from untemplated base', function () {
            $field = new ReproInheritedCtorTextField();

            $field->entity = new stdClass();
            expect($field->entity)->toBeInstanceOf(stdClass::class);

            $field->entity = 'sample_string';
            expect($field->entity)->toBe('sample_string');

            $field->entity = 12345;
            expect($field->entity)->toBe(12345);
        });

        test('enforces specialized bound template when subclass extends templated parent with inherited constructor', function () {
            $dogField = new ReproInheritedCtorDogField();

            $dog = new Dog();
            $dogField->entity = $dog;
            expect($dogField->entity)->toBe($dog);

            expect(fn () => $dogField->entity = new Car())
                ->toThrow(TypeError::class, Dog::class)
            ;
        });
    });

    describe('Static Property Assignments', function () {
        test('allows assigning any value to unbound generic template static property with inherited constructor', function () {
            try {
                ReproInheritedCtorTextField::$staticEntity = new stdClass();
                expect(ReproInheritedCtorTextField::$staticEntity)->toBeInstanceOf(stdClass::class);

                ReproInheritedCtorTextField::$staticEntity = 'static_sample_string';
                expect(ReproInheritedCtorTextField::$staticEntity)->toBe('static_sample_string');

                ReproInheritedCtorTextField::$staticEntity = 99999;
                expect(ReproInheritedCtorTextField::$staticEntity)->toBe(99999);
            } finally {
                ReproInheritedCtorTextField::$staticEntity = null;
            }
        });

        test('enforces specialized bound template when assigning to nullable static property on subclass with inherited constructor', function () {
            try {
                $dog = new Dog();
                ReproInheritedCtorDogField::$staticEntity = $dog;
                expect(ReproInheritedCtorDogField::$staticEntity)->toBe($dog);

                expect(fn () => ReproInheritedCtorDogField::$staticEntity = new Car())
                    ->toThrow(TypeError::class, '(TypePHP\Tests\Fixtures\Domain\Dog | null)')
                ;
            } finally {
                ReproInheritedCtorDogField::$staticEntity = null;
            }
        });

        test('enforces strict non-nullable specialized bound template on static property with inherited constructor', function () {
            $dog = new Dog();
            ReproStaticStrictDogHolder::$strictStaticEntity = $dog;
            expect(ReproStaticStrictDogHolder::$strictStaticEntity)->toBe($dog);

            expect(fn () => ReproStaticStrictDogHolder::$strictStaticEntity = new Car())
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });
    });
});
