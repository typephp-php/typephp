<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

describe('CallableWrapper Unit Tests', function () {
    test('returns raw value if callable is not valid or node is not CallableTypeNode', function () {
        $registry = new TypeValidatorRegistry();
        $notCallable = 'not_a_callable_string_xyz';

        $result = CallableWrapper::wrapTypeNode(null, $notCallable, 'prefix', $registry);

        expect($result)->toBe($notCallable);
    });

    test('wraps callable and validates argument types on invocation', function () {
        $registry = new TypeValidatorRegistry();
        $callable = fn (int $id): string => "id_{$id}";

        $typeNode = new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [
                new CallableTypeParameterNode(
                    new IdentifierTypeNode('positive-int'),
                    false,
                    false,
                    'id',
                    false
                ),
            ],
            new IdentifierTypeNode('string'),
            []
        );

        $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestCallback', $registry);

        expect($wrapped(10))->toBe('id_10');

        expect(fn () => $wrapped(-5))
            ->toThrow(TypeError::class, 'TestCallback $id must be of type positive-int')
        ;
    });

    test('enforces static-closure constraints', function () {
        $registry = new TypeValidatorRegistry();
        $typeNode = new CallableTypeNode(
            new IdentifierTypeNode('static-closure'),
            [],
            new IdentifierTypeNode('void'),
            []
        );

        $nonStatic = fn () => null;

        expect(fn () => CallableWrapper::wrapTypeNode($typeNode, $nonStatic, 'TestStatic', $registry))
            ->toThrow(TypeError::class, 'must be a static Closure')
        ;
    });

    test('preserves by-reference parameter mutations in wrapped callback', function () {
        $registry = new TypeValidatorRegistry();
        $callable = function (int &$num): void {
            $num += 50;
        };

        $typeNode = new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [
                new CallableTypeParameterNode(
                    new IdentifierTypeNode('positive-int'),
                    true, // isReference: true
                    false,
                    'num',
                    false
                ),
            ],
            new IdentifierTypeNode('void'),
            []
        );

        $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestByRef', $registry);

        $val = 10;
        $wrapped($val);
        expect($val)->toBe(60);

        // Input validation check on entry
        $badVal = -5;
        expect(fn () => $wrapped($badVal))
            ->toThrow(TypeError::class, 'TestByRef $num must be of type positive-int')
        ;

        // Post-mutation validation check on exit
        $badMutator = function (int &$num): void {
            $num = -100;
        };
        $wrappedBad = CallableWrapper::wrapTypeNode($typeNode, $badMutator, 'TestByRefBad', $registry);
        $val2 = 10;
        expect(fn () => $wrappedBad($val2))
            ->toThrow(TypeError::class, 'TestByRefBad $num must be of type positive-int')
        ;
    });

    test('handles mixed by-ref and by-value parameters in wrapped callback', function () {
        $registry = new TypeValidatorRegistry();
        $callable = function (int &$item, string $key): void {
            $item *= 2;
        };

        $typeNode = new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [
                new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, false, 'item', false),
                new CallableTypeParameterNode(new IdentifierTypeNode('string'), false, false, 'key', false),
            ],
            new IdentifierTypeNode('void'),
            []
        );

        $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestMixed', $registry);

        $num = 25;
        $wrapped($num, 'key_1');
        expect($num)->toBe(50);
    });

    test('preserves variadic by-reference mutations in wrapped callback', function () {
        $registry = new TypeValidatorRegistry();
        $callable = function (int &...$numbers): void {
            foreach ($numbers as &$n) {
                $n += 5;
            }
        };

        $typeNode = new CallableTypeNode(
            new IdentifierTypeNode('callable'),
            [
                new CallableTypeParameterNode(new IdentifierTypeNode('positive-int'), true, true, 'numbers', false),
            ],
            new IdentifierTypeNode('void'),
            []
        );

        $wrapped = CallableWrapper::wrapTypeNode($typeNode, $callable, 'TestVariadicRef', $registry);

        $a = 10;
        $b = 20;
        $wrapped($a, $b);
        expect($a)->toBe(15)
            ->and($b)->toBe(25)
        ;
    });

    test('isCallable helper accurately identifies callables and rejects unsafe deprecated relative strings', function () {
        expect(CallableWrapper::isCallable('strlen'))->toBeTrue();
        expect(CallableWrapper::isCallable(fn () => 1))->toBeTrue();
        expect(CallableWrapper::isCallable(new class () {
            public function __invoke(): void
            {
            }
        }))->toBeTrue();

        expect(CallableWrapper::isCallable(''))->toBeFalse();
        expect(CallableWrapper::isCallable('static::method'))->toBeFalse();
        expect(CallableWrapper::isCallable(['static', 'method']))->toBeFalse();
        expect(CallableWrapper::isCallable(['self', 'method']))->toBeFalse();
        expect(CallableWrapper::isCallable(['parent', 'method']))->toBeFalse();
        expect(CallableWrapper::isCallable('not_callable_123'))->toBeFalse();
        expect(CallableWrapper::isCallable(123))->toBeFalse();
    });
});
