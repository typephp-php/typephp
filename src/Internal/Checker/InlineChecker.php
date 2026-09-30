<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Internal\Docblock\DocblockNormalizer;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

/**
 * Evaluates inline variable (@var) and class property validation rules.
 *
 * @internal
 */
final class InlineChecker
{
    /**
     * In-memory cache for tokenized and parsed TypeNode ASTs and their context necessity flag.
     *
     * @var array<string, array{0: TypeNode, 1: bool}>
     */
    private static array $parsedTypeNodeCache = [];

    /**
     * In-memory 3D cache for resolved class contexts with static bounds: [$className][$methodName][$typeString] => TypeNode.
     *
     * @var array<string, array<string, array<string, TypeNode>>>
     */
    private static array $resolvedClassContextCache = [];

    /**
     * In-memory 2D cache for properties known to have no DocBlock annotations: [$className][$propName] => true.
     *
     * @var array<string, array<string, true>>
     */
    public static array $nullPropertyCache = [];

    /**
     * Cache for whether a class property's type references generic templates:
     * [$className][$propName] => bool.
     *
     * @var array<string, array<string, bool>>
     */
    private static array $propertyUsesTemplatesCache = [];

    /**
     * Cache for whether a class has any methods declaring @self-out or @this-out:
     * [$className] => bool.
     *
     * @var array<string, bool>
     */
    private static array $classHasSelfOutCache = [];

    /**
     * Resets internal type node and function caches. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$parsedTypeNodeCache = [];
        self::$resolvedClassContextCache = [];
        self::$nullPropertyCache = [];
        self::$propertyUsesTemplatesCache = [];
        self::$classHasSelfOutCache = [];
    }

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
    public static function checkVariable(
        mixed $value,
        string $typeString,
        string $varName,
        string $file,
        TypeValidatorRegistry $registry,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): mixed {
        if (! Config::hasActiveInlineChecks()) {
            return $value;
        }

        try {
            $normalized = DocblockNormalizer::normalize($typeString);
            [$typeNode, $needsContext] = self::parseTypeString($normalized);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            if ($needsContext && $caller !== null) {
                $typeNode = self::resolveCallerContext($typeNode, $caller, $thisOrClass);
            }

            if (! self::shouldValidateType($typeNode)) {
                return $value;
            }

            if ($value === [] && $varName !== 'return' && self::isArrayShapeType($typeNode)) {
                return $value;
            }

            $context = ($varName === 'return') ? 'Return value' : "Variable \$$varName";

            if ($typeNode instanceof GenericTypeNode) {
                $baseName = strtolower($typeNode->type->name);
                $isCollection = isset(self::ARRAY_TYPES[$baseName]);

                if (! $isCollection) {
                    if (! Config::isInlineGenericsEnabled() && Config::isInlineObjectsEnabled()) {
                        $typeNode = $typeNode->type;
                    } elseif (! Config::isInlineGenericsEnabled() && ! Config::isInlineObjectsEnabled()) {
                        return $value;
                    }
                }
            }

            if ($typeNode instanceof GenericTypeNode && Config::isInlineGenericsEnabled() && \is_object($value)) {
                $err = TemplateManager::bindInstanceFromNode($value, $typeNode, $context);
                if ($err !== null) {
                    return $err;
                }
            }

            $err = $registry->validate($value, $typeNode, $context);
            if ($err !== null) {
                return $err;
            }

            if ($typeNode instanceof CallableTypeNode || ($typeNode instanceof IdentifierTypeNode && strtolower($typeNode->name) === 'callable')) {
                $cbPrefix = ($varName === 'return') ? 'Return value: Callback' : "Variable \$$varName: Callback";

                return CallableWrapper::wrapTypeNode($typeNode, $value, $cbPrefix, $registry);
            }

            if ($typeNode instanceof ArrayShapeNode && \is_array($value)) {
                $value = self::wrapShapeCallables($typeNode, $value, $context, $registry);
            } elseif (\is_array($value)) {
                $value = self::wrapCollectionCallables($typeNode, $value, $context, $registry);
            }
        } catch (\Throwable $e) {
            // Silently ignore unexpected execution exceptions
        }

        return $value;
    }

    /**
     * Recursively wraps callable items nested inside array shapes.
     *
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function wrapShapeCallables(ArrayShapeNode $shapeNode, array $value, string $context, TypeValidatorRegistry $registry): array
    {
        foreach ($shapeNode->items as $item) {
            $key = null;
            if ($item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode) {
                $key = $item->keyName->value;
            } elseif ($item->keyName instanceof IdentifierTypeNode) {
                $key = $item->keyName->name;
            } elseif ($item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode) {
                $key = (int) $item->keyName->value;
            } elseif ($item->keyName !== null) {
                $key = (string) $item->keyName;
            }

            if ($key !== null && \array_key_exists($key, $value)) {
                if ($item->valueType instanceof CallableTypeNode) {
                    $cbPrefix = $context . "['" . $key . "']: Callback";
                    $value[$key] = CallableWrapper::wrapTypeNode($item->valueType, $value[$key], $cbPrefix, $registry);
                } elseif ($item->valueType instanceof ArrayShapeNode && \is_array($value[$key])) {
                    $value[$key] = self::wrapShapeCallables($item->valueType, $value[$key], $context . "['" . $key . "']", $registry);
                }
            }
        }

        return $value;
    }

    /**
     * Wraps callable elements in generic lists or typed arrays (e.g. list<callable(int): string>).
     *
     * @param array<int|string, mixed> $value
     *
     * @return array<int|string, mixed>
     */
    private static function wrapCollectionCallables(TypeNode $typeNode, array $value, string $context, TypeValidatorRegistry $registry): array
    {
        $innerCallable = null;
        if ($typeNode instanceof GenericTypeNode && \in_array(strtolower($typeNode->type->name), ['list', 'array', 'iterable'], true)) {
            $innerCallable = $typeNode->genericTypes[1] ?? $typeNode->genericTypes[0] ?? null;
        } elseif ($typeNode instanceof ArrayTypeNode) {
            $innerCallable = $typeNode->type;
        }

        if ($innerCallable instanceof CallableTypeNode) {
            foreach ($value as $k => $item) {
                if (CallableWrapper::isCallable($item)) {
                    $cbPrefix = $context . "[$k]: Callback";
                    $value[$k] = CallableWrapper::wrapTypeNode($innerCallable, $item, $cbPrefix, $registry);
                }
            }
        }

        return $value;
    }

