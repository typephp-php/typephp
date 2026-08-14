<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Types\NestedAliasService;

describe('Nested Type Aliases (Local and Imported)', function () {
    describe('Local Multi-Tier Nested Aliases', function () {
        test('accepts valid nested records matching local 3-tier alias definitions', function () {
            $service = new NestedAliasService();

            $validRecords = [
                ['id' => 10, 'status' => 'active'],
                ['id' => 20, 'status' => 'pending'],
            ];

            expect($service->saveLocalRecords($validRecords))->toBeTrue();
        });

        test('throws TypeError when nested shape item violates local scalar alias', function () {
            $service = new NestedAliasService();

            $invalidRecords = [
                ['id' => 10, 'status' => 'active'],
                ['id' => -5, 'status' => 'pending'], // -5 violates LocalId (positive-int)
            ];

            expect(fn () => $service->saveLocalRecords($invalidRecords))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int")
            ;
        });

        test('throws TypeError when nested shape item violates local union alias', function () {
            $service = new NestedAliasService();

            $invalidRecords = [
                ['id' => 10, 'status' => 'archived'], // 'archived' violates LocalStatus ('active'|'pending')
            ];

            expect(fn () => $service->saveLocalRecords($invalidRecords))
                ->toThrow(TypeError::class, "['status'] must be of type ('active' | 'pending')")
            ;
        });
    });

    describe('Imported Multi-Tier Nested Aliases', function () {
        test('accepts valid nested records matching imported 3-tier alias definitions', function () {
            $service = new NestedAliasService();

            $validRecords = [
                ['id' => 100, 'status' => 'active'],
                ['id' => 200, 'status' => 'pending'],
            ];

            expect($service->saveImportedRecords($validRecords))->toBeTrue();
        });

        test('throws TypeError when nested shape item violates imported scalar alias', function () {
            $service = new NestedAliasService();

            $invalidRecords = [
                ['id' => -100, 'status' => 'active'],
            ];

            expect(fn () => $service->saveImportedRecords($invalidRecords))
                ->toThrow(TypeError::class, "['id'] must be of type positive-int")
            ;
        });

        test('throws TypeError when nested shape item violates imported union alias', function () {
            $service = new NestedAliasService();

            $invalidRecords = [
                ['id' => 100, 'status' => 'deleted'],
            ];

            expect(fn () => $service->saveImportedRecords($invalidRecords))
                ->toThrow(TypeError::class, "['status'] must be of type ('active' | 'pending')")
            ;
        });
    });

    describe('Edge Case 1: 3-Class Chained Alias Imports (A -> B -> Service)', function () {
        test('resolves chained type aliases imported across 3 separate classes', function () {
            $service = new NestedAliasService();

            expect($service->saveChainedData(['code' => 50, 'label' => 'valid']))->toBeTrue();

            expect(fn () => $service->saveChainedData(['code' => -10, 'label' => 'valid']))
                ->toThrow(TypeError::class, "['code'] must be of type positive-int")
            ;

            expect(fn () => $service->saveChainedData(['code' => 50, 'label' => '']))
                ->toThrow(TypeError::class, "['label'] must be of type non-empty-string")
            ;
        });
    });

    describe('Edge Case 2: Unions Combining Independent Type Aliases', function () {
        test('validates parameter against a union composed of separate type aliases', function () {
            $service = new NestedAliasService();

            expect($service->setUnionStatus('admin_active'))->toBeTrue();
            expect($service->setUnionStatus('user_active'))->toBeTrue();

            expect(fn () => $service->setUnionStatus('guest_active'))
                ->toThrow(TypeError::class, "('admin_active' | 'user_active')")
            ;
        });
    });

    describe('Edge Case 3: Property Hooks with Imported Type Aliases', function () {
        test('validates PHP 8.4 property hook write against imported 3-tier shape alias', function () {
            $service = new NestedAliasService();

            $service->chainedProperty = ['code' => 100, 'label' => 'updated'];
            expect($service->chainedProperty['code'])->toBe(100);

            expect(fn () => $service->chainedProperty = ['code' => -1, 'label' => 'updated'])
                ->toThrow(TypeError::class, "Property TypePHP\Tests\Fixtures\Types\NestedAliasService::\$chainedProperty['code'] must be of type positive-int");
        });
    });
});
