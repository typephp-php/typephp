<?php

declare(strict_types=1);

use TypePHP\Internal\DocblockNormalizer;

describe('DocblockNormalizer', function () {
    test('returns docblock string unchanged when no curly braces are present', function () {
        $doc = '/** @param string $name */';
        expect(DocblockNormalizer::normalize($doc))->toBe($doc);
    });

    test('strips optional equals sign from @phpstan-type and @psalm-type tags', function () {
        $doc1 = '/** @phpstan-type MetricTypeValues = "histogram"|"gauge" */';
        $expected1 = '/** @phpstan-type MetricTypeValues "histogram"|"gauge" */';
        expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

        $doc2 = '/** @psalm-type UserRole = "admin"|"user" */';
        $expected2 = '/** @psalm-type UserRole "admin"|"user" */';
        expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);
    });

    test('converts stdClass shapes into intersection shapes', function () {
        $doc = '/** @param stdClass{id: int, name: string} $data */';
        $expected = '/** @param (stdClass&object{id: int, name: string}) $data */';

        expect(DocblockNormalizer::normalize($doc))->toBe($expected);
    });

    test('converts namespaced class shapes into intersection shapes', function () {
        $doc1 = '/** @param \stdClass{id: int} $data */';
        $expected1 = '/** @param (\stdClass&object{id: int}) $data */';
        expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

        $doc2 = '/** @param App\Models\User{id: positive-int} $user */';
        $expected2 = '/** @param (App\Models\User&object{id: positive-int}) $user */';
        expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);
    });

    test('preserves built-in PHPStan shape keywords (array, list, object, non-empty-array, non-empty-list)', function () {
        $arrayDoc = '/** @param array{id: int, name: string} $data */';
        expect(DocblockNormalizer::normalize($arrayDoc))->toBe($arrayDoc);

        $listDoc = '/** @param list{int, string} $data */';
        expect(DocblockNormalizer::normalize($listDoc))->toBe($listDoc);

        $objectDoc = '/** @param object{id: int} $data */';
        expect(DocblockNormalizer::normalize($objectDoc))->toBe($objectDoc);

        $nonEmptyArrayDoc = '/** @param non-empty-array{id: int} $data */';
        expect(DocblockNormalizer::normalize($nonEmptyArrayDoc))->toBe($nonEmptyArrayDoc);

        $nonEmptyListDoc = '/** @param non-empty-list{string} $data */';
        expect(DocblockNormalizer::normalize($nonEmptyListDoc))->toBe($nonEmptyListDoc);
    });

    test('handles case-insensitive built-in keywords', function () {
        $upperArrayDoc = '/** @param ARRAY{id: int} $data */';
        expect(DocblockNormalizer::normalize($upperArrayDoc))->toBe($upperArrayDoc);

        $upperObjectDoc = '/** @param OBJECT{id: int} $data */';
        expect(DocblockNormalizer::normalize($upperObjectDoc))->toBe($upperObjectDoc);
    });

    test('normalizes custom class shapes inside generic containers and unions', function () {
        $nestedDoc = '/** @param list<stdClass{id: positive-int}> $items */';
        $expected = '/** @param list<(stdClass&object{id: positive-int})> $items */';

        expect(DocblockNormalizer::normalize($nestedDoc))->toBe($expected);
    });

    test('normalizes inline @var annotations with class shapes', function () {
        $varDoc = '/** @var stdClass{id: int, role: string} $user */';
        $expected = '/** @var (stdClass&object{id: int, role: string}) $user */';

        expect(DocblockNormalizer::normalize($varDoc))->toBe($expected);
    });

    test('normalizes class shapes with weird spacing and newlines between name and brace', function () {
        $doc = '/** @param stdClass   {id: int} $data */';
        $expected = '/** @param (stdClass&object{id: int}) $data */';

        expect(DocblockNormalizer::normalize($doc))->toBe($expected);
    });

    test('normalizes standard multi-line parameter docblocks with class shapes', function () {
        $doc = "/**\n * @param stdClass{id: positive-int, name: non-empty-string} \$payload\n */";
        $expected = "/**\n * @param (stdClass&object{id: positive-int, name: non-empty-string}) \$payload\n */";

        expect(DocblockNormalizer::normalize($doc))->toBe($expected);
    });

    test('normalizes multi-line class shapes with inner newlines and asterisks', function () {
        $doc = "/**\n * @param stdClass{\n *   id: positive-int,\n *   name: non-empty-string\n * } \$data\n */";
        $expected = "/**\n * @param (stdClass&object{\n *   id: positive-int,\n *   name: non-empty-string\n * }) \$data\n */";

        expect(DocblockNormalizer::normalize($doc))->toBe($expected);
    });

    test('wraps class constant array shape keys in quotes for legacy phpdoc-parser compatibility', function () {
        $doc = '/** @param array{self::KEY_ID: int, App\Constants::ROLE: string} $payload */';

        $expected = '/** @param array{"self::KEY_ID": int, "App\Constants::ROLE": string} $payload */';

        expect(DocblockNormalizer::normalize($doc))->toBe($expected);
    });
});
