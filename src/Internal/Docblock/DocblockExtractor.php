<?php

declare(strict_types=1);

namespace TypePHP\Internal\Docblock;

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\ReturnTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\VarTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\StubManager;

/**
 * @internal Encapsulates PHPDoc AST parsing, tokenizing, and tag extractions.
 */
final class DocblockExtractor
{
    /**
     * In-memory cache for normalized and parsed PhpDocNode ASTs keyed by raw docblock string.
     *
     * @var array<string, PhpDocNode>
     */
    private static array $docParseCache = [];

    private static ?PhpDocParser $phpDocParser = null;

    private static ?TypeParser $typeParser = null;

    private static ?Lexer $lexer = null;

    /**
     * Resets the parsed docblock cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$docParseCache = [];
    }

    /**
     * Returns shared static instances of PHPStan's PhpDocParser and Lexer.
     *
     * @return array{PhpDocParser, Lexer}
     */
    public static function getParserComponents(): array
    {
        self::initParserComponents();

        /** @var PhpDocParser $docParser */
        $docParser = self::$phpDocParser;
        /** @var Lexer $lexer */
        $lexer = self::$lexer;

        return [$docParser, $lexer];
    }

    /**
     * Returns shared static instances of PHPStan's TypeParser and Lexer.
     *
     * @return array{TypeParser, Lexer}
     */
    public static function getTypeParserComponents(): array
    {
        self::initParserComponents();

        /** @var TypeParser $typeParser */
        $typeParser = self::$typeParser;
        /** @var Lexer $lexer */
        $lexer = self::$lexer;

        return [$typeParser, $lexer];
    }

    private static function initParserComponents(): void
    {
        if (self::$phpDocParser === null || self::$typeParser === null || self::$lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            self::$lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            self::$typeParser = new TypeParser($config, $constExprParser);
            self::$phpDocParser = new PhpDocParser($config, self::$typeParser, $constExprParser);
        }
    }

    /**
     * Normalizes and parses a PHPDoc doccomment string into an AST PhpDocNode with in-memory memoization.
     */
    public static function parseDocString(string $doc): PhpDocNode
    {
        if (isset(self::$docParseCache[$doc])) {
            return self::$docParseCache[$doc];
        }

        $normalized = DocblockNormalizer::normalize($doc);
        [$phpDocParser, $lexer] = self::getParserComponents();
        $tokens = new TokenIterator($lexer->tokenize($normalized));

        return self::$docParseCache[$doc] = $phpDocParser->parse($tokens);
    }

    /**
     * Extracts parameter tags with priority: @phpstan-param > @psalm-param > @param.
     *
     * @return array<string, ParamTagValueNode>
     */
    public static function getParamTags(PhpDocNode $node): array
    {
        $tags = [];

        foreach ($node->getParamTagValues('@param') as $tag) {
            $pName = ltrim($tag->parameterName, '$');
            $tags[$pName] = $tag;
        }

        foreach ($node->getParamTagValues('@psalm-param') as $tag) {
            $pName = ltrim($tag->parameterName, '$');
            $tags[$pName] = $tag;
        }

        foreach ($node->getParamTagValues('@phpstan-param') as $tag) {
            $pName = ltrim($tag->parameterName, '$');
            $tags[$pName] = $tag;
        }

        return $tags;
    }

    /**
     * Extracts return tag with priority: @phpstan-return > @psalm-return > @return.
     */
    public static function getReturnTag(PhpDocNode $node): ?ReturnTagValueNode
    {
        $phpstanReturns = $node->getReturnTagValues('@phpstan-return');
        if (\count($phpstanReturns) > 0) {
            return $phpstanReturns[0];
        }

        $psalmReturns = $node->getReturnTagValues('@psalm-return');
        if (\count($psalmReturns) > 0) {
            return $psalmReturns[0];
        }

        $returns = $node->getReturnTagValues('@return');
        if (\count($returns) > 0) {
            return $returns[0];
        }

        return null;
    }

