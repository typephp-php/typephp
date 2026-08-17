<?php

declare(strict_types=1);

use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\GenericCollection;
use TypePHP\Tests\Fixtures\Generics\HookedCollection;
use TypePHP\Tests\Fixtures\Types\ConfiguredProperty;
use TypePHP\TypePHP;
use TypePHP\Validator\TypeValidatorRegistry;

describe('InlineChecker Unit Tests', function () {
    beforeEach(function () {
        Config::reset();
        Config::set([
            'inline_vars' => [
                'properties' => true,
                'generics' => true,
                'callables' => true,
                'scalars' => true,
                'arrays' => true,
                'objects' => true,
            ],
        ]);
    });

    afterEach(function () {
        Config::reset();
    });

    describe('checkVariable: Scalar Validations', function () {
        test('validates scalar types when enabled in config', function () {
            $registry = new TypeValidatorRegistry();

            $valid = InlineChecker::checkVariable(10, 'positive-int', 'age', __FILE__, $registry);
            expect($valid)->toBe(10);

            $invalid = InlineChecker::checkVariable(-5, 'positive-int', 'age', __FILE__, $registry);
            expect($invalid)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalid->getMessage())->toContain('Variable $age must be of type positive-int');
        });

        test('validates non-empty-string and numeric-string', function () {
            $registry = new TypeValidatorRegistry();

            expect(InlineChecker::checkVariable('hello', 'non-empty-string', 'name', __FILE__, $registry))->toBe('hello');
            expect(InlineChecker::checkVariable('', 'non-empty-string', 'name', __FILE__, $registry))->toBeInstanceOf(ErrorMessage::class);

            expect(InlineChecker::checkVariable('123.45', 'numeric-string', 'num', __FILE__, $registry))->toBe('123.45');
            expect(InlineChecker::checkVariable('not_numeric', 'numeric-string', 'num', __FILE__, $registry))->toBeInstanceOf(ErrorMessage::class);
        });

        test('ignores scalar validation when scalars toggle is false', function () {
            try {
                Config::set(['inline_vars' => ['scalars' => false]]);
                $registry = new TypeValidatorRegistry();

                $result = InlineChecker::checkVariable(-5, 'positive-int', 'age', __FILE__, $registry);
                expect($result)->toBe(-5);
            } finally {
                Config::reset();
            }
        });
    });

    describe('checkVariable: Arrays & Shapes', function () {
        test('validates array shapes and lists', function () {
            $registry = new TypeValidatorRegistry();

            expect(InlineChecker::checkVariable([1, 2, 3], 'list<positive-int>', 'scores', __FILE__, $registry))->toBe([1, 2, 3]);

            expect(InlineChecker::checkVariable([1, -5, 3], 'list<positive-int>', 'scores', __FILE__, $registry))->toBeInstanceOf(ErrorMessage::class);

            expect(InlineChecker::checkVariable(['id' => 1, 'name' => 'Alice'], 'array{id: int, name: string}', 'user', __FILE__, $registry))
                ->toBe(['id' => 1, 'name' => 'Alice']);

            expect(InlineChecker::checkVariable(['id' => 1], 'array{id: int, name: string}', 'user', __FILE__, $registry))->toBeInstanceOf(ErrorMessage::class);
        });

        test('ignores array validation when arrays toggle is false', function () {
            try {
                Config::set(['inline_vars' => ['arrays' => false]]);
                $registry = new TypeValidatorRegistry();

                $result = InlineChecker::checkVariable(['bad_key' => 1], 'list<int>', 'scores', __FILE__, $registry);
                expect($result)->toBe(['bad_key' => 1]);
            } finally {
                Config::reset();
            }
        });
    });

    describe('checkVariable: Objects & Generics Pre-Binding', function () {
        test('validates object class instances', function () {
            $registry = new TypeValidatorRegistry();
            $dog = new Dog();

            expect(InlineChecker::checkVariable($dog, Dog::class, 'animal', __FILE__, $registry))->toBe($dog);
            expect(InlineChecker::checkVariable(new Car(), Dog::class, 'animal', __FILE__, $registry))->toBeInstanceOf(ErrorMessage::class);
        });

        test('pre-binds generic template on object instance via WeakMap', function () {
            $registry = new TypeValidatorRegistry();
            $collection = new GenericCollection();

            $typeString = 'TypePHP\Tests\Fixtures\Generics\GenericCollection<TypePHP\Tests\Fixtures\Domain\Dog>';
            $result = InlineChecker::checkVariable($collection, $typeString, 'dogs', __FILE__, $registry);

            expect($result)->toBe($collection)
                ->and(TypePHP::getGenericType($collection))->toBe(Dog::class);
        });

        test('ignores object and generic checks when respective toggles are false', function () {
            try {
                Config::set(['inline_vars' => ['objects' => false, 'generics' => false]]);
                $registry = new TypeValidatorRegistry();

                $car = new Car();
                expect(InlineChecker::checkVariable($car, Dog::class, 'animal', __FILE__, $registry))->toBe($car);
            } finally {
                Config::reset();
            }
        });
    });

    describe('checkVariable: Callables & Direct Returns', function () {
        test('wraps callable variable in lazy proxy', function () {
            $registry = new TypeValidatorRegistry();
            $cb = fn (int $id): string => "user_{$id}";

            $wrapped = InlineChecker::checkVariable($cb, 'callable(positive-int): non-empty-string', 'formatter', __FILE__, $registry);

            expect($wrapped)->toBeCallable()
                ->and($wrapped(10))->toBe('user_10');

            expect(fn () => $wrapped(-5))->toThrow(TypeError::class, 'positive-int');
        });

        test('formats error message context as Return value when varName is return', function () {
            $registry = new TypeValidatorRegistry();

            $invalid = InlineChecker::checkVariable(-5, 'positive-int', 'return', __FILE__, $registry);

            expect($invalid)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalid->getMessage())->toContain('Return value must be of type positive-int');
        });

        test('returns value immediately when all inline checks are disabled', function () {
            try {
                Config::set([
                    'inline_vars' => [
                        'generics' => false,
                        'callables' => false,
                        'scalars' => false,
                        'arrays' => false,
                        'objects' => false,
                    ],
                ]);
                $registry = new TypeValidatorRegistry();

                $result = InlineChecker::checkVariable(-5, 'positive-int', 'age', __FILE__, $registry);
                expect($result)->toBe(-5);
            } finally {
                Config::reset();
            }
        });
    });

    describe('checkProperty: Instance and Static Properties', function () {
        test('validates instance class properties against @var docblock', function () {
            $registry = new TypeValidatorRegistry();
            $fixture = new ConfiguredProperty();

            $valid = InlineChecker::checkProperty([1, 2, 3], $fixture, 'numbers', __FILE__, $registry);
            expect($valid)->toBe([1, 2, 3]);

            $invalid = InlineChecker::checkProperty(['invalid'], $fixture, 'numbers', __FILE__, $registry);
            expect($invalid)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalid->getMessage())->toContain("numbers['0']");
        });

        test('validates static class properties against @var docblock', function () {
            $registry = new TypeValidatorRegistry();

            $valid = InlineChecker::checkProperty('New Title', ConfiguredProperty::class, 'staticTitle', __FILE__, $registry);
            expect($valid)->toBe('New Title');

            $invalid = InlineChecker::checkProperty(12345, ConfiguredProperty::class, 'staticTitle', __FILE__, $registry);
            expect($invalid)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalid->getMessage())->toContain('staticTitle must be of type string');
        });

        test('substitutes generic template types in class properties', function () {
            if (PHP_VERSION_ID < 80400) {
                expect(true)->toBeTrue();

                return;
            }

            $registry = new TypeValidatorRegistry();
            $collection = new HookedCollection();

            TemplateManager::bindTemplate(HookedCollection::class . '::__construct', $collection, 'T', new \PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode(Dog::class));

            $valid = InlineChecker::checkProperty([new Dog()], $collection, 'items', __FILE__, $registry);
            expect($valid)->toBeArray();

            $invalid = InlineChecker::checkProperty([new Car()], $collection, 'items', __FILE__, $registry);
            expect($invalid)->toBeInstanceOf(ErrorMessage::class)
                ->and($invalid->getMessage())->toContain("items['0']");
        });

        test('ignores property checks when properties toggle is false', function () {
            try {
                Config::set(['inline_vars' => ['properties' => false]]);
                $registry = new TypeValidatorRegistry();
                $fixture = new ConfiguredProperty();

                $result = InlineChecker::checkProperty(['invalid'], $fixture, 'numbers', __FILE__, $registry);
                expect($result)->toBe(['invalid']);
            } finally {
                Config::reset();
            }
        });

        test('returns value untouched for un-annotated properties or invalid inputs', function () {
            $registry = new TypeValidatorRegistry();
            $fixture = new ConfiguredProperty();

            expect(InlineChecker::checkProperty(123, null, 'prop', __FILE__, $registry))->toBe(123);
            expect(InlineChecker::checkProperty('raw', $fixture, 'nonExistentProperty', __FILE__, $registry))->toBe('raw');
        });
    });
});