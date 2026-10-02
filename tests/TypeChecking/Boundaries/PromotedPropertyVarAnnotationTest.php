<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Boundaries;

use TypePHP\Exception\TypeError;

class PromotedVarUnnamedOrder
{
    public function __construct(
        /**
         * @var positive-int
         */
        public int $orderId,
    ) {
    }
}

class PromotedVarNamedSku
{
    public function __construct(
        /**
         * @var non-empty-string $sku
         */
        public string $sku,
    ) {
    }
}

class PromotedToolingVarOrder
{
    public function __construct(
        /**
         * @phpstan-var positive-int
         */
        public int $id,
        /**
         * @psalm-var non-empty-string
         */
        public string $code,
    ) {
    }
}

class PromotedMixedMultipleOrder
{
    public function __construct(
        /**
         * @var positive-int
         */
        public int $id,
        /**
         * @var non-empty-string
         */
        public string $name,
        /**
         * @var int<1, 100>
         */
        public int $quantity = 1,
    ) {
    }
}

if (PHP_VERSION_ID >= 80400) {
    require_once __DIR__ . '/../../Fixtures/PropertyHooks/AsymmetricPromotedPropertyFixtures.php';
}

describe('Constructor Property Promotion (CPP) with /** @var */ Annotations', function () {
    describe('Standard Promoted Properties (PHP 8.0+)', function () {
        test('accepts valid value on promoted parameter with unnamed /** @var */', function () {
            $order = new PromotedVarUnnamedOrder(100);

            expect($order->orderId)->toBe(100);
        });

        test('rejects invalid value on promoted parameter with unnamed /** @var */', function () {
            expect(fn () => new PromotedVarUnnamedOrder(-50))
                ->toThrow(TypeError::class, 'Argument $orderId must be of type positive-int, negative int (-50) given')
            ;
        });

        test('accepts valid value on promoted parameter with named /** @var Type $name */', function () {
            $product = new PromotedVarNamedSku('SKU-100');

            expect($product->sku)->toBe('SKU-100');
        });

        test('rejects invalid value on promoted parameter with named /** @var Type $name */', function () {
            expect(fn () => new PromotedVarNamedSku(''))
                ->toThrow(TypeError::class, 'Argument $sku must be of type non-empty-string')
            ;
        });

        test('enforces @phpstan-var and @psalm-var on promoted parameters', function () {
            $order = new PromotedToolingVarOrder(42, 'ORDER-42');
            expect($order->id)->toBe(42)->and($order->code)->toBe('ORDER-42');

            expect(fn () => new PromotedToolingVarOrder(-1, 'ORDER-42'))
                ->toThrow(TypeError::class, 'Argument $id must be of type positive-int')
            ;

            expect(fn () => new PromotedToolingVarOrder(42, ''))
                ->toThrow(TypeError::class, 'Argument $code must be of type non-empty-string')
            ;
        });

        test('validates multiple promoted parameters with varied types', function () {
            $order = new PromotedMixedMultipleOrder(10, 'Widgets', 5);
            expect($order->id)->toBe(10)
                ->and($order->name)->toBe('Widgets')
                ->and($order->quantity)->toBe(5)
            ;

            expect(fn () => new PromotedMixedMultipleOrder(-5, 'Widgets', 5))
                ->toThrow(TypeError::class, 'Argument $id must be of type positive-int')
            ;

            expect(fn () => new PromotedMixedMultipleOrder(10, '', 5))
                ->toThrow(TypeError::class, 'Argument $name must be of type non-empty-string')
            ;

            expect(fn () => new PromotedMixedMultipleOrder(10, 'Widgets', 150))
                ->toThrow(TypeError::class, 'Argument $quantity')
            ;
        });
    });

    describe('PHP 8.4 Asymmetric Visibility Promoted Properties', function () {
        test('accepts valid value on asymmetric visibility promoted parameter with /** @var */', function () {
            if (PHP_VERSION_ID < 80400) {
                expect(true)->toBeTrue();

                return;
            }

            $order = new \TypePHP\Tests\Fixtures\PropertyHooks\AsymmetricPromotedVarOrder(100);

            expect($order->orderId)->toBe(100);
        });

        test('rejects invalid value on asymmetric visibility promoted parameter with /** @var */', function () {
            if (PHP_VERSION_ID < 80400) {
                expect(true)->toBeTrue();

                return;
            }

            expect(fn () => new \TypePHP\Tests\Fixtures\PropertyHooks\AsymmetricPromotedVarOrder(-50))
                ->toThrow(TypeError::class, 'Argument $orderId must be of type positive-int, negative int (-50) given')
            ;
        });
    });
});
