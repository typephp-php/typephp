<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Tests\Fixtures\Services\BaseService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\UserApi;

describe('SpecialTypeResolver Unit Tests', function () {
    test('resolves self to declaring class FQCN', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');
        $node = new IdentifierTypeNode('self');

        $resolved = SpecialTypeResolver::resolve($node, $ref);

        expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($resolved->name)->toBe(UserService::class)
        ;
    });

    test('resolves parent to parent class FQCN', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');
        $node = new IdentifierTypeNode('parent');

        $resolved = SpecialTypeResolver::resolve($node, $ref);

        expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($resolved->name)->toBe(BaseService::class)
        ;
    });

    test('preserves $this and static keyword nodes', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');

        $thisNode = new IdentifierTypeNode('$this');
        $staticNode = new IdentifierTypeNode('static');

        expect(SpecialTypeResolver::resolve($thisNode, $ref)->name)->toBe('$this')
            ->and(SpecialTypeResolver::resolve($staticNode, $ref)->name)->toBe('static')
        ;
    });

    test('resolves imported class names using Reflection file context', function () {
        $ref = new ReflectionMethod(UserApi::class, 'saveUser');
        $node = new IdentifierTypeNode('LocalUserShape');

        $resolved = SpecialTypeResolver::resolve($node, $ref);

        expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class);
    });

    test('normalizes backslashes to forward slashes in file metadata seeding and lookups', function () {
        $windowsPath = 'C:\\project\\app\\Services\\UserService.php';
        SpecialTypeResolver::seedFileMetadata($windowsPath, 'App\\Services', ['User' => 'App\\Models\\User']);

        $forwardPath = 'C:/project/app/Services/UserService.php';

        expect(SpecialTypeResolver::getNamespaceFromFile($forwardPath))->toBe('App\\Services')
            ->and(SpecialTypeResolver::getUseImportsFromFile($forwardPath))->toHaveKey('User')
        ;
    });

    test('leaves built-in scalar and pseudo-type keywords untouched', function () {
        $ref = new ReflectionMethod(UserService::class, 'find');

        $primitives = ['int', 'string', 'bool', 'float', 'array', 'mixed', 'void', 'positive-int', 'non-empty-string'];

        foreach ($primitives as $primitive) {
            $node = new IdentifierTypeNode($primitive);
            $resolved = SpecialTypeResolver::resolve($node, $ref);

            expect($resolved->name)->toBe($primitive);
        }
    });

    test('resolves class names for file context using resolveForFile', function () {
        $filePath = (new ReflectionClass(UserApi::class))->getFileName();
        expect($filePath)->not()->toBeFalse();

        $node = new IdentifierTypeNode('GlobalTypes');
        $resolved = SpecialTypeResolver::resolveForFile($node, (string) $filePath);

        expect($resolved)->toBeInstanceOf(IdentifierTypeNode::class)
            ->and($resolved->name)->toBe('TypePHP\Tests\Fixtures\Types\GlobalTypes')
        ;
    });
});
