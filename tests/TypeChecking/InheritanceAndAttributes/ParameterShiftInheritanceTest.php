<?php

declare(strict_types=1);

use TypePHP\Internal\Config;
use TypePHP\Tests\Fixtures\Services\ChildShiftedMethodService;
use TypePHP\Tests\Fixtures\Services\ChildShiftedOopService;
use TypePHP\Tests\Fixtures\Services\ChildShiftedParamService;
use TypePHP\Tests\Fixtures\Services\ChildShiftedTraitAppService;
use TypePHP\Tests\Fixtures\Services\HelperService;

beforeEach(function () {
    Config::reset();
});

afterEach(function () {
    Config::reset();
});

describe('Parameter Shift & Renaming Inheritance Disambiguation', function () {
    describe('Constructor Parameter Shifts', function () {
        test('prevents parent constructor param contracts from mis-mapping onto shifted child params', function () {
            $helper = new HelperService();

            $service = new ChildShiftedParamService(
                'sales_channel.',
                $helper,
                ['product' => 'ProductDefinition'],
                ['product' => 'ProductRepository']
            );

            expect($service)->toBeInstanceOf(ChildShiftedParamService::class);
        });
    });

    describe('Instance Method Parameter Renaming', function () {
        test('validates instance method parameters when child renames parameters', function () {
            $service = new ChildShiftedMethodService();

            expect($service->updateUser(42, 'Alice', ['active' => true]))->toBeTrue();
        });

        test('throws TypeError when renamed $userId parameter fails parent inherited positive-int contract', function () {
            $service = new ChildShiftedMethodService();

            expect(fn () => $service->updateUser(-5, 'Alice', ['active' => true]))
                ->toThrow(TypeError::class, 'Argument $userId must be of type positive-int')
            ;
        });

        test('throws TypeError when renamed $userName parameter fails parent inherited non-empty-string contract', function () {
            $service = new ChildShiftedMethodService();

            expect(fn () => $service->updateUser(42, '', ['active' => true]))
                ->toThrow(TypeError::class, 'Argument $userName must be of type non-empty-string')
            ;
        });

        test('throws TypeError when renamed $userOptions parameter fails parent inherited shape contract', function () {
            $service = new ChildShiftedMethodService();

            expect(fn () => $service->updateUser(42, 'Alice', ['active' => 'not_bool']))
                ->toThrow(TypeError::class, "Argument \$userOptions['active'] must be of type bool")
            ;
        });
    });

    describe('Static Method Parameter Renaming & Optional Parameters', function () {
        test('validates static method parameters when child renames parameters and adds optional parameter', function () {
            expect(ChildShiftedMethodService::processBatch([10, 20], 'json', true))->toBeTrue();
        });

        test('throws TypeError when renamed $itemBatch parameter fails parent contract on static method', function () {
            expect(fn () => ChildShiftedMethodService::processBatch([10, -5], 'json'))
                ->toThrow(TypeError::class, 'Argument $itemBatch[1] must be of type positive-int')
            ;
        });

        test('throws TypeError when renamed $outputFormat parameter fails parent contract on static method', function () {
            expect(fn () => ChildShiftedMethodService::processBatch([10, 20], ''))
                ->toThrow(TypeError::class, 'Argument $outputFormat must be of type non-empty-string')
            ;
        });
    });

    describe('Interfaces, Abstract Classes, and Traits', function () {
        test('inherits and validates contracts from Interfaces with renamed parameters', function () {
            $service = new ChildShiftedOopService();

            expect($service->execute(200, 'valid_token'))->toBeTrue();

            expect(fn () => $service->execute(-10, 'valid_token'))
                ->toThrow(TypeError::class, 'Argument $statusCode must be of type positive-int')
            ;

            expect(fn () => $service->execute(200, ''))
                ->toThrow(TypeError::class, 'Argument $authToken must be of type non-empty-string')
            ;
        });

        test('inherits and validates contracts from Abstract Classes with renamed parameters', function () {
            $service = new ChildShiftedOopService();

            expect($service->processItems([10, 20, 30]))->toBeTrue();

            expect(fn () => $service->processItems([10, -5, 30]))
                ->toThrow(TypeError::class, 'Argument $itemList[1] must be of type positive-int')
            ;
        });

        test('inherits and validates contracts from Traits with renamed parameters', function () {
            $service = new ChildShiftedOopService();

            expect($service->logEvent(1, 'info_message'))->toBeTrue();

            expect(fn () => $service->logEvent(-1, 'info_message'))
                ->toThrow(TypeError::class, 'Argument $logLevel must be of type positive-int')
            ;

            expect(fn () => $service->logEvent(1, ''))
                ->toThrow(TypeError::class, 'Argument $logMessage must be of type non-empty-string')
            ;
        });

        test('inherits Interface contracts when method is fulfilled by Trait with renamed parameters', function () {
            $service = new ChildShiftedTraitAppService();

            expect($service->runAction(100, 'valid_token'))->toBeTrue();

            expect(fn () => $service->runAction(-5, 'valid_token'))
                ->toThrow(TypeError::class, 'Argument $actionCode must be of type positive-int')
            ;

            expect(fn () => $service->runAction(100, ''))
                ->toThrow(TypeError::class, 'Argument $actionToken must be of type non-empty-string');
        });
    });
});
