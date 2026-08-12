<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use TypePHP\Contract\DocblockExtractor;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Types\UserApi;

describe('DocblockExtractor Unit Tests', function () {
    test('parses raw PHPDoc comment string into PhpDocNode AST', function () {
        $doc = '/** @param positive-int $id */';
        $node = DocblockExtractor::parseDocString($doc);

        expect($node)->toBeInstanceOf(PhpDocNode::class)
            ->and(\count($node->getParamTagValues()))->toBe(1)
        ;
    });

    test('extracts @template tags from docblock node', function () {
        $doc = '/** @template T of \TypePHP\Tests\Fixtures\Domain\Animal */';
        $node = DocblockExtractor::parseDocString($doc);

        $templates = DocblockExtractor::extractTemplates($node);

        expect($templates)->toHaveKey('T')
            ->and($templates['T']->name)->toBe('T')
        ;
    });

    test('extracts property promotion type from property @var docblock', function () {
        $doc = '/** @var string[] $strings */';
        $typeNode = DocblockExtractor::extractTypeFromPropertyDoc($doc, 'strings');

        expect($typeNode)->not()->toBeNull();
    });

    test('extracts local @phpstan-type aliases', function () {
        $doc = '/** @phpstan-type StatusType "active"|"pending" */';
        $node = DocblockExtractor::parseDocString($doc);
        $aliases = [];

        $ref = new ReflectionClass(UserService::class);
        DocblockExtractor::extractAliases($node, $aliases, $ref);

        expect($aliases)->toHaveKey('StatusType');
    });

    test('resolves imported type aliases with @phpstan-import-type', function () {
        $doc = '/** @phpstan-import-type SharedShape from GlobalTypes as LocalUserShape */';
        $node = DocblockExtractor::parseDocString($doc);
        $aliases = [];

        $ref = new ReflectionClass(UserApi::class);
        DocblockExtractor::extractAliases($node, $aliases, $ref);

        expect($aliases)->toHaveKey('LocalUserShape');
    });

    test('extracts type from class-level @property, @property-read, and @property-write docblocks', function () {
        $doc = "/**\n * @property positive-int \$score\n * @property-read non-empty-string \$title\n * @property-write list<string> \$tags\n */";

        $scoreType = DocblockExtractor::extractTypeFromClassPropertyDoc($doc, 'score');
        expect((string) $scoreType)->toBe('positive-int');

        $titleType = DocblockExtractor::extractTypeFromClassPropertyDoc($doc, 'title');
        expect((string) $titleType)->toBe('non-empty-string');

        $tagsType = DocblockExtractor::extractTypeFromClassPropertyDoc($doc, 'tags');
        expect((string) $tagsType)->toBe('list<string>');

        $missingType = DocblockExtractor::extractTypeFromClassPropertyDoc($doc, 'missing');
        expect($missingType)->toBeNull();
    });
});