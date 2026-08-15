<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Cat;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\ChildComboService;
use TypePHP\Tests\Fixtures\Generics\ChildSingleAbstractService;
use TypePHP\Tests\Fixtures\Generics\ChildWithoutTemplateDocblock;
use TypePHP\Tests\Fixtures\Generics\DeepGenericChildService;
use TypePHP\TypePHP;

describe('Inherited Interface Template Discovery during Instance Pre-binding', function () {
    describe('Interface Template Inheritance', function () {
        test('binds generic union template when child class inherits template from interface without declaring it locally', function () {
            /** @var ChildWithoutTemplateDocblock<Dog|Cat> $container */
            $container = new ChildWithoutTemplateDocblock();

            $container->push(new Dog());

            expect($container->push(new Cat()))->toBeTrue();
            expect(TypePHP::getGenericType($container))->toBe('(' . Dog::class . ' | ' . Cat::class . ')');
        });

        test('throws TypeError when item violates pre-bound union template on child inheriting interface', function () {
            /** @var ChildWithoutTemplateDocblock<Dog|Cat> $container */
            $container = new ChildWithoutTemplateDocblock();

            expect(fn () => $container->push(new Car()))
                ->toThrow(TypeError::class, 'must be of type (' . Dog::class . ' | ' . Cat::class . ')');
        });
    });

    describe('Single Abstract Class Generic Inheritance', function () {
        test('pre-binds template inherited from single abstract parent without child docblock', function () {
            /** @var ChildSingleAbstractService<Dog|Cat> $service */
            $service = new ChildSingleAbstractService();

            expect($service->setItem(new Dog()))->toBeTrue();
            expect($service->setItem(new Cat()))->toBeTrue();

            expect(fn () => $service->setItem(new Car()))
                ->toThrow(TypeError::class, 'must be of type (' . Dog::class . ' | ' . Cat::class . ')');
        });
    });

    describe('Multi-Nested Deep Generic Inheritance (Root -> Mid -> Child)', function () {
        test('pre-binds template inherited across 3-tier deep abstract inheritance chain without child docblock', function () {
            /** @var DeepGenericChildService<positive-int> $service */
            $service = new DeepGenericChildService();

            expect($service->processElement(100))->toBeTrue();

            expect(fn () => $service->processElement(-50))
                ->toThrow(TypeError::class, 'must be of type positive-int');
        });
    });

    describe('Abstract Class + Interface + Trait Combo Hierarchy', function () {
        test('pre-binds templates inherited across abstract class, interface, and trait combo without local child docblock', function () {
            /** @var ChildComboService<positive-int, non-empty-string, Dog|Cat> $service */
            $service = new ChildComboService();

            expect($service->processData(42))->toBeTrue();
            expect(fn () => $service->processData(-5))
                ->toThrow(TypeError::class, 'must be of type positive-int');

            expect($service->setKey('valid_key'))->toBeTrue();
            expect(fn () => $service->setKey(''))
                ->toThrow(TypeError::class, 'must be of type non-empty-string');
                
            expect($service->setVal(new Dog()))->toBeTrue();
            expect($service->setVal(new Cat()))->toBeTrue();
            expect(fn () => $service->setVal(new Car()))
                ->toThrow(TypeError::class, 'must be of type (' . Dog::class . ' | ' . Cat::class . ')');
        });
    });
});