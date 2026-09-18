<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Tests\Fixtures\Forwarding\BoxConsumer;
use TypePHP\Tests\Fixtures\Forwarding\ConcreteBox;
use TypePHP\Tests\Fixtures\Forwarding\ItemA;
use TypePHP\Tests\Fixtures\Forwarding\ItemB;
use TypePHP\TypePHP;

describe('Generic Template Forwarding Across Abstract Hierarchies with Shifted Template Indexes', function () {
    test('infers method template arguments T and U from implementing class when abstract parent has extra leading template', function () {
        $concreteBox = new ConcreteBox();

        $consumer = new BoxConsumer($concreteBox);

        expect($consumer->box)->toBe($concreteBox);
    });

    test('reifies bound generic types on concrete box instance matching both interface and class templates', function () {
        $concreteBox = new ConcreteBox();

        expect(TypePHP::getGenericType($concreteBox, 'T'))->toBe(ItemA::class)
            ->and(TypePHP::getGenericType($concreteBox, 'U'))->toBe(ItemB::class)
        ;
    });
});