    /**
     * Checks if a TypeNode is or contains an ArrayShapeNode.
     */
    private static function isArrayShapeType(TypeNode $node): bool
    {
        if ($node instanceof ArrayShapeNode) {
            return true;
        }

        if ($node instanceof NullableTypeNode) {
            return self::isArrayShapeType($node->type);
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $subType) {
                if (self::isArrayShapeType($subType)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Evaluates class property validation dynamically based on configuration with zero-allocation 2D caching.
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file, TypeValidatorRegistry $registry): mixed
    {
        if (! \is_object($objectOrClass) && ! \is_string($objectOrClass)) {
            return $value;
        }

        $className = \is_string($objectOrClass) ? $objectOrClass : $objectOrClass::class;

        if (isset(self::$nullPropertyCache[$className][$propName])) {
            return $value;
        }

        if (! Config::isInlinePropertiesEnabled()) {
            return $value;
        }

        $rawTypeNode = DocblockParser::parseProperty($className, $propName);
        if ($rawTypeNode === null) {
            self::$nullPropertyCache[$className][$propName] = true;

            return $value;
        }

        if (! self::shouldValidateType($rawTypeNode)) {
            return $value;
        }

        $propertyUsesTemplates = false;
        $typeNode = $rawTypeNode;

        if (\is_object($objectOrClass)) {
            $propertyUsesTemplates = self::propertyUsesTemplates($rawTypeNode, $className, $propName);
            if ($propertyUsesTemplates) {
                $typeNode = self::substitutePropertyGenerics($rawTypeNode, $objectOrClass, $className);
            }
        }

        try {
            $err = $registry->validate($value, $typeNode, 'Property ' . $className . '::$' . $propName);
            if ($err !== null) {
                if (
                    $propertyUsesTemplates &&
                    \is_object($objectOrClass) &&
                    self::classHasSelfOut($className) &&
                    self::trySelfOutTransition($value, $objectOrClass, $propName, $registry)
                ) {
                    return $value;
                }

                return $err;
            }
        } catch (\Throwable $e) {
            // Silently ignore unexpected execution exceptions
        }

        return $value;
    }

    /**
     * Checks if a property's declared type references class generic templates with memoization.
     */
    private static function propertyUsesTemplates(TypeNode $rawTypeNode, string $className, string $propName): bool
    {
        if (isset(self::$propertyUsesTemplatesCache[$className][$propName])) {
            return self::$propertyUsesTemplatesCache[$className][$propName];
        }

        $constructorTarget = $className . '::__construct';
        $contract = DocblockParser::parse($constructorTarget);
        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];

        if ($allTemplates === []) {
            return self::$propertyUsesTemplatesCache[$className][$propName] = false;
        }

        return self::$propertyUsesTemplatesCache[$className][$propName] = DocblockParser::typeReferencesTemplate($rawTypeNode, $allTemplates);
    }

    /**
     * Checks if a class declares any methods containing @self-out or @this-out annotations with memoization.
     */
    private static function classHasSelfOut(string $className): bool
    {
        if (isset(self::$classHasSelfOutCache[$className])) {
            return self::$classHasSelfOutCache[$className];
        }

        if (! class_exists($className) && ! trait_exists($className) && ! interface_exists($className)) {
            return self::$classHasSelfOutCache[$className] = false;
        }

        try {
            /** @var class-string<object> $className */
            $ref = new \ReflectionClass($className);
            foreach ($ref->getMethods() as $method) {
                $doc = $method->getDocComment();
                if ($doc !== false && (str_contains($doc, 'self-out') || str_contains($doc, 'this-out'))) {
                    return self::$classHasSelfOutCache[$className] = true;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection errors
        }

        return self::$classHasSelfOutCache[$className] = false;
    }

    /**
     * Attempts a typestate transition when a property assignment to $this fails against current bindings.
     */
    private static function trySelfOutTransition(mixed $value, object $object, string $propName, TypeValidatorRegistry $registry): bool
    {
        if (! Config::isSelfOutEnabled()) {
            return false;
        }

        $trace = debug_backtrace(DEBUG_BACKTRACE_PROVIDE_OBJECT, 7);
        $callerFrame = null;
        $callerFunction = null;
        $contract = null;

        foreach ($trace as $frame) {
            if (isset($frame['object'], $frame['class']) && $frame['object'] === $object) {
                if ($frame['class'] === 'TypePHP\Internal\RuntimeTypeChecker' || str_starts_with($frame['class'], 'TypePHP\\Internal\\')) {
                    continue;
                }

                $candidateFunction = $frame['class'] . '::' . $frame['function'];
                $candidateContract = DocblockParser::parse($candidateFunction);

                if (($candidateContract['hasSelfOutContract'] ?? false) && $candidateContract['selfOut'] !== null) {
                    $callerFrame = $frame;
                    $callerFunction = $candidateFunction;
                    $contract = $candidateContract;

                    break;
                }
            }
        }

        if ($callerFrame === null || $callerFunction === null || $contract === null) {
            return false;
        }

        $selfOutNode = $contract['selfOut'];
        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $boundTemplates = (\count($allTemplates) > 0)
            ? TemplateManager::getBoundTemplates($callerFunction, $object, $allTemplates)
            : [];

        if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
            $selfOutNode = TemplateSubstitutor::substitute($selfOutNode, $boundTemplates, $allTemplates);
            $selfOutNode = SpecialTypeResolver::resolve($selfOutNode, $callerFunction, $object);
        }

        $vars = $callerFrame['args'] ?? [];
        if (
            $selfOutNode instanceof ConditionalTypeForParameterNode ||
            $selfOutNode instanceof ConditionalTypeNode
        ) {
            $selfOutNode = ConditionalChecker::resolve($selfOutNode, $vars, $boundTemplates, $registry, $callerFunction);
        }

        if (! ($selfOutNode instanceof GenericTypeNode)) {
            return false;
        }

        $previousBindings = TemplateManager::getBoundTemplatesForInstance($object);

        TemplateManager::bindInstanceFromNode($object, $selfOutNode, forceBind: true);

        $className = $object::class;
        $propTypeNode = DocblockParser::parseProperty($className, $propName);
        if ($propTypeNode !== null) {
            $propTypeNode = self::substitutePropertyGenerics($propTypeNode, $object, $className);
        }

        $testErr = $propTypeNode !== null
            ? $registry->validate($value, $propTypeNode, 'Property ' . $className . '::$' . $propName)
            : null;

        if ($testErr === null) {
            return true;
        }

        TemplateManager::restoreInstanceBindings($object, $previousBindings);

        return false;
    }

    /**
     * Resolves caller class or function context and applies templates & type aliases to the AST.
     */
    private static function resolveCallerContext(TypeNode $typeNode, ?string $caller = null, mixed $thisOrClass = null): TypeNode
    {
        if ($caller !== null) {
            if ($caller === '') {
                return $typeNode;
            }

            if (str_contains($caller, '::')) {
                [$className, $methodName] = explode('::', $caller, 2);
                $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

                return self::resolveClassContext(
                    $typeNode,
                    $className,
                    $methodName,
                    $thisObj
                );
            }

            return self::resolveFunctionContext($typeNode, $caller);
        }

        return $typeNode;
    }

    /**
     * Resolves templates and aliases within standalone functions.
     */
    private static function resolveFunctionContext(TypeNode $typeNode, string $functionName): TypeNode
    {
        if (! \function_exists($functionName)) {
            return $typeNode;
        }

        try {
            $refFunc = new \ReflectionFunction($functionName);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $refFunc);

            $contract = DocblockParser::parse($functionName);
            $declaredTemplates = $contract['templates'] ?? [];
            $aliases = $contract['aliases'] ?? [];
            $boundTemplates = TemplateManager::getBoundTemplates($functionName, null, $declaredTemplates);

            $activeBindings = [...$aliases, ...$boundTemplates];

            if (\count($activeBindings) > 0 || \count($declaredTemplates) > 0) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $activeBindings, $declaredTemplates);
                $typeNode = SpecialTypeResolver::resolve($typeNode, $refFunc);
            }
        } catch (\ReflectionException $e) {
            // Silently continue if reflection fails
        }

