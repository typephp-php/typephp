<?php

declare(strict_types=1);

use TypePHP\Exception\TypeError;
use TypePHP\Tests\Fixtures\Enums\Suit;
use TypePHP\Tests\Fixtures\Traits\Context\A\ImplA;
use TypePHP\Tests\Fixtures\Traits\Context\B\ImplB;
use TypePHP\Tests\Fixtures\Traits\Context\MultiConsumer;
use TypePHP\Tests\Fixtures\Types\StatusEnum;

describe('Trait Context Resolution (Namespaces and Use Imports)', function () {
    test('resolves conflicting use-statement aliases across different traits', function () {
        $consumer = new MultiConsumer();

        $consumer->traitAProp = Suit::Hearts;
        expect($consumer->traitAProp)->toBe(Suit::Hearts);

        expect(fn () => $consumer->traitAProp = StatusEnum::Active)
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Enums\Suit')
        ;

        $consumer->traitBProp = StatusEnum::Active;
        expect($consumer->traitBProp)->toBe(StatusEnum::Active);

        expect(fn () => $consumer->traitBProp = Suit::Hearts)
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Types\StatusEnum')
        ;
    });

    test('resolves same-namespace typehints without explicit imports in traits', function () {
        $consumer = new MultiConsumer();

        $implA = new ImplA();
        expect($consumer->processA($implA))->toBe(ImplA::class);

        expect(fn () => $consumer->processA(new stdClass()))
            ->toThrow(TypeError::class, 'TypePHP\Tests\Fixtures\Traits\Context\A\InterfaceA')
        ;

        $implB = new ImplB();
        expect($consumer->processB($implB))->toBe(ImplB::class);
    });

    test('resolves type contracts on static properties defined in traits', function () {
        MultiConsumer::$traitAStaticProp = Suit::Diamonds;
        expect(MultiConsumer::$traitAStaticProp)->toBe(Suit::Diamonds);

        expect(fn () => MultiConsumer::$traitAStaticProp = 'invalid')
            ->toThrow(TypeError::class, 'must be of type TypePHP\Tests\Fixtures\Enums\Suit')
        ;
    });

    test('resolves return types on magic methods (@method) declared in traits', function () {
        $consumer = new MultiConsumer();

        $suit = $consumer->getMagicSuit();

        expect($suit)->toBe(Suit::Spades);
    });
});
