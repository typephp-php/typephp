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
     * Fast lookup set for scalar refinement types.
     */
    private const SCALAR_TYPES = [
        'int' => true,
        'integer' => true,
        'string' => true,
        'bool' => true,
        'boolean' => true,
        'float' => true,
        'double' => true,
        'null' => true,
        'true' => true,
        'false' => true,
        'scalar' => true,
        'numeric' => true,
        'positive-int' => true,
        'negative-int' => true,
        'non-positive-int' => true,
        'non-negative-int' => true,
        'non-zero-int' => true,
        'unsigned-int' => true,
        'positive-float' => true,
        'negative-float' => true,
        'non-positive-float' => true,
        'non-negative-float' => true,
        'non-zero-float' => true,
        'non-empty-string' => true,
        'numeric-string' => true,
        'lowercase-string' => true,
        'non-empty-lowercase-string' => true,
        'uppercase-string' => true,
        'non-empty-uppercase-string' => true,
        'truthy' => true,
        'falsy' => true,
        'array-key' => true,
    ];

    /**
     * Fast lookup set for iterable types.
     */
    private const ARRAY_TYPES = [
        'array' => true,
        'list' => true,
        'iterable' => true,
    ];

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file, TypeValidatorRegistry $registry): mixed
    {
        $rawConfig = Config::get()['inline_vars'] ?? [];
        /** @var array<string, bool> $config */
        $config = \is_array($rawConfig) ? $rawConfig : [];

        if (! self::hasActiveInlineChecks($config)) {
            return $value;
        }

        try {
            $typeString = DocblockNormalizer::normalize($typeString);
            $typeNode = self::parseTypeString($typeString);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            $typeNode = self::resolveCallerContext($typeNode);

            if (! self::shouldValidateType($typeNode, $config)) {
                return $value;
            }

            $context = ($varName === 'return') ? 'Return value' : "Variable \$$varName";

            if ($typeNode instanceof CallableTypeNode || ($typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'callable')) {
                $cbPrefix = ($varName === 'return') ? 'Return value: Callback' : "Variable \$$varName: Callback";

                return CallableWrapper::wrapTypeNode($typeNode, $value, $cbPrefix, $registry);
            }

            $checkGenerics = (bool) ($config['generics'] ?? true);
            if ($typeNode instanceof GenericTypeNode && $checkGenerics && \is_object($value)) {
                $err = TemplateManager::bindInstanceFromNode($value, $typeNode, $context, true);
                if ($err !== null) {
                    return $err;
                }
            }

            $err = $registry->validate($value, $typeNode, $context);
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

        if (\is_object($objectOrClass)) {
            $typeNode = self::substitutePropertyGenerics($typeNode, $objectOrClass, $className);
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
     * Checks if at least one inline variable category is active.
     *
     * @param array<string, bool> $config
     */
    private static function hasActiveInlineChecks(array $config): bool
    {
        return (bool) ($config['generics'] ?? true)
            || (bool) ($config['callables'] ?? true)
            || (bool) ($config['scalars'] ?? false)
            || (bool) ($config['arrays'] ?? false)
            || (bool) ($config['objects'] ?? false);
    }

    /**
     * Resolves caller class context and applies class-level type aliases to the AST.
     */
    private static function resolveCallerContext(TypeNode $typeNode): TypeNode
    {
        $className = null;
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 7);

        foreach ($trace as $frame) {
            $classCandidate = $frame['class'] ?? null;
            if ($classCandidate !== null && ! str_starts_with($classCandidate, 'TypePHP\\Internal\\') && ! str_starts_with($classCandidate, 'TypePHP\\Wrapper\\')) {
                $className = $classCandidate;

                break;
            }
        }

        if ($className === null || (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className))) {
            return $typeNode;
        }

        try {
            $refClass = new \ReflectionClass($className);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $refClass);

            $classAliases = ContractParser::parseClassAliases($className);
            if (\count($classAliases) > 0) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $classAliases);
            }
        } catch (\ReflectionException $e) {
            // Silently continue if reflection fails
        }

        return $typeNode;
    }

    /**
     * Substitutes generic template types declared on class properties.
     */
    private static function substitutePropertyGenerics(TypeNode $typeNode, object $object, string $className): TypeNode
    {
        $constructorTarget = $className . '::__construct';
        $contract = ContractParser::parse($constructorTarget);

        $boundTemplates = TemplateManager::getBoundTemplates('none', $object, $contract['templates']);
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

        return $typeNode;
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

            if (isset(self::ARRAY_TYPES[$lower])) {
                return $checkArrays;
            }

            if (isset(self::SCALAR_TYPES[$lower])) {
                return (bool) ($config['scalars'] ?? false);
            }

            return (bool) ($config['objects'] ?? false);
        }

        if ($node instanceof GenericTypeNode) {
            $lower = strtolower($node->type->name);
            if (isset(self::ARRAY_TYPES[$lower])) {
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
