<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\Config;
use TypePHP\Internal\DocblockNormalizer;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;

/**
 * @internal Evaluates inline variable (@var) and class property validation rules.
 */
final class InlineChecker
{
    /**
     * In-memory cache for tokenized and parsed TypeNode ASTs keyed by normalized type string.
     *
     * @var array<string, TypeNode>
     */
    private static array $parsedTypeNodeCache = [];

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file, TypeValidatorRegistry $registry): mixed
    {
        $rawConfig = Config::get()['inline_vars'] ?? [];
        /** @var array<string, bool> $config */
        $config = \is_array($rawConfig) ? $rawConfig : [];

        $checkGenerics = (bool) ($config['generics'] ?? true);
        $checkCallables = (bool) ($config['callables'] ?? true);
        $checkScalars = (bool) ($config['scalars'] ?? false);
        $checkArrays = (bool) ($config['arrays'] ?? false);
        $checkObjects = (bool) ($config['objects'] ?? false);

        if (! $checkGenerics && ! $checkCallables && ! $checkScalars && ! $checkArrays && ! $checkObjects) {
            return $value;
        }

        try {
            $typeString = DocblockNormalizer::normalize($typeString);
            $typeNode = self::parseTypeString($typeString);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            // Resolve local class-level aliases for the executing context by traversing back the call stack
            $className = null;
            $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
            foreach ($trace as $frame) {
                $classCandidate = $frame['class'] ?? null;
                if ($classCandidate !== null && ! str_starts_with($classCandidate, 'TypePHP\\Internal\\') && ! str_starts_with($classCandidate, 'TypePHP\\Wrapper\\')) {
                    $className = $classCandidate;

                    break;
                }
            }

            if ($className !== null) {
                $classAliases = ContractParser::parseClassAliases($className);
                if (\count($classAliases) > 0) {
                    $typeNode = TemplateSubstitutor::substitute($typeNode, $classAliases);
                }
            }

            if (! self::shouldValidateType($typeNode, $config)) {
                return $value;
            }

            if ($typeNode instanceof CallableTypeNode || ($typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'callable')) {
                return CallableWrapper::wrapTypeNode($typeNode, $value, "Variable \$$varName: Callback", $registry);
            }

            if ($typeNode instanceof GenericTypeNode && $checkGenerics && \is_object($value)) {
                $err = TemplateManager::bindInstanceFromNode($value, $typeNode, "Variable \$$varName", true);
                if ($err !== null) {
                    return $err;
                }
            }

            $err = $registry->validate($value, $typeNode, "Variable \$$varName");
            if ($err !== null) {
                return $err;
            }
        } catch (\Throwable $e) {
            // Silently ignore unexpected execution exceptions
        }

        return $value;
    }

    /**
     * Evaluates class property validation dynamically based on configuration.
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file, TypeValidatorRegistry $registry): mixed
    {
        if (! \is_object($objectOrClass) && ! \is_string($objectOrClass)) {
            return $value;
        }

        $rawConfig = Config::get()['inline_vars'] ?? [];
        /** @var array<string, bool> $config */
        $config = \is_array($rawConfig) ? $rawConfig : [];

        if (! ($config['properties'] ?? true)) {
            return $value;
        }

        $className = \is_string($objectOrClass) ? $objectOrClass : \get_class($objectOrClass);

        $typeNode = ContractParser::parseProperty($className, $propName);
        if ($typeNode === null) {
            return $value;
        }

        if (! self::shouldValidateType($typeNode, $config)) {
            return $value;
        }

        // Substitute class-level property generics for object instances
        if (\is_object($objectOrClass)) {
            $constructorTarget = $className . '::__construct';
            $contract = ContractParser::parse($constructorTarget);

            $boundTemplates = TemplateManager::getBoundTemplates('none', $objectOrClass, $contract['templates']);
            $declaredTemplates = $contract['templates'];

            if (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $declaredTemplates);

                if (class_exists($className) || interface_exists($className) || trait_exists($className)) {
                    try {
                        $refClass = new \ReflectionClass($className);
                        $typeNode = SpecialTypeResolver::resolve($typeNode, $refClass);
                    } catch (\ReflectionException $e) {
                        // Silently continue if reflection fails
                    }
                }
            }
        }

        try {
            $err = $registry->validate($value, $typeNode, 'Property ' . $className . '::$' . $propName);
            if ($err !== null) {
                return $err;
            }
        } catch (\Throwable $e) {
            // Silently ignore unexpected execution exceptions
        }

        return $value;
    }

    /**
     * Parses and caches a type string into a TypeNode AST.
     */
    private static function parseTypeString(string $typeString): TypeNode
    {
        if (isset(self::$parsedTypeNodeCache[$typeString])) {
            return self::$parsedTypeNodeCache[$typeString];
        }

        [$typeParser, $lexer] = self::getTypeParserComponents();
        $tokens = new TokenIterator($lexer->tokenize($typeString));

        return self::$parsedTypeNodeCache[$typeString] = $typeParser->parse($tokens);
    }

    /**
     * @return array{TypeParser, Lexer}
     */
    private static function getTypeParserComponents(): array
    {
        /** @var TypeParser|null $typeParser */
        static $typeParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($typeParser === null || $lexer === null) {
            $configParser = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($configParser);
            $constExprParser = new ConstExprParser($configParser);
            $typeParser = new TypeParser($configParser, $constExprParser);
        }

        return [$typeParser, $lexer];
    }

    /**
     * @param array<string, bool> $config
     */
    private static function shouldValidateType(TypeNode $node, array $config): bool
    {
        $checkArrays = (bool) ($config['arrays'] ?? false);

        if ($node instanceof CallableTypeNode) {
            return (bool) ($config['callables'] ?? true);
        }

        if ($node instanceof ObjectShapeNode || $node instanceof ArrayShapeNode || $node instanceof ArrayTypeNode) {
            return $checkArrays;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);

            if ($lower === 'mixed') {
                return false;
            }

            if ($lower === 'callable') {
                return (bool) ($config['callables'] ?? true);
            }

            if (\in_array($lower, ['array', 'list', 'iterable'], true)) {
                return $checkArrays;
            }

            if (\in_array($lower, [
                'int',
                'integer',
                'string',
                'bool',
                'boolean',
                'float',
                'double',
                'null',
                'true',
                'false',
                'scalar',
                'numeric',
                'positive-int',
                'negative-int',
                'non-empty-string',
                'numeric-string',
                'truthy',
                'falsy',
                'uppercase-string',
                'non-empty-uppercase-string',
                'array-key'
            ], true)) {
                return (bool) ($config['scalars'] ?? false);
            }

            return (bool) ($config['objects'] ?? false);
        }

        if ($node instanceof GenericTypeNode) {
            $lower = strtolower($node->type->name);
            if (\in_array($lower, ['array', 'list', 'iterable'], true)) {
                return $checkArrays;
            }

            if ((bool) ($config['generics'] ?? true)) {
                return true;
            }

            return (bool) ($config['objects'] ?? false);
        }

        if ($node instanceof NullableTypeNode) {
            return self::shouldValidateType($node->type, $config);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::shouldValidateType($t, $config)) {
                    return true;
                }
            }

            return false;
        }

        return (bool) ($config['scalars'] ?? false);
    }
}
