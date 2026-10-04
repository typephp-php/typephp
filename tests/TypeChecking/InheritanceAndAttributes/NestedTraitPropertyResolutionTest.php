<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\InheritanceAndAttributes;

use TypePHP\Tests\Fixtures\Traits\Nested\Contracts\NestedEntity;
use TypePHP\Tests\Fixtures\Traits\Nested\Model\NestedAgency;

describe('Nested Trait Property Type Resolution (Bug Reproduction)', function () {
    test('resolves property @var against declaring trait imports when trait is used through another trait', function () {
        $agency = new NestedAgency();

        $agency->setGroup([$agency]);

        expect($agency->group)->toHaveCount(1)
            ->and($agency->group[0])->toBeInstanceOf(NestedEntity::class)
        ;
    });

    test('resolves non-array property @var against declaring trait imports in nested traits', function () {
        $agency = new NestedAgency();

        $agency->setSingle($agency);

        expect($agency->singleEntity)->toBe($agency);
    });
});
