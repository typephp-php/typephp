<?php

declare(strict_types=1);

use TypePHP\Internal\Docblock\DocblockNormalizer;

describe('DocblockNormalizer', function () {
    test('returns docblock string unchanged when no special keywords or curly braces are present', function () {
        $doc = '/** @param string $name */';
        expect(DocblockNormalizer::normalize($doc))->toBe($doc);
    });

    describe('Fast-Path String Guards', function () {
        test('bypasses type alias regex when equals sign is absent', function () {
            $doc = '/** @phpstan-type MetricTypeValues "histogram"|"gauge" */';
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('bypasses callable regex when colon return type is already defined', function () {
            $doc1 = '/** @param callable(int): string $cb */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param Closure(int, string): bool $closure */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);
        });

        test('bypasses class constant regex when double colons or single colons are absent', function () {
            $doc1 = '/** @param string $class User::class */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param array{id: int} $data */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);
        });
    });

    describe('Callable and Closure Return Type Normalization', function () {
        test('auto-completes omitted return types for callable and Closure signatures', function () {
            $doc1 = '/** @var callable(int[] $items) $callback */';
            $expected1 = '/** @var callable(int[] $items): mixed $callback */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

            $doc2 = '/** @param Closure(string $name) $closure */';
            $expected2 = '/** @param Closure(string $name): mixed $closure */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);

            $doc3 = '/** @param callable() $emptyCallable */';
            $expected3 = '/** @param callable(): mixed $emptyCallable */';
            expect(DocblockNormalizer::normalize($doc3))->toBe($expected3);
        });
    });

    describe('Type Alias Normalization (@phpstan-type and @psalm-type)', function () {
        test('strips optional equals sign from @phpstan-type and @psalm-type tags', function () {
            $doc1 = '/** @phpstan-type MetricTypeValues = "histogram"|"gauge" */';
            $expected1 = '/** @phpstan-type MetricTypeValues "histogram"|"gauge" */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

            $doc2 = '/** @psalm-type UserRole = "admin"|"user" */';
            $expected2 = '/** @psalm-type UserRole "admin"|"user" */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);
        });
    });

    describe('Class Constant Keys in Array Shapes', function () {
        test('wraps class constant array shape keys in quotes for parser compatibility', function () {
            $doc = '/** @param array{self::KEY_ID: int, App\Constants::ROLE: string, Config::OPTIONAL?: bool} $payload */';
            $expected = '/** @param array{"self::KEY_ID": int, "App\Constants::ROLE": string, "Config::OPTIONAL"?: bool} $payload */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('does not double-quote already quoted class constant array shape keys', function () {
            $docDoubleQuoted = '/** @param array{"self::KEY_ID": int, "App\Constants::ROLE": string} $payload */';
            expect(DocblockNormalizer::normalize($docDoubleQuoted))->toBe($docDoubleQuoted);

            $docSingleQuoted = "/** @param array{'self::KEY_ID': int, 'App\\Constants::ROLE': string} \$payload */";
            expect(DocblockNormalizer::normalize($docSingleQuoted))->toBe($docSingleQuoted);
        });
    });

    describe('Variadic Tuple and Spread Syntax Normalization', function () {
        test('normalizes trailing ...Type[] spread syntax to ...<Type>', function () {
            $doc = '/** @param array{string, int, ...float[]} $tuple */';
            $expected = '/** @param array{string, int, ...<float>} $tuple */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes trailing ...list<Type> spread syntax to ...<Type>', function () {
            $doc = '/** @param array{string, ...list<positive-int>} $data */';
            $expected = '/** @param array{string, ...<positive-int>} $data */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes trailing ...array<Type> spread syntax to ...<Type>', function () {
            $doc = '/** @param array{id: int, ...array<string>} $payload */';
            $expected = '/** @param array{id: int, ...<string>} $payload */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('preserves already-compliant ...<Type> and ...<Key, Value> syntax', function () {
            $doc1 = '/** @param array{string, int, ...<float>} $tuple */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param array{id: int, ...<string, string>} $options */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);

            $doc3 = '/** @param array{string, int, ...} $bareTuple */';
            expect(DocblockNormalizer::normalize($doc3))->toBe($doc3);
        });
    });

    describe('Nested Braces in Custom Class Shapes', function () {
        test('normalizes custom class shape containing nested array shape', function () {
            $doc = '/** @param stdClass{id: int, config: array{debug: bool}} $data */';
            $expected = '/** @param (stdClass&object{id: int, config: array{debug: bool}}) $data */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes custom class shape containing multi-level nested array shapes', function () {
            $doc = '/** @param stdClass{id: int, a: array{b: array{c: string}}} $data */';
            $expected = '/** @param (stdClass&object{id: int, a: array{b: array{c: string}}}) $data */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes nested custom class shapes within custom class shapes', function () {
            $doc = '/** @param stdClass{id: int, author: User{name: string}} $data */';
            $expected = '/** @param (stdClass&object{id: int, author: (User&object{name: string})}) $data */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });
    });

    describe('@self-out and @this-out Tag Normalization', function () {
        test('normalizes @self-out to @phpstan-self-out for native parser compatibility', function () {
            $doc = "/**\n * @self-out self<'authenticated'>\n */";
            $expected = "/**\n * @phpstan-self-out self<'authenticated'>\n */";

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes @this-out to @phpstan-this-out for native parser compatibility', function () {
            $doc = "/**\n * @this-out self<'configured'>\n */";
            $expected = "/**\n * @phpstan-this-out self<'configured'>\n */";

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes conditional type syntax inside @self-out', function () {
            $doc = '/** @self-out ($asAdmin is true ? self<\'admin\'> : self<\'guest\'>) */';
            $expected = '/** @phpstan-self-out ($asAdmin is true ? self<\'admin\'> : self<\'guest\'>) */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('preserves priority when @phpstan-self-out is already present alongside @self-out', function () {
            $doc = <<<'DOC'
/**
 * @self-out self<'standard_state'>
 * @psalm-self-out self<'psalm_state'>
 * @phpstan-self-out self<'phpstan_state'>
 */
DOC;
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('preserves priority when @phpstan-this-out is already present alongside @this-out', function () {
            $doc = <<<'DOC'
/**
 * @this-out self<'standard_state'>
 * @phpstan-this-out self<'phpstan_state'>
 */
DOC;
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });
    });

    describe('Custom Class Shapes to Intersection Shapes', function () {
        test('converts stdClass shapes into intersection shapes', function () {
            $doc = '/** @param stdClass{id: int, name: string} $data */';
            $expected = '/** @param (stdClass&object{id: int, name: string}) $data */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('converts namespaced and leading backslash class shapes into intersection shapes', function () {
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

        test('normalizes class shapes with spacing and newlines between name and brace', function () {
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
    });
});