    /**
     * Extracts @var tags with priority per variable name: @phpstan-var > @psalm-var > @var.
     *
     * @return array<VarTagValueNode>
     */
    public static function getVarTags(PhpDocNode $node): array
    {
        $named = [];

        foreach ($node->getVarTagValues('@var') as $tag) {
            $vName = ltrim($tag->variableName, '$');
            if ($vName !== '') {
                $named[$vName] = $tag;
            }
        }

        foreach ($node->getVarTagValues('@psalm-var') as $tag) {
            $vName = ltrim($tag->variableName, '$');
            if ($vName !== '') {
                $named[$vName] = $tag;
            }
        }

        foreach ($node->getVarTagValues('@phpstan-var') as $tag) {
            $vName = ltrim($tag->variableName, '$');
            if ($vName !== '') {
                $named[$vName] = $tag;
            }
        }

        $unnamed = [];
        $unnamedPhpstan = array_values(array_filter($node->getVarTagValues('@phpstan-var'), fn ($t) => $t->variableName === ''));
        $unnamedPsalm = array_values(array_filter($node->getVarTagValues('@psalm-var'), fn ($t) => $t->variableName === ''));
        $unnamedStandard = array_values(array_filter($node->getVarTagValues('@var'), fn ($t) => $t->variableName === ''));

        if (\count($unnamedPhpstan) > 0) {
            $unnamed = $unnamedPhpstan;
        } elseif (\count($unnamedPsalm) > 0) {
            $unnamed = $unnamedPsalm;
        } elseif (\count($unnamedStandard) > 0) {
            $unnamed = $unnamedStandard;
        }

        return array_values([...$named, ...$unnamed]);
    }

    /**
     * Extracts all @template tags with priority: @phpstan-template-* > @psalm-template-* > @template-*.
     *
     * @return array<string, TemplateTagValueNode>
     */
    public static function extractTemplates(PhpDocNode $node): array
    {
        $templates = [];

        $priorityGroups = [
            ['@template', '@template-covariant', '@template-contravariant'],
            ['@psalm-template', '@psalm-template-covariant', '@psalm-template-contravariant'],
            ['@phpstan-template', '@phpstan-template-covariant', '@phpstan-template-contravariant'],
        ];

        foreach ($priorityGroups as $tagNames) {
            foreach ($tagNames as $tagName) {
                foreach ($node->getTagsByName($tagName) as $tagNode) {
                    if ($tagNode->value instanceof TemplateTagValueNode) {
                        $templates[$tagNode->value->name] = $tagNode->value;
                    }
                }
            }
        }

        return $templates;
    }

    /**
     * Extracts declared template variances ('covariant', 'contravariant', or 'invariant') per template name.
     *
     * @return array<string, string>
     */
    public static function extractTemplateVariances(PhpDocNode $node): array
    {
        $variances = [];

        $priorityGroups = [
            ['@template', '@template-covariant', '@template-contravariant'],
            ['@psalm-template', '@psalm-template-covariant', '@psalm-template-contravariant'],
            ['@phpstan-template', '@phpstan-template-covariant', '@phpstan-template-contravariant'],
        ];

        foreach ($priorityGroups as $tagNames) {
            foreach ($tagNames as $tagName) {
                foreach ($node->getTagsByName($tagName) as $tagNode) {
                    if ($tagNode->value instanceof TemplateTagValueNode) {
                        $tName = $tagNode->value->name;
                        $lowerTag = strtolower($tagNode->name);

                        if (str_contains($lowerTag, 'covariant')) {
                            $variances[$tName] = 'covariant';
                        } elseif (str_contains($lowerTag, 'contravariant')) {
                            $variances[$tName] = 'contravariant';
                        } else {
                            $variances[$tName] = 'invariant';
                        }
                    }
                }
            }
        }

        return $variances;
    }

    /**
     * Extracts all inherited template type tags (@extends, @implements, @use and their @template-*, @phpstan-*, @psalm-* variants).
     *
     * @return array<int, \PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode|\PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode|\PHPStan\PhpDocParser\Ast\PhpDoc\UsesTagValueNode>
     */
    public static function getInheritedTags(PhpDocNode $node): array
    {
        $tags = [];
        $tagNames = [
            '@extends',
            '@template-extends',
            '@phpstan-extends',
            '@psalm-extends',
            '@implements',
            '@template-implements',
            '@phpstan-implements',
            '@psalm-implements',
            '@use',
            '@template-use',
            '@phpstan-use',
        ];

        foreach ($tagNames as $name) {
            foreach ($node->getTagsByName($name) as $tagNode) {
                if (
                    $tagNode->value instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\ExtendsTagValueNode ||
                    $tagNode->value instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\ImplementsTagValueNode ||
                    $tagNode->value instanceof \PHPStan\PhpDocParser\Ast\PhpDoc\UsesTagValueNode
                ) {
                    $tags[] = $tagNode->value;
                }
            }
        }

        return $tags;
    }

