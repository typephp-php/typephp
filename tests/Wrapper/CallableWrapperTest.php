<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;

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
});