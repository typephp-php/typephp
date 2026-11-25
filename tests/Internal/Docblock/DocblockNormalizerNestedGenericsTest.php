<?php

declare(strict_types=1);

namespace TypePHP\Tests\Internal\Docblock;

use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockNormalizer;

describe('DocblockNormalizer Nested Angle Brackets and Callables (Issue 5)', function () {
    describe('1. Callable Generic Parameter Preservation (<T>)', function () {
        test('preserves generic template <T> when auto-completing omitted return type to : mixed', function () {
            $doc = '/** @param callable<T>(T) $fn */';
            $normalized = DocblockNormalizer::normalize($doc);

            expect($normalized)->toBe('/** @param callable<T>(T): mixed $fn */');

            $parsed = DocblockExtractor::parseDocString($normalized);
            $tag = DocblockExtractor::getParamTags($parsed)['fn'] ?? null;
            expect($tag)->not()->toBeNull()
                ->and((string) $tag->type)->toBe('callable<T>(T): mixed')
            ;
        });

        test('preserves generic template <T, R> on Closure with omitted return type', function () {
            $doc = '/** @param Closure<T, R>(T) $fn */';
            $normalized = DocblockNormalizer::normalize($doc);

            expect($normalized)->toBe('/** @param Closure<T, R>(T): mixed $fn */');
        });

        test('handles nested angle brackets inside callable template bounds without truncation', function () {
            $doc = '/** @param callable<T of array<int>>(T) $fn */';
            $normalized = DocblockNormalizer::normalize($doc);

            expect($normalized)->toBe('/** @param callable<T of array<int>>(T): mixed $fn */');

            $parsed = DocblockExtractor::parseDocString($normalized);
            $tag = DocblockExtractor::getParamTags($parsed)['fn'] ?? null;
            expect($tag)->not()->toBeNull()
                ->and((string) $tag->type)->toBe('callable<T of array<int>>(T): mixed')
            ;
        });

        test('preserves callable<T>(T): R when explicit return type is already present', function () {
            $doc = '/** @param callable<T>(T): T $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('normalizes nested curried callable that declares generic templates', function () {
            $doc = '/** @param callable(int): callable<T>(T): T $fn */';
            $expected = '/** @param callable(int): (callable<T>(T): T) $fn */';

            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });
    });

    describe('2. Tuple and Shape Spread Normalization with Nested Generics', function () {
        test('normalizes ...list<array<string, int>> without truncating at inner angle bracket', function () {
            $doc = '/** @param array{string, ...list<array<string, int>>} $tuple */';
            $normalized = DocblockNormalizer::normalize($doc);

            expect($normalized)->toBe('/** @param array{string, ...<array<string, int>>} $tuple */');

            $parsed = DocblockExtractor::parseDocString($normalized);
            $tag = DocblockExtractor::getParamTags($parsed)['tuple'] ?? null;
            expect($tag)->not()->toBeNull()
                ->and((string) $tag->type)->toBe('array{string, ...<array<string, int>>}')
            ;
        });

        test('normalizes ...array<list<positive-int>> without truncating', function () {
            $doc = '/** @param array{id: int, ...array<list<positive-int>>} $payload */';
            $normalized = DocblockNormalizer::normalize($doc);

            expect($normalized)->toBe('/** @param array{id: int, ...<list<positive-int>>} $payload */');
        });

        test('normalizes ...non-empty-list and ...non-empty-array spreads', function () {
            $docList = '/** @param array{string, ...non-empty-list<int>} $tuple */';
            expect(DocblockNormalizer::normalize($docList))->toBe('/** @param array{string, ...<int>} $tuple */');

            $docArray = '/** @param array{id: int, ...non-empty-array<string, int>} $payload */';
            expect(DocblockNormalizer::normalize($docArray))->toBe('/** @param array{id: int, ...<string, int>} $payload */');
        });
    });
});