    /**
     * Extracts a TypeNode from a property's @var, @param, @phpstan-param, or @psalm-param docblock.
     */
    public static function extractTypeFromPropertyDoc(string $doc, string $propName): ?TypeNode
    {
        try {
            $phpDocNode = self::parseDocString($doc);

            foreach (self::getVarTags($phpDocNode) as $varTag) {
                $tagVarName = ltrim($varTag->variableName, '$');
                if ($tagVarName === '' || $tagVarName === $propName) {
                    return $varTag->type;
                }
            }

            foreach (self::getParamTags($phpDocNode) as $tagParamName => $paramTag) {
                if ($tagParamName === '' || $tagParamName === $propName) {
                    return $paramTag->type;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed property docblocks
        }

        return null;
    }

    /**
     * Extracts the first @var tag's type string and variable name from a docblock string.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function extractVarTagFromDoc(string $doc): ?array
    {
        try {
            $phpDocNode = self::parseDocString($doc);
            $varTags = self::getVarTags($phpDocNode);
            if (\count($varTags) > 0) {
                $typeString = (string) $varTags[0]->type;
                $varName = ltrim($varTags[0]->variableName, '$');

                return [$typeString, $varName];
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblocks
        }

        return null;
    }

    /**
     * Extracts local and imported type aliases (@phpstan-type and @phpstan-import-type) from a PHPDoc node.
     *
     * @param array<string, TypeNode> $aliases
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod $ref
     */
    public static function extractAliases(
        PhpDocNode $phpDocNode,
        array &$aliases,
        \ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref
    ): void {
        foreach ($phpDocNode->getTypeAliasTagValues() as $aliasTag) {
            if (! isset($aliases[$aliasTag->alias])) {
                $aliases[$aliasTag->alias] = $aliasTag->type;
            }
        }

        foreach ($phpDocNode->getTypeAliasImportTagValues() as $importTag) {
            $localName = $importTag->importedAs ?? $importTag->importedAlias;
            if (! isset($aliases[$localName])) {
                $fqcnSource = SpecialTypeResolver::resolveFqcn($importTag->importedFrom->name, $ref);
                $resolvedType = self::resolveImportedTypeAlias($fqcnSource, $importTag->importedAlias);
                if ($resolvedType !== null) {
                    $aliases[$localName] = $resolvedType;
                }
            }
        }

        foreach ($aliases as $name => $type) {
            $aliases[$name] = DocblockParser::substituteAliases($type, $aliases);
        }
    }

    /**
     * Resolves an imported type alias (@phpstan-import-type) from a target class, interface, trait, or enum.
     */
    public static function resolveImportedTypeAlias(string $fqcn, string $importedAlias): ?TypeNode
    {
        if (! ClassNameValidator::isValid($fqcn) || (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn))) {
            return null;
        }

        try {
            $stubDoc = StubManager::getClassDoc($fqcn);
            $ref = new \ReflectionClass($fqcn);
            $doc = $stubDoc ?? $ref->getDocComment();

            if ($doc !== false && $doc !== null) {
                $phpDocNode = self::parseDocString($doc);

                $targetAliases = [];
                self::extractAliases($phpDocNode, $targetAliases, $ref);

                if (isset($targetAliases[$importedAlias])) {
                    return $targetAliases[$importedAlias];
                }

                foreach ($phpDocNode->getTypeAliasImportTagValues() as $importTag) {
                    $localName = $importTag->importedAs ?? $importTag->importedAlias;
                    if ($localName === $importedAlias) {
                        $nextFqcn = SpecialTypeResolver::resolveFqcn($importTag->importedFrom->name, $ref);

                        return self::resolveImportedTypeAlias($nextFqcn, $importTag->importedAlias);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore unresolvable types
        }

        return null;
    }

    /**
     * Extracts a TypeNode from a class-level @property, @property-read, or @property-write docblock.
     */
    public static function extractTypeFromClassPropertyDoc(string $doc, string $propName): ?TypeNode
    {
        try {
            $phpDocNode = self::parseDocString($doc);

            foreach ($phpDocNode->getPropertyTagValues() as $tag) {
                if (ltrim($tag->propertyName, '$') === $propName) {
                    return $tag->type;
                }
            }

            foreach ($phpDocNode->getPropertyWriteTagValues() as $tag) {
                if (ltrim($tag->propertyName, '$') === $propName) {
                    return $tag->type;
                }
            }

            foreach ($phpDocNode->getPropertyReadTagValues() as $tag) {
                if (ltrim($tag->propertyName, '$') === $propName) {
                    return $tag->type;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed class docblocks
        }

        return null;
    }

    /**
     * Extracts a MethodTagValueNode from a class-level @method docblock.
     */
    public static function extractMagicMethodContract(string $doc, string $methodName): ?MethodTagValueNode
    {
        try {
            $phpDocNode = self::parseDocString($doc);
            foreach ($phpDocNode->getMethodTagValues() as $tag) {
                if ($tag->methodName === $methodName) {
                    return $tag;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed class docblocks
        }

        return null;
    }
}
