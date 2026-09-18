<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use TypePHP\Exception\TypeError;
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

    describe('Return Type Contracts with Forwarded Generics', function () {
        test('validates @return T correctly uses inferred ItemA', function () {
            $concreteBox = new ConcreteBox();
            $consumer = new BoxConsumer($concreteBox);

            $first = $consumer->extractFirst($concreteBox);
            expect($first)->toBeInstanceOf(ItemA::class);
        });

        test('validates @return U correctly uses inferred ItemB', function () {
            $concreteBox = new ConcreteBox();
            $consumer = new BoxConsumer($concreteBox);

            $second = $consumer->extractSecond($concreteBox);
            expect($second)->toBeInstanceOf(ItemB::class);
        });

        test('throws TypeError when @return T returns ItemB instead of inferred ItemA', function () {
            $concreteBox = new ConcreteBox();
            $consumer = new BoxConsumer($concreteBox);

            expect(fn () => $consumer->extractBad($concreteBox))
                ->toThrow(
                    TypeError::class,
                    'Return value must be of type ' . ItemA::class . ', ' . ItemB::class . ' returned'
                )
            ;
        });

        test('validates @return BoxInterface<T, U> returning the forwarded concrete instance', function () {
            $concreteBox = new ConcreteBox();
            $consumer = new BoxConsumer($concreteBox);

            $result = $consumer->passThrough($concreteBox);
            expect($result)->toBe($concreteBox);
        });
    });
});
