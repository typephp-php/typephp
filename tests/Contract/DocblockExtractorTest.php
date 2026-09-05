<?php

declare(strict_types=1);

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Tests\Fixtures\Services\HelperService;
use TypePHP\Tests\Fixtures\Services\UserService;
use TypePHP\Tests\Fixtures\Shopware\Metric\Type as MetricTypeEnum;
use TypePHP\Tests\Fixtures\Types\NestedAliasChainedB;
use TypePHP\Tests\Fixtures\Types\NestedAliasService;
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

    test('resolves imported type aliases from Enums', function () {
        $resolvedNode = DocblockExtractor::resolveImportedTypeAlias(MetricTypeEnum::class, 'MetricTypeValues');

        expect($resolvedNode)->not()->toBeNull()
            ->and((string) $resolvedNode)->toContain('histogram')
        ;
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

        $phpDocNode = DocblockExtractor::parseDocString($doc);
        $aliases = [];

        DocblockExtractor::extractAliases($phpDocNode, $aliases, $ref);

        expect($aliases)->toHaveKey('LocalRecordList')
            ->and((string) $aliases['LocalRecordList'])->toContain('positive-int')
            ->and((string) $aliases['LocalRecordList'])->toContain('active')
        ;
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
            TypePHP\Internal\Config::set([
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
            TypePHP\Internal\Config::reset();
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
