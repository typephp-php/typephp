<?php

declare(strict_types=1);

use TypePHP\Tests\Fixtures\Attributes\DeepMultiLevelField;
use TypePHP\Tests\Fixtures\Attributes\MeasurementSystemEntity;
use TypePHP\Tests\Fixtures\Attributes\OnDeleteOption;
use TypePHP\Tests\Fixtures\Attributes\OneToManyRelation;

describe('Attribute Constructor Parameter Inheritance', function () {

    describe('Attribute Case (Single-Level Shift)', function () {

        test('reproduces parameter index mismatch bug when parent parameter positions shift', function () {
            $attr = new OneToManyRelation(
                entity: 'measurement_display_unit',
                ref: 'measurement_system_id',
                onDelete: OnDeleteOption::CASCADE,
                api: true
            );

            expect($attr)->toBeInstanceOf(OneToManyRelation::class);
        });

        test('reproduces parameter index mismatch bug when instantiated via PHP 8 ReflectionAttribute::newInstance()', function () {
            $refProp = new ReflectionProperty(MeasurementSystemEntity::class, 'units');
            $refAttr = $refProp->getAttributes(OneToManyRelation::class)[0];

            $attrInstance = $refAttr->newInstance();

            expect($attrInstance)->toBeInstanceOf(OneToManyRelation::class);
        });

    });

    describe('Multi-Level 3-Tier Parameter Shift Edge Cases', function () {

        test('correctly maps inherited contracts across 3 hierarchy levels with multiple position shifts', function () {
            $field = new DeepMultiLevelField(10, 'entity', 'unit_name', true);

            expect($field)->toBeInstanceOf(DeepMultiLevelField::class);
        });

        test('throws TypeError when $id violates local child contract in 3-tier hierarchy', function () {
            expect(fn () => new DeepMultiLevelField(-5, 'entity', 'unit_name', true))
                ->toThrow(TypeError::class, 'Argument $id must be of type positive-int')
            ;
        });

        test('throws TypeError when $type violates 3rd-tier grand-parent contract', function () {
            expect(fn () => new DeepMultiLevelField(10, '', 'unit_name', true))
                ->toThrow(TypeError::class, 'Argument $type must be of type non-empty-string')
            ;
        });

        test('throws TypeError when $name violates 2nd-tier parent contract', function () {
            expect(fn () => new DeepMultiLevelField(10, 'entity', '', true))
                ->toThrow(TypeError::class, 'Argument $name must be of type non-empty-string')
            ;
        });

    });

});
