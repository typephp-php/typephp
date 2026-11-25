<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Parser\TokenIterator;
use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

describe('CallableWrapper Variadic @typephp-ignore Suppression', function () {
    beforeEach(function () {
        Config::reset();
        CallableWrapper::reset();
    });

    afterEach(function () {
        Config::reset();
        CallableWrapper::reset();
    });

    $parseCallableNode = static function (string $signature): PHPStan\PhpDocParser\Ast\Type\TypeNode {
        [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();

        return $typeParser->parse(new TokenIterator($lexer->tokenize($signature)));
    };

    $caller = new class () {
        /**
         * @typephp-ignore
         */
        public function invokeIgnoredVariadic(Closure $cb, mixed ...$args): void
        {
            $cb(...$args);
        }

        public function invokeEnforcedVariadic(Closure $cb, mixed ...$args): void
        {
            $cb(...$args);
        }

        /**
         * @typephp-ignore
         */
        public function invokeIgnoredVariadicByRef(Closure $cb, mixed &$a, mixed &$b): void
        {
            $cb($a, $b);
        }

        public function invokeEnforcedVariadicByRef(Closure $cb, mixed &$a, mixed &$b): void
        {
            $cb($a, $b);
        }
    };

    test('suppresses variadic callback argument violations when caller has @typephp-ignore', function () use ($parseCallableNode, $caller) {
        $typeNode = $parseCallableNode('callable(positive-int ...$nums): void');
        $received = [];

        $wrapped = CallableWrapper::wrapTypeNode(
            $typeNode,
            static function (mixed ...$nums) use (&$received): void {
                $received = $nums;
            },
            'VariadicCallback',
            new TypeValidatorRegistry()
        );

        expect($wrapped)->toBeInstanceOf(Closure::class);

        $caller->invokeIgnoredVariadic($wrapped, 1, -5, 3);
        expect($received)->toBe([1, -5, 3]);

        expect(fn () => $caller->invokeEnforcedVariadic($wrapped, 1, -5, 3))
            ->toThrow(TypePHPTypeError::class, 'positive-int')
        ;
    });

    test('suppresses variadic by-reference callback mutation violations when caller has @typephp-ignore', function () use ($parseCallableNode, $caller) {
        $typeNode = $parseCallableNode('callable(positive-int &...$nums): void');

        $wrapped = CallableWrapper::wrapTypeNode(
            $typeNode,
            static function (mixed &...$nums): void {
                $nums[1] = -99;
            },
            'VariadicRefCallback',
            new TypeValidatorRegistry()
        );

        expect($wrapped)->toBeInstanceOf(Closure::class);

        $a = 10;
        $b = 20;
        $caller->invokeIgnoredVariadicByRef($wrapped, $a, $b);
        expect($b)->toBe(-99);

        $c = 10;
        $d = 20;
        expect(fn () => $caller->invokeEnforcedVariadicByRef($wrapped, $c, $d))
            ->toThrow(TypePHPTypeError::class, 'positive-int')
        ;
    });

    test('enforces variadic callback violations for ignored callers when respect_ignore_tags is disabled', function () use ($parseCallableNode, $caller) {
        Config::set(['respect_ignore_tags' => false]);

        $argNode = $parseCallableNode('callable(positive-int ...$nums): void');
        $wrappedArg = CallableWrapper::wrapTypeNode(
            $argNode,
            static function (mixed ...$nums): void {
            },
            'VariadicCallback',
            new TypeValidatorRegistry()
        );

        expect(fn () => $caller->invokeIgnoredVariadic($wrappedArg, 1, -5))
            ->toThrow(TypePHPTypeError::class, 'positive-int')
        ;

        $refNode = $parseCallableNode('callable(positive-int &...$nums): void');
        $wrappedRef = CallableWrapper::wrapTypeNode(
            $refNode,
            static function (mixed &...$nums): void {
                $nums[1] = -99;
            },
            'VariadicRefCallback',
            new TypeValidatorRegistry()
        );

        $a = 10;
        $b = 20;
        expect(fn () => $caller->invokeIgnoredVariadicByRef($wrappedRef, $a, $b))
            ->toThrow(TypePHPTypeError::class, 'positive-int')
        ;
    });
});
