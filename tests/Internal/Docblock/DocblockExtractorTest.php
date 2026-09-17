<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TypeParser;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Util\Config;
use TypePHP\Tests\Fixtures\Services\HelperService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Shopware\Metric\Type as MetricTypeEnum;
use TypePHP\Tests\Fixtures\Types\NestedAliasChainedB;
use TypePHP\Tests\Fixtures\Types\NestedAliasService;
use TypePHP\Tests\Fixtures\Types\UserApi;

describe('DocblockExtractor Unit Tests', function () {
    afterEach(function () {
        DocblockExtractor::reset();
        Config::reset();
    });

    describe('Core Parser Components & Cache Management', function () {
        test('getParserComponents returns shared instances of PhpDocParser and Lexer', function () {
            [$parser, $lexer] = DocblockExtractor::getParserComponents();

            expect($parser)->toBeInstanceOf(PhpDocParser::class)
                ->and($lexer)->toBeInstanceOf(Lexer::class)
            ;
        });

        test('getTypeParserComponents returns shared instances of TypeParser and Lexer', function () {
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();

            expect($typeParser)->toBeInstanceOf(TypeParser::class)
                ->and($lexer)->toBeInstanceOf(Lexer::class)
            ;
        });

        test('parses raw PHPDoc comment string into PhpDocNode AST and caches the result', function () {
            $doc = '/** @param positive-int $id */';
            $node1 = DocblockExtractor::parseDocString($doc);
            $node2 = DocblockExtractor::parseDocString($doc);

            expect($node1)->toBeInstanceOf(PhpDocNode::class)
                ->and($node1)->toBe($node2)
                ->and(\count($node1->getParamTagValues()))->toBe(1)
            ;
        });

        test('reset clears internal docParseCache', function () {
            $doc = '/** @param string $val */';
            $nodeBefore = DocblockExtractor::parseDocString($doc);

            DocblockExtractor::reset();

            $nodeAfter = DocblockExtractor::parseDocString($doc);

            expect($nodeBefore)->toEqual($nodeAfter)
                ->and($nodeBefore)->not()->toBe($nodeAfter)
            ;
        });
    });

    describe('Tag Extraction Helpers', function () {
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

            expect($typeNode)->toBeInstanceOf(TypeNode::class);
        });

        test('extracts type from constructor @param docblock on property', function () {
            $doc = '/** @param non-empty-string $username */';
            $typeNode = DocblockExtractor::extractTypeFromPropertyDoc($doc, 'username');

            expect($typeNode)->toBeInstanceOf(TypeNode::class)
                ->and((string) $typeNode)->toBe('non-empty-string')
            ;
        });

        test('extractTypeFromPropertyDoc returns null on unmatching property or malformed docblock', function () {
            $doc = '/** @var int $otherProperty */';
            expect(DocblockExtractor::extractTypeFromPropertyDoc($doc, 'targetProperty'))->toBeNull();

            $invalidDoc = '/* not a docblock */';
            expect(DocblockExtractor::extractTypeFromPropertyDoc($invalidDoc, 'any'))->toBeNull();
        });

        test('extracts variable tag from doc using extractVarTagFromDoc', function () {
            $namedDoc = '/** @var positive-int $count */';
            $named = DocblockExtractor::extractVarTagFromDoc($namedDoc);

            expect($named)->toBe(['positive-int', 'count']);

            $unnamedDoc = '/** @var non-empty-string */';
            $unnamed = DocblockExtractor::extractVarTagFromDoc($unnamedDoc);

            expect($unnamed)->toBe(['non-empty-string', '']);

            $noVarDoc = '/** @param int $x */';
            expect(DocblockExtractor::extractVarTagFromDoc($noVarDoc))->toBeNull();
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

        test('extracts magic method contract using extractMagicMethodContract', function () {
            $doc = <<<'DOC'
/**
 * @method positive-int calculateTotal(positive-int $base, non-empty-string $taxRate)
 * @method bool verifyUser(string $token)
 */
DOC;
            $contract = DocblockExtractor::extractMagicMethodContract($doc, 'calculateTotal');

            expect($contract)->toBeInstanceOf(MethodTagValueNode::class)
                ->and($contract->methodName)->toBe('calculateTotal')
                ->and((string) $contract->returnType)->toBe('positive-int')
                ->and($contract->parameters)->toHaveCount(2)
            ;

            expect(DocblockExtractor::extractMagicMethodContract($doc, 'nonExistentMethod'))->toBeNull();
        });
    });

    describe('Type Alias Extractions & Tooling Priority (@phpstan-type > @psalm-type)', function () {
        test('extracts local @phpstan-type aliases', function () {
            $doc = '/** @phpstan-type StatusType "active"|"pending" */';
            $node = DocblockExtractor::parseDocString($doc);
            $aliases = [];

            $ref = new ReflectionClass(UserService::class);
            DocblockExtractor::extractAliases($node, $aliases, $ref);

            expect($aliases)->toHaveKey('StatusType');
        });

        test('extracts standalone @psalm-type when @phpstan-type is absent', function () {
            $doc = "/** @psalm-type RoleType 'admin'|'editor' */";
            $node = DocblockExtractor::parseDocString($doc);
            $aliases = [];

            $ref = new ReflectionClass(UserService::class);
            DocblockExtractor::extractAliases($node, $aliases, $ref);

            expect($aliases)->toHaveKey('RoleType')
                ->and((string) $aliases['RoleType'])->toBe("('admin' | 'editor')")
            ;
        });

        test('prioritizes @phpstan-type over @psalm-type when both are defined', function () {
            $doc = <<<'DOC'
/**
 * @psalm-type RoleType 'admin'|'editor'
 * @phpstan-type RoleType 'admin'|'editor'|'viewer'
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $aliases = [];

            $ref = new ReflectionClass(UserService::class);
            DocblockExtractor::extractAliases($node, $aliases, $ref);

            expect($aliases)->toHaveKey('RoleType')
                ->and((string) $aliases['RoleType'])->toBe("('admin' | 'editor' | 'viewer')")
            ;
        });

        test('resolves imported type aliases with @phpstan-import-type', function () {
            $doc = '/** @phpstan-import-type SharedShape from GlobalTypes as LocalUserShape */';
            $node = DocblockExtractor::parseDocString($doc);
            $aliases = [];

            $ref = new ReflectionClass(UserApi::class);
            DocblockExtractor::extractAliases($node, $aliases, $ref);

            expect($aliases)->toHaveKey('LocalUserShape');
        });

        test('resolves imported type aliases with @psalm-import-type when phpstan tag is absent', function () {
            $doc = '/** @psalm-import-type SharedShape from GlobalTypes as LocalUserShape */';
            $node = DocblockExtractor::parseDocString($doc);
            $aliases = [];

            $ref = new ReflectionClass(UserApi::class);
            DocblockExtractor::extractAliases($node, $aliases, $ref);

            expect($aliases)->toHaveKey('LocalUserShape')
                ->and((string) $aliases['LocalUserShape'])->toContain('positive-int')
            ;
        });

        test('resolves imported type aliases from Enums', function () {
            $resolvedNode = DocblockExtractor::resolveImportedTypeAlias(MetricTypeEnum::class, 'MetricTypeValues');

            expect($resolvedNode)->not()->toBeNull()
                ->and((string) $resolvedNode)->toContain('histogram')
            ;
        });

        test('resolveImportedTypeAlias returns null on non-existent class or invalid alias', function () {
            expect(DocblockExtractor::resolveImportedTypeAlias('NonExistentClass123', 'SomeAlias'))->toBeNull();
            expect(DocblockExtractor::resolveImportedTypeAlias(UserApi::class, 'NonExistentAlias'))->toBeNull();
        });

        test('resolves multi-tier chained imported type aliases (A -> B -> C)', function () {
            $resolvedNode = DocblockExtractor::resolveImportedTypeAlias(NestedAliasChainedB::class, 'MidShape');

            expect($resolvedNode)->not()->toBeNull()
                ->and((string) $resolvedNode)->toContain('positive-int')
                ->and((string) $resolvedNode)->toContain('non-empty-string')
            ;
        });

        test('fully expands nested alias dependencies when extracting aliases from a class', function () {
            $ref = new ReflectionClass(NestedAliasService::class);
            $doc = $ref->getDocComment();
            expect($doc)->not()->toBeFalse();

            $phpDocNode = DocblockExtractor::parseDocString((string) $doc);
            $aliases = [];

            DocblockExtractor::extractAliases($phpDocNode, $aliases, $ref);

            expect($aliases)->toHaveKey('LocalRecordList')
                ->and((string) $aliases['LocalRecordList'])->toContain('positive-int')
                ->and((string) $aliases['LocalRecordList'])->toContain('active')
            ;
        });
    });

    describe('Prioritized Tag Extractions (@phpstan-* > @psalm-* > standard)', function () {
        test('prioritizes @phpstan-param over @psalm-param and @param', function () {
            $doc = <<<'DOC'
/**
 * @param mixed $element
 * @psalm-param int $element
 * @phpstan-param positive-int $element
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $paramTags = DocblockExtractor::getParamTags($node);

            expect($paramTags)->toHaveKey('element')
                ->and((string) $paramTags['element']->type)->toBe('positive-int')
            ;
        });

        test('prioritizes @psalm-param over @param when @phpstan-param is absent', function () {
            $doc = <<<'DOC'
/**
 * @param mixed $element
 * @psalm-param non-empty-string $element
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $paramTags = DocblockExtractor::getParamTags($node);

            expect($paramTags)->toHaveKey('element')
                ->and((string) $paramTags['element']->type)->toBe('non-empty-string')
            ;
        });

        test('prioritizes @phpstan-return over @psalm-return and @return', function () {
            $doc = <<<'DOC'
/**
 * @return mixed
 * @psalm-return array<string, int>
 * @phpstan-return list<positive-int>
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $returnTag = DocblockExtractor::getReturnTag($node);

            expect($returnTag)->not()->toBeNull()
                ->and((string) $returnTag->type)->toBe('list<positive-int>')
            ;
        });

        test('prioritizes @psalm-return over @return when @phpstan-return is absent', function () {
            $doc = <<<'DOC'
/**
 * @return mixed
 * @psalm-return array{id: positive-int}
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $returnTag = DocblockExtractor::getReturnTag($node);

            expect($returnTag)->not()->toBeNull()
                ->and((string) $returnTag->type)->toBe('array{id: positive-int}')
            ;
        });

        test('prioritizes @phpstan-var over @psalm-var and @var', function () {
            $doc = <<<'DOC'
/**
 * @var mixed $item
 * @psalm-var int $item
 * @phpstan-var positive-int $item
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $varTags = DocblockExtractor::getVarTags($node);

            expect($varTags)->toHaveCount(1)
                ->and((string) $varTags[0]->type)->toBe('positive-int')
            ;
        });

        test('prioritizes @psalm-var over @var when @phpstan-var is absent', function () {
            $doc = <<<'DOC'
/**
 * @var mixed $item
 * @psalm-var non-empty-string $item
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $varTags = DocblockExtractor::getVarTags($node);

            expect($varTags)->toHaveCount(1)
                ->and((string) $varTags[0]->type)->toBe('non-empty-string')
            ;
        });
    });

    describe('@self-out and @this-out Extractions & Priority', function () {
        test('extracts basic @self-out tag', function () {
            $doc = "/** @self-out self<'authenticated'> */";
            $node = DocblockExtractor::parseDocString($doc);
            $type = DocblockExtractor::getSelfOutTag($node);

            expect($type)->not()->toBeNull()
                ->and((string) $type)->toContain("'authenticated'")
            ;
        });

        test('extracts basic @this-out tag', function () {
            $doc = "/** @this-out self<'ready'> */";
            $node = DocblockExtractor::parseDocString($doc);
            $type = DocblockExtractor::getSelfOutTag($node);

            expect($type)->not()->toBeNull()
                ->and((string) $type)->toContain("'ready'")
            ;
        });

        test('prioritizes @phpstan-self-out over @psalm-self-out and @self-out', function () {
            $doc = <<<'DOC'
/**
 * @self-out self<'standard_state'>
 * @psalm-self-out self<'psalm_state'>
 * @phpstan-self-out self<'phpstan_state'>
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $type = DocblockExtractor::getSelfOutTag($node);

            expect($type)->not()->toBeNull()
                ->and((string) $type)->toContain("'phpstan_state'")
            ;
        });

        test('prioritizes @psalm-self-out over @self-out when @phpstan-self-out is absent', function () {
            $doc = <<<'DOC'
/**
 * @self-out self<'standard_state'>
 * @psalm-self-out self<'psalm_state'>
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $type = DocblockExtractor::getSelfOutTag($node);

            expect($type)->not()->toBeNull()
                ->and((string) $type)->toContain("'psalm_state'")
            ;
        });

        test('getSelfOutTag returns null when no self-out or this-out tag is present', function () {
            $doc = '/** @param int $val */';
            $node = DocblockExtractor::parseDocString($doc);

            expect(DocblockExtractor::getSelfOutTag($node))->toBeNull();
        });
    });

    describe('@template Priority and Variance Extractions', function () {
        test('prioritizes @phpstan-template with bound over basic @template', function () {
            $doc = <<<'DOC'
/**
 * @template T
 * @phpstan-template T of \TypePHP\Tests\Fixtures\Domain\Animal
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $templates = DocblockExtractor::extractTemplates($node);

            expect($templates)->toHaveKey('T')
                ->and($templates['T']->bound)->not()->toBeNull()
                ->and((string) $templates['T']->bound)->toBe('\TypePHP\Tests\Fixtures\Domain\Animal')
            ;
        });

        test('extracts declared template variances with @phpstan-template-covariant priority', function () {
            $doc = <<<'DOC'
/**
 * @template T
 * @phpstan-template-covariant T
 * @psalm-template-contravariant K
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $variances = DocblockExtractor::extractTemplateVariances($node);

            expect($variances)->toBe([
                'T' => 'covariant',
                'K' => 'contravariant',
            ]);
        });

        test('extracts all inherited template tag variations via getInheritedTags', function () {
            $doc = <<<'DOC'
/**
 * @template-extends BaseRepository<User>
 * @phpstan-implements ProcessorInterface<string>
 * @use LoggerTrait<int>
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $inherited = DocblockExtractor::getInheritedTags($node);

            expect($inherited)->toHaveCount(3);
        });

        test('resolves imported type aliases defined inside stub files', function () {
            $tempDir = sys_get_temp_dir() . '/typephp_doc_stub_' . uniqid();
            mkdir($tempDir, 0777, true);

            $stubPath = $tempDir . '/HelperService.stub';
            $stubContent = <<<'PHP'
<?php

namespace TypePHP\Tests\Fixtures\Services;

/**
 * @phpstan-type StubbedUserShape array{id: positive-int, username: non-empty-string}
 */
class HelperService
{
}
PHP;
            file_put_contents($stubPath, $stubContent);

            try {
                Config::set([
                    'stubs' => [
                        str_replace('\\', '/', $tempDir) . '/**',
                    ],
                ]);

                $resolved = DocblockExtractor::resolveImportedTypeAlias(HelperService::class, 'StubbedUserShape');

                expect($resolved)->not()->toBeNull()
                    ->and((string) $resolved)->toContain('positive-int')
                    ->and((string) $resolved)->toContain('non-empty-string')
                ;
            } finally {
                if (file_exists($stubPath)) {
                    @unlink($stubPath);
                }
                if (is_dir($tempDir)) {
                    @rmdir($tempDir);
                }
                Config::reset();
            }
        });

        test('preserves multiple variable @var tags when mixed with @phpstan-var and @psalm-var', function () {
            $doc = <<<'DOC'
/**
 * @phpstan-var positive-int $id
 * @var non-empty-string $username
 * @psalm-var 'admin'|'user' $role
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $varTags = DocblockExtractor::getVarTags($node);

            expect($varTags)->toHaveCount(3);

            $tagsByName = [];
            foreach ($varTags as $tag) {
                $tagsByName[ltrim($tag->variableName, '$')] = (string) $tag->type;
            }

            expect($tagsByName)->toHaveKey('id')
                ->and($tagsByName['id'])->toBe('positive-int')
                ->and($tagsByName)->toHaveKey('username')
                ->and($tagsByName['username'])->toBe('non-empty-string')
                ->and($tagsByName)->toHaveKey('role')
                ->and($tagsByName['role'])->toBe("('admin' | 'user')")
            ;
        });
    });

    describe('@param-out Tag Extractions (@param-out, @phpstan-param-out, @psalm-param-out)', function () {
        test('extracts basic @param-out tag', function () {
            $doc = '/** @param-out positive-int $id */';
            $node = DocblockExtractor::parseDocString($doc);
            $tags = DocblockExtractor::getParamOutTags($node);

            expect($tags)->toHaveKey('id')
                ->and((string) $tags['id']->type)->toBe('positive-int')
            ;
        });

        test('prioritizes @phpstan-param-out over @psalm-param-out and @param-out', function () {
            $doc = <<<'DOC'
/**
 * @param-out mixed $id
 * @psalm-param-out int $id
 * @phpstan-param-out positive-int $id
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $tags = DocblockExtractor::getParamOutTags($node);

            expect($tags)->toHaveKey('id')
                ->and((string) $tags['id']->type)->toBe('positive-int')
            ;
        });

        test('prioritizes @psalm-param-out over @param-out when @phpstan-param-out is absent', function () {
            $doc = <<<'DOC'
/**
 * @param-out mixed $id
 * @psalm-param-out non-empty-string $id
 */
DOC;
            $node = DocblockExtractor::parseDocString($doc);
            $tags = DocblockExtractor::getParamOutTags($node);

            expect($tags)->toHaveKey('id')
                ->and((string) $tags['id']->type)->toBe('non-empty-string')
            ;
        });
    });
});
