<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\DocblockNormalizer;
use TypePHP\Resolver\SpecialTypeResolver;

/**
 * @internal Encapsulates PHPDoc AST parsing, tokenizing, and tag extractions.
 */
final class DocblockExtractor
{
    /**
     * Returns shared static instances of PHPStan's PhpDocParser and Lexer.
     *
     * @return array{PhpDocParser, Lexer}
     */
    public static function getParserComponents(): array
    {
        /** @var PhpDocParser|null $phpDocParser */
        static $phpDocParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($phpDocParser === null || $lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        return [$phpDocParser, $lexer];
    }

    /**
     * Normalizes and parses a PHPDoc doccomment string into an AST PhpDocNode.
     */
    public static function parseDocString(string $doc): PhpDocNode
    {
        $doc = DocblockNormalizer::normalize($doc);
        [$phpDocParser, $lexer] = self::getParserComponents();
        $tokens = new TokenIterator($lexer->tokenize($doc));

        return $phpDocParser->parse($tokens);
    }

    /**
     * Extracts all @template tag values from a parsed PHPDoc node.
     *
     * @return array<string, TemplateTagValueNode>
     */
    public static function extractTemplates(PhpDocNode $node): array
    {
        $tags = [];
        foreach ($node->getTags() as $tagNode) {
            if ($tagNode->value instanceof TemplateTagValueNode) {
                $tags[$tagNode->value->name] = $tagNode->value;
            }
        }

        return $tags;
    }

    /**
     * Extracts a TypeNode from a property's @var or @param docblock.
     */
    public static function extractTypeFromPropertyDoc(string $doc, string $propName): ?TypeNode
    {
        try {
            $phpDocNode = self::parseDocString($doc);

            foreach ($phpDocNode->getVarTagValues() as $varTag) {
                $tagVarName = ltrim($varTag->variableName, '$');
                if ($tagVarName === '' || $tagVarName === $propName) {
                    return $varTag->type;
                }
            }

            foreach ($phpDocNode->getParamTagValues() as $paramTag) {
                $tagParamName = ltrim($paramTag->parameterName, '$');
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
            $varTags = $phpDocNode->getVarTagValues();
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

        // Expand nested/imported alias references inside extracted local aliases
        foreach ($aliases as $name => $type) {
            $aliases[$name] = ContractParser::substituteAliases($type, $aliases);
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
            $ref = new \ReflectionClass($fqcn);
            $doc = $ref->getDocComment();

            if ($doc !== false) {
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