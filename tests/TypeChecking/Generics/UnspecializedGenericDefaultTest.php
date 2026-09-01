<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Exception\TypeError;
use TypePHP\Resolver\TemplateManager;
use TypePHP\TypePHP;

interface SpecificAppMiddleware
{
}

class ConcreteAppMiddleware implements SpecificAppMiddleware
{
}

class IncompatibleOtherMiddleware
{
}

/**
 * Generic container with class template
 *
 * @template TMiddleware of object
 */
class GenericMiddlewareContainer
{
    /**
     * @var array<int, TMiddleware>
     */
    public array $middlewares = [];
}

/**
 * Function accepting specialized generic container
 *
 * @param GenericMiddlewareContainer<SpecificAppMiddleware> $container
 */
function consumeMiddlewareContainer(GenericMiddlewareContainer $container): bool
{
    return true;
}

describe('Unspecialized Generic Default Objects & First-Use Parameter Binding', function () {
    test('reproduces tempest unspecialized mixed binding mismatch on un-annotated instance', function () {
        $rawContainer = new GenericMiddlewareContainer();

        TemplateManager::bindTemplate('none', $rawContainer, 'TMiddleware', new IdentifierTypeNode('mixed'));

        expect(consumeMiddlewareContainer($rawContainer))->toBeTrue()
            ->and(TypePHP::getGenericType($rawContainer))->toBe(SpecificAppMiddleware::class)
        ;
    });

    test('strictly rejects explicitly annotated instance holding an incompatible generic type', function () {
        $badContainer = new GenericMiddlewareContainer();
        TemplateManager::bindTemplate('none', $badContainer, 'TMiddleware', new IdentifierTypeNode(IncompatibleOtherMiddleware::class));

        expect(fn () => consumeMiddlewareContainer($badContainer))
            ->toThrow(TypeError::class, 'expects GenericMiddlewareContainer<invariant SpecificAppMiddleware>, but GenericMiddlewareContainer<IncompatibleOtherMiddleware> was given')
        ;
    });
});