        return $typeNode;
    }

    /**
     * Resolves templates, aliases, and class context within class methods using zero-allocation nested caching.
     */
    private static function resolveClassContext(
        TypeNode $typeNode,
        string $className,
        ?string $methodName,
        ?object $thisObj
    ): TypeNode {
        if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className)) {
            return $typeNode;
        }

        $targetFunc = ($methodName !== '{closure}' && $methodName !== null && ! str_starts_with($methodName, '{closure'))
            ? $className . '::' . $methodName
            : $className . '::__construct';

        $contract = DocblockParser::parse($targetFunc);
        $hasMethodTemplates = ($contract['templates'] ?? []) !== [];

        $methodKey = $methodName ?? '';
        $typeString = null;
        $canCache = ($thisObj === null && ! $hasMethodTemplates);

        if ($canCache) {
            $typeString = (string) $typeNode;
            if (isset(self::$resolvedClassContextCache[$className][$methodKey][$typeString])) {
                return self::$resolvedClassContextCache[$className][$methodKey][$typeString];
            }
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $refClass);

            $classAliases = DocblockParser::parseClassAliases($className);
            $allTemplates = $contract['allTemplates'] ?? [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
            $declaredTemplates = $allTemplates;

            if ($classAliases === [] && $declaredTemplates === []) {
                if ($canCache && $typeString !== null) {
                    return self::$resolvedClassContextCache[$className][$methodKey][$typeString] = $typeNode;
                }

                return $typeNode;
            }

            $boundTemplates = TemplateManager::getBoundTemplates($targetFunc, $thisObj, $declaredTemplates);
            $activeBindings = [...$classAliases, ...$boundTemplates];

            if ($activeBindings !== [] || $declaredTemplates !== []) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $activeBindings, $declaredTemplates);
                $typeNode = SpecialTypeResolver::resolve($typeNode, $refClass);
            }
        } catch (\ReflectionException $e) {
            // Silently continue if reflection fails
        }

        if ($canCache && $typeString !== null) {
            return self::$resolvedClassContextCache[$className][$methodKey][$typeString] = $typeNode;
        }

        return $typeNode;
    }

    /**
     * Substitutes generic template types declared on class properties.
     */
    private static function substitutePropertyGenerics(TypeNode $typeNode, object $object, string $className): TypeNode
    {
        $constructorTarget = $className . '::__construct';
        $contract = DocblockParser::parse($constructorTarget);

        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $boundTemplates = TemplateManager::getBoundTemplates('none', $object, $allTemplates);
        $declaredTemplates = $allTemplates;

        if (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $declaredTemplates);

            if (class_exists($className) || interface_exists($className) || trait_exists($className)) {
                try {
                    /** @var class-string<object> $className */
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
     * Inspects a TypeNode to check if it requires caller context resolution (templates, aliases, self/static/$this).
     */
    private static function needsContextResolution(TypeNode $node): bool
    {
        if ($node instanceof ThisTypeNode || $node instanceof OffsetAccessTypeNode || $node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return true;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            if (\in_array($lower, ['self', 'static', 'parent', '$this'], true)) {
                return true;
            }

            return ! SpecialTypeResolver::isBuiltInTypeKeyword($lower);
        }

        if ($node instanceof GenericTypeNode) {
            return true;
        }

        if ($node instanceof ConstTypeNode) {
            $constExpr = $node->constExpr;
            if ($constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode && $constExpr->className !== '') {
                return true;
            }

            return false;
        }

        if ($node instanceof NullableTypeNode || $node instanceof ArrayTypeNode) {
            return self::needsContextResolution($node->type);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $subType) {
                if (self::needsContextResolution($subType)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ArrayShapeNode) {
            foreach ($node->items as $item) {
                if (self::needsContextResolution($item->valueType)) {
                    return true;
                }
            }

            if ($node->unsealedType !== null) {
                if ($node->unsealedType->keyType !== null && self::needsContextResolution($node->unsealedType->keyType)) {
                    return true;
                }

                return self::needsContextResolution($node->unsealedType->valueType);
            }

            return false;
        }

        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                if (self::needsContextResolution($item->valueType)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof CallableTypeNode) {
            foreach ($node->parameters as $p) {
                if (self::needsContextResolution($p->type)) {
                    return true;
                }
            }

            return self::needsContextResolution($node->returnType);
        }

        return false;
    }

    /**
     * Parses and caches a type string into a TypeNode AST along with its context requirement flag.
     *
     * @return array{0: TypeNode, 1: bool}
     */
    private static function parseTypeString(string $typeString): array
    {
        if (isset(self::$parsedTypeNodeCache[$typeString])) {
            return self::$parsedTypeNodeCache[$typeString];
        }

        [$typeParser, $lexer] = self::getTypeParserComponents();
        $tokens = new TokenIterator($lexer->tokenize($typeString));
        $typeNode = $typeParser->parse($tokens);
        $needsContext = self::needsContextResolution($typeNode);

        return self::$parsedTypeNodeCache[$typeString] = [$typeNode, $needsContext];
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

    private static function shouldValidateType(TypeNode $node): bool
    {
        $checkArrays = Config::isInlineArraysEnabled();

        if ($node instanceof CallableTypeNode) {
            return Config::isInlineCallablesEnabled();
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
                return Config::isInlineCallablesEnabled();
            }

            if (isset(self::ARRAY_TYPES[$lower])) {
                return $checkArrays;
            }

            if (isset(self::SCALAR_TYPES[$lower])) {
                return Config::isInlineScalarsEnabled();
            }

            return Config::isInlineObjectsEnabled();
        }

        if ($node instanceof GenericTypeNode) {
            $lower = strtolower($node->type->name);
            if (isset(self::ARRAY_TYPES[$lower])) {
                return $checkArrays;
            }

            if (Config::isInlineGenericsEnabled()) {
                return true;
            }

            return Config::isInlineObjectsEnabled();
        }

        if ($node instanceof NullableTypeNode) {
            return self::shouldValidateType($node->type);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::shouldValidateType($t)) {
                    return true;
                }
            }

            return false;
        }

        return Config::isInlineScalarsEnabled();
    }
}
