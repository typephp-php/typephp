<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Tests\Fixtures\Domain\Animal;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\ClassLevelTraitService;
use TypePHP\Tests\Fixtures\Generics\Container;
use TypePHP\Tests\Fixtures\Generics\DogRepository;
use TypePHP\Tests\Fixtures\Generics\Producer;

describe('TemplateManager Unit Tests', function () {
    afterEach(function () {
        TemplateManager::popCallFrame('testFunc');
        TemplateManager::$pendingCloneSource = null;
    });

    describe('Type Inference (inferTypeFromValue)', function () {
        test('infers AST TypeNode correctly from raw PHP primitives and lists', function () {
            expect((string) TemplateManager::inferTypeFromValue(10))->toBe('int')
                ->and((string) TemplateManager::inferTypeFromValue('hello'))->toBe('string')
                ->and((string) TemplateManager::inferTypeFromValue(12.34))->toBe('float')
                ->and((string) TemplateManager::inferTypeFromValue(true))->toBe('bool')
                ->and((string) TemplateManager::inferTypeFromValue([1, 2, 3]))->toBe('list')
                ->and((string) TemplateManager::inferTypeFromValue(['a' => 1]))->toBe('array')
                ->and((string) TemplateManager::inferTypeFromValue(null))->toBe('null')
            ;
        });

        test('infers object class names and includes bound generic types from WeakMap', function () {
            $dog = new Dog();
            expect((string) TemplateManager::inferTypeFromValue($dog))->toBe(Dog::class);

            $container = new Container($dog);
            TemplateManager::bindInstance($container, Container::class . '<' . Dog::class . '>');

            $inferredGeneric = TemplateManager::inferTypeFromValue($container);
            expect($inferredGeneric)->toBeInstanceOf(GenericTypeNode::class)
                ->and((string) $inferredGeneric)->toContain(Container::class)
                ->and((string) $inferredGeneric)->toContain(Dog::class)
            ;
        });
    });

    describe('Call Stack Frame Management', function () {
        test('pushes, inspects, binds, and pops function call stack frames', function () {
            TemplateManager::pushCallFrame('testFunc');

            expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeFalse()
                ->and(TemplateManager::getBoundType('testFunc', null, 'T'))->toBeNull()
            ;

            TemplateManager::bindTemplate('testFunc', null, 'T', new IdentifierTypeNode('int'));

            expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeTrue()
                ->and((string) TemplateManager::getBoundType('testFunc', null, 'T'))->toBe('int')
                ->and(TemplateManager::getBoundTemplates('testFunc', null, []))->toHaveKey('T')
            ;

            TemplateManager::popCallFrame('testFunc');
            expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeFalse();
        });

        test('clears and initializes fresh call bindings via clearCallBindings', function () {
            TemplateManager::clearCallBindings('testFunc', []);

            expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeFalse();

            TemplateManager::bindTemplate('testFunc', null, 'T', new IdentifierTypeNode('string'));
            expect(TemplateManager::isBound('testFunc', null, 'T'))->toBeTrue();
        });

        test('resolveInheritedTemplates returns safely when class does not exist in reflection', function () {
            $anonObj = new stdClass();
            TemplateManager::resolveInheritedTemplates($anonObj, 'NonExistentClass12345');

            expect(TemplateManager::getBoundTemplatesForInstance($anonObj))->toBeEmpty();
        });
    });

    describe('Instance WeakMap Bindings', function () {
        test('binds object instances via bindInstanceFromNode', function () {
            $container = new Container(new Dog());
            $typeNode = new GenericTypeNode(
                new IdentifierTypeNode(Container::class),
                [new IdentifierTypeNode(Dog::class)]
            );

            $err = TemplateManager::bindInstanceFromNode($container, $typeNode);

            expect($err)->toBeNull()
                ->and(TemplateManager::isBound('none', $container, 'T'))->toBeTrue()
                ->and((string) TemplateManager::getBoundType('none', $container, 'T'))->toBe(Dog::class)
                ->and(TemplateManager::getBoundTemplatesForInstance($container))->toHaveKey('T')
            ;
        });

        test('returns ErrorMessage when assigning incompatible invariant generic instance', function () {
            $container = new Container(new Dog());
            $dogNode = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Dog::class)]);
            TemplateManager::bindInstanceFromNode($container, $dogNode);

            $catNode = new GenericTypeNode(new IdentifierTypeNode(Container::class), [new IdentifierTypeNode(Cat::class)]);
            $err = TemplateManager::bindInstanceFromNode($container, $catNode, '$var');

            expect($err)->toBeInstanceOf(ErrorMessage::class)
                ->and($err->getMessage())->toContain('expects')
            ;
        });

        test('returns null when binding instance to non-matching class name', function () {
            $container = new Container(new Dog());
            $typeNode = new GenericTypeNode(new IdentifierTypeNode('NonMatchingClass'), [new IdentifierTypeNode('int')]);

            expect(TemplateManager::bindInstanceFromNode($container, $typeNode))->toBeNull();
        });

        test('binds generic types from type string via bindInstance', function () {
            $container = new Container(new Dog());
            $bound = TemplateManager::bindInstance($container, Container::class . '<' . Dog::class . '>');

            expect($bound)->toBe($container)
                ->and(TemplateManager::getBoundTemplatesForInstance($container))->toHaveKey('T')
            ;
        });

        test('copies instance bindings to cloned object via copyInstanceBindings and pendingCloneSource', function () {
            $original = new Container(new Dog());
            TemplateManager::bindInstance($original, Container::class . '<' . Dog::class . '>');

            $cloned = new Container(new Dog());
            TemplateManager::copyInstanceBindings($original, $cloned);

            expect(TemplateManager::getBoundTemplatesForInstance($cloned))->toHaveKey('T')
                ->and((string) TemplateManager::getBoundType('none', $cloned, 'T'))->toBe(Dog::class)
            ;

            $anotherClone = new Container(new Dog());
            TemplateManager::$pendingCloneSource = $original;

            expect(TemplateManager::getBoundTemplatesForInstance($anotherClone))->toHaveKey('T');
        });
    });

    describe('Inherited Template Resolution (@extends, @implements, @use)', function () {
        test('resolves inherited generic templates from @extends on parent classes', function () {
            $dogRepo = new DogRepository();

            expect(TemplateManager::getBoundTemplatesForInstance($dogRepo))->toHaveKey('T')
                ->and((string) TemplateManager::getBoundType('none', $dogRepo, 'T'))->toBe(Dog::class)
            ;
        });

        test('resolves inherited generic templates from @use on traits', function () {
            $service = new ClassLevelTraitService();

            expect(TemplateManager::getBoundTemplatesForInstance($service))->toHaveKey('T')
                ->and((string) TemplateManager::getBoundType('none', $service, 'T'))->toBe(Dog::class)
            ;
        });
    });

    describe('Variance Engine (checkVariance)', function () {
        test('validates exact type matches and bivariant/mixed targets', function () {
            $dog = new IdentifierTypeNode(Dog::class);
            $mixed = new IdentifierTypeNode('mixed');

            expect(TemplateManager::checkVariance($dog, $dog, GenericTypeNode::VARIANCE_INVARIANT))->toBeTrue();
            expect(TemplateManager::checkVariance($dog, $mixed, GenericTypeNode::VARIANCE_INVARIANT))->toBeTrue();
            expect(TemplateManager::checkVariance($dog, new IdentifierTypeNode('int'), GenericTypeNode::VARIANCE_BIVARIANT))->toBeTrue();
        });

        test('validates covariance rules (subtypes allowed, supertypes/unrelated rejected)', function () {
            $dog = new IdentifierTypeNode(Dog::class);
            $animal = new IdentifierTypeNode(Animal::class);
            $car = new IdentifierTypeNode(Car::class);

            expect(TemplateManager::checkVariance($dog, $animal, GenericTypeNode::VARIANCE_COVARIANT))->toBeTrue();

            expect(TemplateManager::checkVariance($animal, $dog, GenericTypeNode::VARIANCE_COVARIANT))->toBeFalse();

            expect(TemplateManager::checkVariance($car, $animal, GenericTypeNode::VARIANCE_COVARIANT))->toBeFalse();
        });

        test('validates contravariance rules (supertypes allowed, subtypes/unrelated rejected)', function () {
            $dog = new IdentifierTypeNode(Dog::class);
            $animal = new IdentifierTypeNode(Animal::class);
            $cat = new IdentifierTypeNode(Cat::class);

            expect(TemplateManager::checkVariance($animal, $dog, GenericTypeNode::VARIANCE_CONTRAVARIANT))->toBeTrue();

            expect(TemplateManager::checkVariance($dog, $animal, GenericTypeNode::VARIANCE_CONTRAVARIANT))->toBeFalse();

            expect(TemplateManager::checkVariance($cat, $dog, GenericTypeNode::VARIANCE_CONTRAVARIANT))->toBeFalse();
        });

        test('validates union variance checks', function () {
            $dog = new IdentifierTypeNode(Dog::class);
            $cat = new IdentifierTypeNode(Cat::class);
            $unionExpected = new UnionTypeNode([$dog, $cat]);

            expect(TemplateManager::checkVariance($dog, $unionExpected, GenericTypeNode::VARIANCE_COVARIANT))->toBeTrue();
            expect(TemplateManager::checkVariance(new IdentifierTypeNode(Car::class), $unionExpected, GenericTypeNode::VARIANCE_COVARIANT))->toBeFalse();
            $unionExisting = new UnionTypeNode([$dog, $cat]);
            $animal = new IdentifierTypeNode(Animal::class);
            expect(TemplateManager::checkVariance($unionExisting, $animal, GenericTypeNode::VARIANCE_COVARIANT))->toBeTrue();
        });

        test('validates intersection variance checks', function () {
            $countable = new IdentifierTypeNode('Countable');
            $arrayAccess = new IdentifierTypeNode('ArrayAccess');
            $intersection = new IntersectionTypeNode([$countable, $arrayAccess]);

            $countableArrayAccess = new IdentifierTypeNode(ArrayObject::class);
            expect(TemplateManager::checkVariance($countableArrayAccess, $intersection, GenericTypeNode::VARIANCE_COVARIANT))->toBeTrue();
        });

        test('validates nested generic variance (Producer<Dog> vs Producer<Animal>)', function () {
            $dogProducer = new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Dog::class)], [GenericTypeNode::VARIANCE_COVARIANT]);
            $animalProducer = new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Animal::class)], [GenericTypeNode::VARIANCE_COVARIANT]);

            expect(TemplateManager::checkVariance($dogProducer, $animalProducer, GenericTypeNode::VARIANCE_COVARIANT))->toBeTrue();

            $carProducer = new GenericTypeNode(new IdentifierTypeNode(Producer::class), [new IdentifierTypeNode(Car::class)], [GenericTypeNode::VARIANCE_COVARIANT]);
            expect(TemplateManager::checkVariance($carProducer, $animalProducer, GenericTypeNode::VARIANCE_COVARIANT))->toBeFalse();
        });

        test('extracts declared template variances via getTemplateVariances', function () {
            $producer = new Producer(new Dog());
            $variances = TemplateManager::getTemplateVariances($producer);

            expect($variances)->toHaveKey('T')
                ->and($variances['T'])->toBe('covariant')
            ;
        });
    });
});
