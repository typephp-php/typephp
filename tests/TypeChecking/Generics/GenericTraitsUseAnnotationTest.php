<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Domain\Car;
use TypePHP\Tests\Fixtures\Domain\Dog;
use TypePHP\Tests\Fixtures\Generics\ClassLevelTraitService;
use TypePHP\Tests\Fixtures\Generics\InlineTraitUseService;
use TypePHP\Tests\Fixtures\Generics\SingleLineInlineTraitUseService;
use TypePHP\TypePHP;

describe('Generic Traits with @use, @template-use, and @phpstan-use Annotations', function () {
    describe('Class-Level Trait Template Annotations', function () {
        test('pre-binds generic template T upon instantiation when class docblock declares @use Trait<T>', function () {
            $service = new ClassLevelTraitService();

            expect(TypePHP::getGenericType($service))->toBe(Dog::class);

            expect($service->logItem(new Dog()))->toBeTrue();
            expect(fn () => $service->logItem(new Car()))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });
    });

    describe('Inline Trait Use Statement Annotations (/** @use */ use Trait;)', function () {
        test('pre-binds generic template T upon instantiation when inline use statement declares @use Trait<T>', function () {
            $service = new InlineTraitUseService();

            expect(TypePHP::getGenericType($service))->toBe(Dog::class);

            expect($service->logItem(new Dog()))->toBeTrue();
            expect(fn () => $service->logItem(new Car()))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Dog')
            ;
        });

        test('pre-binds generic template T with single-line docblock (/** @use Trait<T> */ use Trait;)', function () {
            $service = new SingleLineInlineTraitUseService();

            expect(TypePHP::getGenericType($service))->toBe(Dog::class);

            expect($service->logItem(new Dog()))->toBeTrue();
            expect(fn () => $service->logItem(new Car()))
                ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Domain\Dog');
        });
    });
});
