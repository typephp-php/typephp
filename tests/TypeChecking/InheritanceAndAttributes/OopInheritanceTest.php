<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Oop\ChildClassInheritingTraitParent;
use TypePHP\Tests\Fixtures\Oop\ClassUsingTraitProperties;
use TypePHP\Tests\Fixtures\Oop\ConcreteChildService;
use TypePHP\Tests\Fixtures\Oop\TraitImplementation;

describe('OOP Inheritance (Traits, Abstract Methods & Interface Traits)', function () {
    test('inherits docblock contracts from abstract class methods', function () {
        $service = new ConcreteChildService();

        expect($service->process(10))->toBe('item_10');

        expect(fn () => $service->process(-50))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $service->process(999))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('inherits interface docblock contracts when method is fulfilled via Trait', function () {
        $app = new TraitImplementation();

        expect($app->execute(100))->toBe('code_100');

        expect(fn () => $app->execute(-5))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        expect(fn () => $app->execute(999))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('inherits instance and static property @var docblocks from Traits', function () {
        $app = new ClassUsingTraitProperties();

        $app->setTraitInstanceProp(100);
        expect($app->traitInstanceProp)->toBe(100);

        expect(fn () => $app->setTraitInstanceProp(-50))
            ->toThrow(TypeError::class, 'positive-int')
        ;

        ClassUsingTraitProperties::setTraitStaticProp('v2.0');
        expect(ClassUsingTraitProperties::$traitStaticProp)->toBe('v2.0');

        expect(fn () => ClassUsingTraitProperties::setTraitStaticProp(''))
            ->toThrow(TypeError::class, 'non-empty-string')
        ;
    });

    test('inherits trait docblock contracts across parent-child class inheritance', function () {
        $child = new ChildClassInheritingTraitParent();

        expect($child->logMessage(10, 'boot'))->toBe('log_10_boot');

        expect(fn () => $child->logMessage(-5, 'boot'))
            ->toThrow(TypeError::class, 'positive-int')
        ;
    });
});
