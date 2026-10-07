<?php

declare(strict_types=1);

use TypePHP\Internal\Docblock\DocblockExtractor;
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

    describe('Nested and Curried Callables / Closures Normalization (Mago & Functional Syntax)', function () {
        describe('1. Basic 2-Level Nested Callables', function () {
            test('normalizes unparenthesized 2-level callable (callable -> callable)', function () {
                $doc = '/** @param callable(int): callable(string): bool $factory */';
                $expected = '/** @param callable(int): (callable(string): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes unparenthesized 2-level Closure (Closure -> Closure)', function () {
                $doc = '/** @param Closure(int): Closure(string): bool $factory */';
                $expected = '/** @param Closure(int): (Closure(string): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes unparenthesized static-closure variants', function () {
                $doc = '/** @param static-closure(int): static-closure(string): bool $factory */';
                $expected = '/** @param static-closure(int): (static-closure(string): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes mixed callable, Closure, and static-closure combinations', function () {
                $doc = '/** @param callable(int): Closure(string): static-closure(float): bool $fn */';
                $expected = '/** @param callable(int): (Closure(string): (static-closure(float): bool)) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes parenthesized (static Closure) inside nested callable return type', function () {
                $doc = '/** @param callable(int): (static Closure)(string): bool $factory */';
                $expected = '/** @param callable(int): (static-closure(string): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes bare static Closure inside nested callable return type', function () {
                $doc = '/** @param callable(int): static Closure(string): bool $factory */';
                $expected = '/** @param callable(int): (static-closure(string): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes 3-level currying with mixed static Closure and callable syntax', function () {
                $doc = '/** @param (static Closure)(int): static Closure(string): (static Closure)(float): int $chain */';
                $expected = '/** @param static-closure(int): (static-closure(string): (static-closure(float): int)) $chain */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes higher-order callable taking parenthesized (static Closure) argument', function () {
                $doc = '/** @param callable((static Closure)(int): bool): string $cb */';
                $expected = '/** @param callable(static-closure(int): bool): string $cb */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('2. Multi-Level Deep Currying (3+ Levels)', function () {
            test('normalizes 3-level curried callable pipeline', function () {
                $doc = '/** @param callable(int): callable(string): callable(float): int $chain */';
                $expected = '/** @param callable(int): (callable(string): (callable(float): int)) $chain */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes 4-level deeply nested curried callable pipeline', function () {
                $doc = '/** @param callable(int): callable(string): callable(float): callable(bool): string $deep */';
                $expected = '/** @param callable(int): (callable(string): (callable(float): (callable(bool): string))) $deep */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('3. Parameter Signatures (Named, Variadic, and Optional)', function () {
            test('normalizes nested callables with named parameters ($threshold, $s)', function () {
                $doc = '/** @param callable(int $threshold): callable(string $s): bool $factory */';
                $expected = '/** @param callable(int $threshold): (callable(string $s): bool) $factory */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callables with variadic and optional parameters', function () {
                $doc = '/** @param callable(int ...$ids): callable(string, ?float=): bool $fn */';
                $expected = '/** @param callable(int ...$ids): (callable(string, ?float=): bool) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callables taking multiple arguments per stage', function () {
                $doc = '/** @param callable(int, string): callable(float, bool, array): int $fn */';
                $expected = '/** @param callable(int, string): (callable(float, bool, array): int) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('4. Complex Nested Return Types (Shapes, Unions, Generics, Nullables)', function () {
            test('normalizes nested callable returning a parenthesized union', function () {
                $doc = '/** @param callable(int): callable(string): (bool|int) $fn */';
                $expected = '/** @param callable(int): (callable(string): (bool|int)) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callable returning an unparenthesized union', function () {
                $doc = '/** @param callable(int): callable(string): bool|int $fn */';
                $expected = '/** @param callable(int): (callable(string): bool|int) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callable returning an array shape', function () {
                $doc = '/** @param callable(int): callable(string): array{status: bool, code: int} $fn */';
                $expected = '/** @param callable(int): (callable(string): array{status: bool, code: int}) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callable returning a generic list/array', function () {
                $doc = '/** @param callable(int): callable(string): list<positive-int> $fn */';
                $expected = '/** @param callable(int): (callable(string): list<positive-int>) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callable returning a nullable type', function () {
                $doc = '/** @param callable(int): callable(string): ?string $fn */';
                $expected = '/** @param callable(int): (callable(string): ?string) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('5. Tag Contexts (@return, @var, @phpstan-type, @method)', function () {
            test('normalizes unparenthesized nested callable in @return tag', function () {
                $doc = '/** @return callable(int): callable(string): bool */';
                $expected = '/** @return callable(int): (callable(string): bool) */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes unparenthesized nested callable in @var tag', function () {
                $doc = '/** @var callable(int): callable(string): bool $pipeline */';
                $expected = '/** @var callable(int): (callable(string): bool) $pipeline */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes unparenthesized nested callable in @phpstan-type alias definition', function () {
                $doc = '/** @phpstan-type CurriedPipeline callable(int): callable(string): bool */';
                $expected = '/** @phpstan-type CurriedPipeline callable(int): (callable(string): bool) */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes unparenthesized nested callable in @method tag', function () {
                $doc = '/** @method callable(int): callable(string): bool createPipeline(string $prefix) */';
                $expected = '/** @method callable(int): (callable(string): bool) createPipeline(string $prefix) */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('6. Higher-Order Callables (Callables Taking Callables)', function () {
            test('preserves higher-order callable where argument is a callable and return is a scalar', function () {
                $doc = '/** @param callable(callable(int): string): bool $cb */';

                expect(DocblockNormalizer::normalize($doc))->toBe($doc);
            });

            test('normalizes higher-order callable where argument is an unparenthesized curried callable', function () {
                $doc = '/** @param callable(callable(int): callable(string): bool): bool $cb */';
                $expected = '/** @param callable(callable(int): (callable(string): bool)): bool $cb */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('7. Omitted Return Type Combinations', function () {
            test('auto-completes omitted return type on inner callable and parenthesizes', function () {
                $doc = '/** @param callable(int): callable(string) $fn */';
                $expected = '/** @param callable(int): (callable(string): mixed) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('auto-completes zero-argument callables with omitted return types', function () {
                $doc = '/** @param callable(): callable() $fn */';
                $expected = '/** @param callable(): (callable(): mixed) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });
        });

        describe('8. Whitespace, Multi-Line & Idempotency Guarantees', function () {
            test('leaves already parenthesized 2-level and 3-level callables untouched (idempotent)', function () {
                $doc2 = '/** @param callable(int): (callable(string): bool) $factory */';
                expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);

                $doc3 = '/** @param callable(int): (callable(string): (callable(float): int)) $chain */';
                expect(DocblockNormalizer::normalize($doc3))->toBe($doc3);
            });

            test('normalizes multiple separate nested callable parameters in the same DocBlock', function () {
                $doc = <<<'DOC'
/**
 * @param callable(int): callable(string): bool $a
 * @param callable(float): callable(bool): int $b
 */
DOC;
                $expected = <<<'DOC'
/**
 * @param callable(int): (callable(string): bool) $a
 * @param callable(float): (callable(bool): int) $b
 */
DOC;
                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('normalizes nested callables with extra spacing around colons and keywords', function () {
                $doc = '/** @param callable(int)   :   callable(string)   :   bool $fn */';
                $expected = '/** @param callable(int)   : (callable(string)   :   bool) $fn */';

                expect(DocblockNormalizer::normalize($doc))->toBe($expected);
            });

            test('guarantees valid AST parsing through phpstan/phpdoc-parser after normalization', function () {
                $rawDoc = '/** @param callable(int $a): callable(string $b): callable(float $c): int $chain */';
                $normalized = DocblockNormalizer::normalize($rawDoc);

                $phpDocNode = DocblockExtractor::parseDocString($normalized);
                $paramTags = DocblockExtractor::getParamTags($phpDocNode);

                expect($paramTags)->toHaveKey('chain')
                    ->and((string) $paramTags['chain']->type)->toBe('callable(int $a): (callable(string $b): (callable(float $c): int))')
                ;
            });
        });
    });

    describe('Static Closure Normalization', function () {
        test('normalizes parenthesized (static Closure)(args) to static-closure(args)', function () {
            $doc = '/** @param (static Closure)(int): int $fn */';
            $expected = '/** @param static-closure(int): int $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes parenthesized (static Closure) with arbitrary whitespace', function () {
            $doc = '/** @param (  static   Closure  )(int, string): bool $fn */';
            $expected = '/** @param static-closure(int, string): bool $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes bare static Closure(args) without parentheses', function () {
            $doc = '/** @param static Closure(int): int $fn */';
            $expected = '/** @param static-closure(int): int $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes bare static Closure parameter without argument list', function () {
            $docParen = '/** @param (static Closure) $fn */';
            $expectedParen = '/** @param static-closure $fn */';
            expect(DocblockNormalizer::normalize($docParen))->toBe($expectedParen);

            $docBare = '/** @param static Closure $fn */';
            $expectedBare = '/** @param static-closure $fn */';
            expect(DocblockNormalizer::normalize($docBare))->toBe($expectedBare);
        });

        test('normalizes case-insensitive static Closure variants', function () {
            $doc = '/** @param (STATIC closure)(int): int $fn */';
            $expected = '/** @param static-closure(int): int $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('normalizes static Closure with omitted return type to mixed', function () {
            $doc = '/** @param (static Closure)(int $x) $fn */';
            $expected = '/** @param static-closure(int $x): mixed $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($expected);
        });

        test('preserves already canonical static-closure syntax untouched', function () {
            $doc = '/** @param static-closure(int): string $fn */';
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('does not affect standard non-static Closure or standalone static keywords', function () {
            $closureDoc = '/** @param Closure(int): string $fn */';
            expect(DocblockNormalizer::normalize($closureDoc))->toBe($closureDoc);

            $staticDoc = '/** @return static */';
            expect(DocblockNormalizer::normalize($staticDoc))->toBe($staticDoc);
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

        test('preserves callable signatures containing inner parenthesized conditional types without inserting premature : mixed', function () {
            $doc1 = '/** @param callable((T is int ? positive-int : T)): bool $callback */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param Closure((T is string ? non-empty-string : int)): string $closure */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);
        });

        test('preserves callable signatures containing inner parenthesized unions and intersections', function () {
            $doc1 = '/** @param callable((int|string)): bool $cb */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param callable((Countable&ArrayAccess)): void $cb */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);
        });

        test('preserves nested higher-order callable signatures', function () {
            $doc = '/** @param callable(callable(int): string): bool $cb */';
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
        });

        test('auto-completes omitted return types for callables with inner parenthesized parameter types', function () {
            $doc1 = '/** @param callable((T is int ? positive-int : T) $x) $cb */';
            $expected1 = '/** @param callable((T is int ? positive-int : T) $x): mixed $cb */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

            $doc2 = '/** @param callable((int|string) $x) $cb */';
            $expected2 = '/** @param callable((int|string) $x): mixed $cb */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);
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

    describe('Unqualified int-mask-of Wildcard Normalization', function () {
        test('quotes unqualified wildcard constant patterns in int-mask-of', function () {
            $doc1 = '/** @param int-mask-of<E_*> $flags */';
            $expected1 = '/** @param int-mask-of<"E_*"> $flags */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($expected1);

            $doc2 = '/** @param int-mask-of<JSON_*> $flags */';
            $expected2 = '/** @param int-mask-of<"JSON_*"> $flags */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($expected2);
        });

        test('preserves class-scoped int-mask-of wildcards without quoting', function () {
            $doc1 = '/** @param int-mask-of<Permissions::*> $mask */';
            expect(DocblockNormalizer::normalize($doc1))->toBe($doc1);

            $doc2 = '/** @param int-mask-of<HttpOptions::FLAG_*> $flags */';
            expect(DocblockNormalizer::normalize($doc2))->toBe($doc2);
        });

        test('does not double-quote already quoted int-mask-of wildcards', function () {
            $doc = '/** @param int-mask-of<"E_*"> $flags */';
            expect(DocblockNormalizer::normalize($doc))->toBe($doc);
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
