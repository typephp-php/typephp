<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Liskov\AppChildService;
use TypePHP\Tests\Fixtures\Liskov\ChildLiskovService;
use TypePHP\Tests\Fixtures\Liskov\RenamedParamImplementation;
use TypePHP\Tests\Fixtures\Liskov\SimulatedVendorParent;

describe('Liskov Substitution Principle & Vendor Isolation', function () {
    describe('Edge Case 1: Partial Parameter Overriding (Gap Filling)', function () {
        test('inherits un-annotated parameter types from parent while respecting child overrides', function () {
            $service = new ChildLiskovService();

            expect($service->update(10, 'Alice'))->toBeTrue();

            expect(fn () => $service->update(-5, 'Alice'))
                ->toThrow(TypeError::class, 'positive-int')
            ;

            expect(fn () => $service->update(10, 'Charlie'))
                ->toThrow(TypeError::class, "('Alice' | 'Bob')")
            ;
        });
    });

    describe('Edge Case 2: Parameter Renaming ($id -> $userId)', function () {
        test('enforces parameter type contract even when child renames parameter', function () {
            $service = new RenamedParamImplementation();

            expect($service->find(100))->toBeTrue();

            expect(fn () => $service->find(-50))
                ->toThrow(TypeError::class, 'positive-int')
            ;
        });
    });

    describe('Edge Case 3: Vendor Isolation', function () {
        test('ignores inherited docblocks from excluded/vendor classes', function () {
            $ref = new ReflectionClass(SimulatedVendorParent::class);
            $filePath = str_replace('\\', '/', (string) $ref->getFileName());

            Config::set(['exclude' => [$filePath]]);

            $appService = new AppChildService();

            expect($appService->execute(100))->toBeTrue();

            Config::reset();
        });
    });
});
