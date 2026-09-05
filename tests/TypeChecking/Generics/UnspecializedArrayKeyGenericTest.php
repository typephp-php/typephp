<?php

declare(strict_types=1);

namespace TypePHP\Tests\TypeChecking\Generics;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\TypePHP;

/**
 * Fixture mirroring Tempest ImmutableArray<TKey of array-key, TValue>
 *
 * @template TKey of array-key = array-key
 * @template TValue = mixed
 */
class TestGenericViteArray
{
    /**
     * @var array<TKey, TValue>
     */
    public array $elements = [];
}

class TestViteChunk
{
    public function __construct(public string $file = 'app.js')
    {
    }
}

class TestViteManifest
{
    /**
     * @param TestGenericViteArray<int, TestViteChunk> $chunks
     */
    public function __construct(
        public TestGenericViteArray $chunks
    ) {
    }
}

describe('Unspecialized array-key Generic Specialization (Tempest Vite Manifest pattern)', function () {
    test('specializes unspecialized array-key collection instance to int without throwing invariant mismatch', function () {
        $chunksInstance = new TestGenericViteArray();

        TemplateManager::bindTemplate('none', $chunksInstance, 'TKey', new IdentifierTypeNode('array-key'));
        TemplateManager::bindTemplate('none', $chunksInstance, 'TValue', new IdentifierTypeNode(TestViteChunk::class));

        $manifest = new TestViteManifest($chunksInstance);

        expect($manifest->chunks)->toBe($chunksInstance)
            ->and(TypePHP::getGenericType($chunksInstance, 'TKey'))->toBe('int')
            ->and(TypePHP::getGenericType($chunksInstance, 'TValue'))->toBe(TestViteChunk::class)
        ;
    });
});
