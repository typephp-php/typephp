<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
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
use TypePHP\Internal\Ast\Elimination\Evaluator\GeneratorEvaluator;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StubManager;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @phpstan-import-type FunctionContract from DocblockParser
 *
 * @internal Evaluates function and method parameter contract validations (including dynamic @method calls via __call / __callStatic).
 */
final class ParamChecker
{
    /**
     * O(1) lookup table for iterable collection container types.
     */
    private const ITERABLE_TYPES = [
        'array' => true,
        'list' => true,
        'iterable' => true,
        'traversable' => true,
    ];

    /**
     * O(1) lookup table for iterable/generator object container types.
     */
    private const TRAVERSABLE_OBJECT_TYPES = [
        'iterable' => true,
        'traversable' => true,
        'generator' => true,
        'iterator' => true,
    ];

    /**
     * O(1) lookup table for all collection and slice container types.
     */
    private const COLLECTION_TYPES = [
        'array' => true,
        'list' => true,
        'iterable' => true,
        'traversable' => true,
        'non-empty-array' => true,
        'non-empty-list' => true,
    ];

    /**
     * 2D Cache for effective functions: [$function][$actualClassName] => effectiveFunction.
     *
     * @var array<string, array<string, string>>
     */
    private static array $effectiveFunctionCache = [];

    /**
     * O(1) Fast-path cache for methods determined to have no parameter contracts.
     *
     * @var array<string, true>
     */
    public static array $noParamContractCache = [];

    /**
     * 2D Cache for resolved parameter base types: [$effectiveFunction][$paramName] => TypeNode.
     *
     * @var array<string, array<string, TypeNode>>
     */
    private static array $baseTypeCache = [];

    /**
     * Cache for declared class template names by class FQCN.
     *
     * @var array<string, list<string>>
     */
    private static array $classDeclaredTemplateNamesCache = [];

    /**
     * Memoized reflection context for generic-object FQCN resolution. Null means "no reflectable context".
     *
     * @var array<string, \ReflectionClass<object>|\ReflectionFunction|null>
     */
    private static array $genericObjectContextCache = [];

    /**
     * 2D cache for resolved generic-object target FQCNs: [$contextKey][$typeName] => FQCN.
     *
     * @var array<string, array<string, string>>
     */
    private static array $genericObjectTargetCache = [];

    /**
     * Per-class traversable contract plan: alias-substituted key/value nodes, the class templates
     * they are expressed in, and the reflection context they resolve against. All class-derived;
     * only the instance bindings are applied per call.
     *
     * @var array<string, array{key: ?TypeNode, value: ?TypeNode, templates: array<string, TemplateTagValueNode>, context: \ReflectionClass<object>|\ReflectionMethod}|null>
     */
    private static array $traversablePlanCache = [];

    /**
     * In-memory cache for callable parameter nodes per function.
     *
     * @var array<string, array<string, CallableTypeNode>>
     */
    private static array $callableNodesCache = [];

    /**
     * 2D cache for whether a parameter's declared type is instance-dependent (static/$this).
     *
     * @var array<string, array<string, bool>>
     */
    private static array $paramDynamicCache = [];

    /**
     * Resets internal caches. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$effectiveFunctionCache = [];
        self::$noParamContractCache = [];
        self::$classDeclaredTemplateNamesCache = [];
        ClassNameValidator::reset();
        self::$baseTypeCache = [];
        self::$genericObjectContextCache = [];
        self::$genericObjectTargetCache = [];
        self::$traversablePlanCache = [];
        self::$callableNodesCache = [];
        self::$paramDynamicCache = [];
    }

    /**
     * Checks if all parameters of a function are unconstrained (mixed or array).
     * Uses the pre-computed flag from DocblockParser contract when available.
     */
    public static function areAllParamsUnconstrained(string $effectiveFunction): bool
    {
        if (str_contains($effectiveFunction, '__call') || str_ends_with($effectiveFunction, '::__get')) {
            return false;
        }

        $contract = DocblockParser::parse($effectiveFunction);

        return $contract['allParamsUnconstrained'] ?? false;
    }

    /**
     * Checks if a TypeNode represents an unconstrained type (mixed or array).
     */
    private static function isUnconstrained(TypeNode $typeNode): bool
    {
        if (! ($typeNode instanceof IdentifierTypeNode)) {
            return false;
        }
        $lower = strtolower($typeNode->name);

        return $lower === 'mixed' || $lower === 'array';
    }

    /**
     * @param array<string, mixed> $vars
     * @param FunctionContract|null $contract Pre-resolved contract to avoid re-parsing
     */
    public static function checkParams(
        string $function,
        array $vars,
        object|string|null $thisOrClass,
        TypeValidatorRegistry $registry,
        string $effectiveFunction = '',
        ?array $contract = null
    ): ?ErrorMessage {
        if (! Config::isParamsEnabled()) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

        if ($effectiveFunction === '') {
            $effectiveFunction = self::resolveEffectiveFunction($function, $thisOrClass, $thisObj);
        }

        $isMagicCall = str_ends_with($effectiveFunction, '::__call') || str_ends_with($effectiveFunction, '::__callStatic');
        $isMagicGet = str_ends_with($effectiveFunction, '::__get');

        if (! $isMagicCall && ! $isMagicGet && isset(self::$noParamContractCache[$effectiveFunction])) {
            return null;
        }

        if ($vars === [] && ! $isMagicCall && ! $isMagicGet) {
            return null;
        }

        $magicError = self::handleMagicCall($effectiveFunction, $vars, $thisObj, $registry);
        if ($magicError !== null) {
            return $magicError;
        }
        if ($isMagicCall || $isMagicGet) {
            return null;
        }

        $contract ??= DocblockParser::parse($effectiveFunction);

        if (! $contract['hasParamContract']) {
            self::$noParamContractCache[$effectiveFunction] = true;
            self::$noParamContractCache[$function] = true;

            return null;
        }

        $paramsUseGenerics = (bool) ($contract['paramsUseGenerics'] ?? true);
        $paramsAreDynamic = (bool) ($contract['paramsAreDynamic'] ?? false);
        $methodTemplates = $contract['templates'];
        $classTemplates = $contract['classTemplates'] ?? [];
        $aliases = $contract['aliases'];
        $hasMethodTemplates = (\count($methodTemplates) > 0);
        $sensitiveParams = $contract['sensitiveParams'] ?? [];

        if (! $paramsUseGenerics && ! $hasMethodTemplates && \count($aliases) === 0 && ! $paramsAreDynamic) {
            return self::validateSimpleParams($contract['types'], $vars, $effectiveFunction, $registry, $sensitiveParams);
        }

        self::prepareGenericBindings($effectiveFunction, $methodTemplates, $thisObj, $classTemplates, $thisOrClass);

        /** @var array<string, TemplateTagValueNode> $allTemplates */
        $allTemplates = [...$classTemplates, ...$methodTemplates];

        if (\count($allTemplates) > 0 && $paramsUseGenerics) {
            self::preInferGenericTemplates($contract['types'], $vars, $effectiveFunction, $thisObj, $allTemplates, $classTemplates);
        }

        $boundTemplates = (\count($allTemplates) > 0)
            ? TemplateManager::getBoundTemplates($effectiveFunction, $thisOrClass, $allTemplates)
            : [];
        $declaredTemplates = $allTemplates;

        $baseTypes = self::resolveBaseTypes($contract['types'], $effectiveFunction, $thisObj, $aliases, $paramsAreDynamic);

        return self::validateAllParameters(
            $contract['types'],
            $baseTypes,
            $vars,
            $effectiveFunction,
            $thisObj,
            $allTemplates,
            $aliases,
            $boundTemplates,
            $declaredTemplates,
            $registry,
            $classTemplates,
            $thisOrClass,
            $sensitiveParams
        );
    }

    private static function refineInferredTypeFromBound(mixed $val, TypeNode $bound, TypeNode $defaultInferred): TypeNode
    {
        if ($bound instanceof \PHPStan\PhpDocParser\Ast\Type\ConstTypeNode) {
            $expectedVal = match (true) {
                $bound->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode => $bound->constExpr->value,
                $bound->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode => (int) $bound->constExpr->value,
                $bound->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode => true,
                $bound->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode => false,
                $bound->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode => null,
                default => null,
            };

            if ($expectedVal !== null && $val === $expectedVal) {
                return $bound;
            }
        }

        if ($bound instanceof UnionTypeNode) {
            foreach ($bound->types as $member) {
                $refined = self::refineInferredTypeFromBound($val, $member, $defaultInferred);
                if ($refined !== $defaultInferred) {
                    return $refined;
                }
            }
        }

        return $defaultInferred;
    }

    /**
     * Validates simple parameters when no generics/aliases are involved.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, bool> $sensitiveParams
     */
    private static function validateSimpleParams(
        array $types,
        array $vars,
        string $effectiveFunction,
        TypeValidatorRegistry $registry,
        array $sensitiveParams = []
    ): ?ErrorMessage {
        foreach ($types as $paramName => $typeNode) {
            if (isset($vars[$paramName]) || \array_key_exists($paramName, $vars)) {
                if (! ($typeNode instanceof IdentifierTypeNode) && ConditionalChecker::containsConditional($typeNode)) {
                    $typeNode = ConditionalChecker::resolve($typeNode, $vars, [], $registry, $effectiveFunction);
                }

                if (self::isUnconstrained($typeNode)) {
                    continue;
                }

                $isSensitive = $sensitiveParams[$paramName] ?? false;
                $err = $registry->validate($vars[$paramName], $typeNode, '', $isSensitive);
                if ($err !== null) {
                    return ErrorFactory::createError($effectiveFunction . '(): Argument $' . $paramName . $err->getMessage());
                }
            }
        }

        return null;
    }

    /**
     * Prepares generic bindings: clears call bindings and resolves inherited templates.
     *
     * @param array<string, TemplateTagValueNode> $methodTemplates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function prepareGenericBindings(
        string $effectiveFunction,
        array $methodTemplates,
        ?object $thisObj,
        array $classTemplates,
        object|string|null $thisOrClass = null
    ): void {
        $hasTemplatesToClear = \count($methodTemplates) > 0 || ($thisObj === null && \count($classTemplates) > 0);

        if ($hasTemplatesToClear) {
            TemplateManager::clearCallBindings($effectiveFunction, [...$classTemplates, ...$methodTemplates], $thisObj);
        }

        if (\count($classTemplates) > 0 && str_contains($effectiveFunction, '::')) {
            $declaringClass = explode('::', $effectiveFunction, 2)[0];
            if ($thisObj !== null && ! TemplateManager::hasInstanceBindings($thisObj)) {
                TemplateManager::resolveInheritedTemplates($thisObj, $declaringClass);
            } elseif ($thisObj === null) {
                $targetClass = \is_string($thisOrClass) && $thisOrClass !== '' ? $thisOrClass : $declaringClass;
                TemplateManager::getClassInheritedBindings($targetClass);
            }

            if ($thisObj !== null && ! str_ends_with(strtolower($effectiveFunction), '::__construct')) {
                TemplateManager::applyClassTemplateDefaults($thisObj, $classTemplates);
            }
        }
    }

    /**
     * Pre‑resolves and caches base types for each parameter using zero-allocation 2D lookup.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, TypeNode> $aliases
     *
     * @return array<string, TypeNode>
     */
    private static function resolveBaseTypes(
        array $types,
        string $effectiveFunction,
        ?object $thisObj,
        array $aliases,
        bool $paramsAreDynamic = false
    ): array {
        $baseTypes = [];
        foreach ($types as $paramName => $typeNode) {
            if ($paramsAreDynamic && ! isset(self::$paramDynamicCache[$effectiveFunction][$paramName])) {
                $typeString = (string) $typeNode;
                self::$paramDynamicCache[$effectiveFunction][$paramName] =
                    str_contains($typeString, 'static') || str_contains($typeString, '$this');
            }

            $isParamDynamic = $paramsAreDynamic && (self::$paramDynamicCache[$effectiveFunction][$paramName] ?? false);

            if (! $isParamDynamic && isset(self::$baseTypeCache[$effectiveFunction][$paramName])) {
                $baseTypes[$paramName] = self::$baseTypeCache[$effectiveFunction][$paramName];

                continue;
            }

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            $resolved = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);

            if (! $isParamDynamic) {
                self::$baseTypeCache[$effectiveFunction][$paramName] = $resolved;
            }

            $baseTypes[$paramName] = $resolved;
        }

        return $baseTypes;
    }

    /**
     * Validates all parameters against their resolved base types.
     *
     * @param array<string, TypeNode> $contractTypes
     * @param array<string, TypeNode> $baseTypes
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $allTemplates
     * @param array<string, TypeNode> $aliases
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, TemplateTagValueNode> $classTemplates
     * @param array<string, bool> $sensitiveParams
     */
    private static function validateAllParameters(
        array $contractTypes,
        array $baseTypes,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $allTemplates,
        array $aliases,
        array $boundTemplates,
        array $declaredTemplates,
        TypeValidatorRegistry $registry,
        array $classTemplates,
        object|string|null $thisOrClass = null,
        array $sensitiveParams = []
    ): ?ErrorMessage {
        foreach ($contractTypes as $paramName => $_) {
            if (! isset($vars[$paramName]) && ! \array_key_exists($paramName, $vars)) {
                continue;
            }

            $currentBoundTemplates = (\count($allTemplates) > 0)
                ? TemplateManager::getBoundTemplates($effectiveFunction, $thisOrClass, $allTemplates)
                : $boundTemplates;

            $isSensitive = $sensitiveParams[$paramName] ?? false;

            $err = self::validateSingleParam(
                $paramName,
                $baseTypes[$paramName],
                $vars[$paramName],
                $effectiveFunction,
                $thisObj,
                $allTemplates,
                $aliases,
                $currentBoundTemplates,
                $declaredTemplates,
                $registry,
                $classTemplates,
                $vars,
                $thisOrClass,
                $isSensitive
            );

            if ($err !== null) {
                return $err;
            }
        }

        if (\count($allTemplates) > 0) {
            return self::validateCrossReferencedTemplateBounds(
                $contractTypes,
                $baseTypes,
                $vars,
                $effectiveFunction,
                $thisObj,
                $allTemplates,
                $registry,
                $thisOrClass,
                $sensitiveParams
            );
        }

        return null;
    }

    /**
     * Resolves the actual runtime class name vs trait name with O(1) 2D memoization.
     */
    public static function resolveEffectiveFunction(string $function, object|string|null $thisOrClass, ?object $thisObj = null): string
    {
        if (! str_contains($function, '::')) {
            return $function;
        }

        $actualClassName = \is_object($thisOrClass) ? $thisOrClass::class : (\is_string($thisOrClass) ? $thisOrClass : '');

        if ($actualClassName === '') {
            return $function;
        }

        if (isset(self::$effectiveFunctionCache[$function][$actualClassName])) {
            return self::$effectiveFunctionCache[$function][$actualClassName];
        }

        $traitAliases = HierarchyResolver::getTraitAliases($actualClassName);

        if (str_starts_with($function, $actualClassName . '::') && $traitAliases === []) {
            return self::$effectiveFunctionCache[$function][$actualClassName] = $function;
        }

        [$classOrTrait, $methodName] = explode('::', $function, 2);

        $effectiveFunction = ($actualClassName !== $classOrTrait)
            ? $actualClassName . '::' . $methodName
            : $function;

        if ($thisObj !== null && $traitAliases !== []) {
            $isTargetOfAlias = false;
            foreach ($traitAliases as $originalTarget) {
                if (str_ends_with($originalTarget, '::' . $methodName)) {
                    $isTargetOfAlias = true;

                    break;
                }
            }

            if ($isTargetOfAlias) {
                $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6);
                foreach ($trace as $frame) {
                    $frameFunc = $frame['function'];
                    $frameClass = $frame['class'] ?? '';
                    if (($frameClass === $actualClassName || $frameClass === $classOrTrait) && isset($traitAliases[$frameFunc])) {
                        return self::$effectiveFunctionCache[$function][$actualClassName] = $actualClassName . '::' . $frameFunc;
                    }
                }
            }
        }

        return self::$effectiveFunctionCache[$function][$actualClassName] = $effectiveFunction;
    }

    /**
     * Intercepts and delegates dynamic @method calls routed via __call and __callStatic.
     *
     * @param array<string, mixed> $vars
     */
    private static function handleMagicCall(
        string $effectiveFunction,
        array $vars,
        ?object $thisObj,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        if (! Config::isMagicMethodsEnabled()) {
            return null;
        }

        if (! str_ends_with($effectiveFunction, '::__call') && ! str_ends_with($effectiveFunction, '::__callStatic')) {
            return null;
        }

        $magicMethodName = array_values($vars)[0] ?? null;
        $magicArgs = array_values($vars)[1] ?? [];

        if (! \is_string($magicMethodName) || ! \is_array($magicArgs)) {
            return null;
        }

        $className = explode('::', $effectiveFunction, 2)[0];
        $magicContract = DocblockParser::parseMagicMethod($className, $magicMethodName);

        if ($magicContract === null) {
            return null;
        }

        $magicFunction = $className . '::' . $magicMethodName;

        return self::validateMagicArguments($magicContract, $magicArgs, $magicFunction, $thisObj, $registry);
    }

    /**
     * Pre-infers generic template parameters from closure typehints, array arguments, shapes, and generic object arguments.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function preInferGenericTemplates(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        if (self::callableNodesFor($effectiveFunction, $types) !== []) {
            foreach ($vars as $v) {
                if ($v instanceof \Closure) {
                    self::inferTemplatesFromClosures($types, $vars, $effectiveFunction, $thisObj, $templates, $classTemplates);

                    break;
                }
            }
        }

        if (\count($types) > 0) {
            self::inferTemplatesFromArrays($types, $vars, $effectiveFunction, $thisObj, $templates, $classTemplates);
            self::inferTemplatesFromShapes($types, $vars, $effectiveFunction, $thisObj, $templates, $classTemplates);
            self::inferTemplatesFromGenericObjects($types, $vars, $effectiveFunction, $thisObj, $templates, $classTemplates);
        }
    }

    /**
     * Infers generic template parameters from closure parameter typehints and return types.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplatesFromClosures(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        $callableNodes = self::callableNodesFor($effectiveFunction, $types);
        if (\count($callableNodes) === 0) {
            return;
        }

        foreach ($callableNodes as $cParamName => $cTypeNode) {
            if (! isset($vars[$cParamName]) || ! ($vars[$cParamName] instanceof \Closure)) {
                continue;
            }

            try {
                $refClosure = new \ReflectionFunction($vars[$cParamName]);
                $closureParams = $refClosure->getParameters();

                foreach ($cTypeNode->parameters as $idx => $pNode) {
                    if ($pNode->type instanceof IdentifierTypeNode && isset($templates[(string) $pNode->type]) && isset($closureParams[$idx])) {
                        $tName = (string) $pNode->type;
                        $isClassLevel = ! TemplateManager::isMethodTemplate($effectiveFunction, $tName) && isset($classTemplates[$tName]);
                        $targetObj = $isClassLevel ? $thisObj : null;

                        $inferredCandidate = self::extractTypeFromClosureParameter($closureParams[$idx]);

                        if ($inferredCandidate !== null) {
                            $templateTag = $templates[$tName];
                            $satisfiesBound = true;

                            if ($templateTag->bound !== null) {
                                $resolvedBound = SpecialTypeResolver::resolve($templateTag->bound, $effectiveFunction, $thisObj);
                                $satisfiesBound = TemplateManager::checkVariance($inferredCandidate, $resolvedBound, GenericTypeNode::VARIANCE_COVARIANT);
                            }

                            if ($satisfiesBound) {
                                TemplateManager::bindTemplate($effectiveFunction, $targetObj, $tName, $inferredCandidate);
                            }
                        }
                    }
                }

                self::inferClosureReturnTemplate(
                    $cTypeNode->returnType,
                    $refClosure,
                    $effectiveFunction,
                    $thisObj,
                    $templates,
                    $classTemplates
                );
            } catch (\Throwable $e) {
                // Silently ignore reflection errors
            }
        }
    }

    /**
     * Infers a return-type template parameter from a closure's declared return type.
     *
     * Handles direct templates (: R), nullables (: ?R), and union pairs (: R|null) such as in mapNotNull.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferClosureReturnTemplate(
        TypeNode $returnTypeNode,
        \ReflectionFunction $refClosure,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates
    ): void {
        $templateName = null;
        $unwrapNullable = false;

        if ($returnTypeNode instanceof IdentifierTypeNode && isset($templates[$returnTypeNode->name])) {
            $templateName = $returnTypeNode->name;
        } elseif ($returnTypeNode instanceof NullableTypeNode && $returnTypeNode->type instanceof IdentifierTypeNode && isset($templates[$returnTypeNode->type->name])) {
            $templateName = $returnTypeNode->type->name;
            $unwrapNullable = true;
        } elseif ($returnTypeNode instanceof UnionTypeNode) {
            $foundTemplate = null;
            $hasNull = false;
            foreach ($returnTypeNode->types as $member) {
                if ($member instanceof IdentifierTypeNode) {
                    if (strtolower($member->name) === 'null') {
                        $hasNull = true;
                    } elseif (isset($templates[$member->name])) {
                        $foundTemplate = $member->name;
                    }
                }
            }
            if ($foundTemplate !== null && $hasNull && \count($returnTypeNode->types) === 2) {
                $templateName = $foundTemplate;
                $unwrapNullable = true;
            }
        }

        if ($templateName === null) {
            return;
        }

        $isClassLevel = ! TemplateManager::isMethodTemplate($effectiveFunction, $templateName) && isset($classTemplates[$templateName]);
        $targetObj = $isClassLevel ? $thisObj : null;

        if (TemplateManager::isBound($effectiveFunction, $targetObj, $templateName)) {
            return;
        }

        $templateTag = $templates[$templateName];
        $inferred = $refClosure->hasReturnType()
            ? self::extractTypeFromReflectionType($refClosure->getReturnType())
            : null;

        if ($inferred === null) {
            if ($templateTag->default !== null) {
                $fallback = $templateTag->bound !== null
                    ? SpecialTypeResolver::resolve($templateTag->bound, $effectiveFunction, $thisObj)
                    : new IdentifierTypeNode('mixed');
                TemplateManager::bindTemplate($effectiveFunction, $targetObj, $templateName, $fallback);
            }

            return;
        }

        if ($unwrapNullable && $inferred instanceof NullableTypeNode) {
            $inferred = $inferred->type;
        }

        $satisfiesBound = true;

        if ($templateTag->bound !== null) {
            $resolvedBound = SpecialTypeResolver::resolve($templateTag->bound, $effectiveFunction, $thisObj);
            $satisfiesBound = TemplateManager::checkVariance($inferred, $resolvedBound, GenericTypeNode::VARIANCE_COVARIANT);
        }

        if ($satisfiesBound) {
            TemplateManager::bindTemplate($effectiveFunction, $targetObj, $templateName, $inferred);
        }
    }

    /**
     * Extracts an inferred TypeNode from a ReflectionType object, ignoring unconstrained types.
     */
    private static function extractTypeFromReflectionType(?\ReflectionType $type): ?TypeNode
    {
        if ($type === null) {
            return null;
        }

        if ($type instanceof \ReflectionNamedType) {
            $name = $type->getName();
            if ($name === 'mixed' || $name === 'void' || $name === 'never') {
                return null;
            }

            if (SpecialTypeResolver::isBuiltInTypeKeyword($name) || class_exists($name) || interface_exists($name)) {
                $node = new IdentifierTypeNode($name);

                return $type->allowsNull() && $name !== 'null' ? new NullableTypeNode($node) : $node;
            }
        } elseif ($type instanceof \ReflectionUnionType) {
            $members = [];
            foreach ($type->getTypes() as $subType) {
                $extracted = self::extractTypeFromReflectionType($subType);
                if ($extracted !== null) {
                    $members[] = $extracted;
                }
            }
            if ($members !== []) {
                return \count($members) === 1 ? $members[0] : new UnionTypeNode($members);
            }
        } elseif ($type instanceof \ReflectionIntersectionType) {
            $members = [];
            foreach ($type->getTypes() as $subType) {
                $extracted = self::extractTypeFromReflectionType($subType);
                if ($extracted !== null) {
                    $members[] = $extracted;
                }
            }
            if ($members !== []) {
                return \count($members) === 1 ? $members[0] : new IntersectionTypeNode($members);
            }
        }

        return null;
    }

    /**
     * Returns the callable parameter nodes of a contract, memoized per function.
     *
     * @param array<string, TypeNode> $types
     *
     * @return array<string, CallableTypeNode>
     */
    private static function callableNodesFor(string $effectiveFunction, array $types): array
    {
        return self::$callableNodesCache[$effectiveFunction] ??= self::extractCallableNodes($types);
    }

    /**
     * @param array<string, TypeNode> $types
     *
     * @return array<string, CallableTypeNode>
     */
    private static function extractCallableNodes(array $types): array
    {
        $callableNodes = [];
        foreach ($types as $paramName => $tNode) {
            while ($tNode instanceof NullableTypeNode) {
                $tNode = $tNode->type;
            }

            if ($tNode instanceof CallableTypeNode) {
                $callableNodes[$paramName] = $tNode;
            } elseif ($tNode instanceof UnionTypeNode) {
                foreach ($tNode->types as $subT) {
                    if ($subT instanceof CallableTypeNode) {
                        $callableNodes[$paramName] = $subT;

                        break;
                    }
                }
            }
        }

        return $callableNodes;
    }

    /**
     * Re-validates template upper bounds that reference templates (F-bounds and cross-template bounds) once all parameters have been bound.
     *
     * @param array<string, TypeNode> $contractTypes
     * @param array<string, TypeNode> $baseTypes
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $allTemplates
     * @param array<string, bool> $sensitiveParams
     */
    private static function validateCrossReferencedTemplateBounds(
        array $contractTypes,
        array $baseTypes,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $allTemplates,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null,
        array $sensitiveParams = []
    ): ?ErrorMessage {
        $finalBoundTemplates = TemplateManager::getBoundTemplates($effectiveFunction, $thisOrClass, $allTemplates);
        if (\count($finalBoundTemplates) === 0) {
            return null;
        }

        foreach ($allTemplates as $tName => $templateNode) {
            if ($templateNode->bound === null || ! isset($finalBoundTemplates[$tName])) {
                continue;
            }

            if (! DocblockParser::typeReferencesTemplate($templateNode->bound, $allTemplates)) {
                continue;
            }

            $substitutedBound = TemplateSubstitutor::substitute($templateNode->bound, $finalBoundTemplates);
            $resolvedBound = SpecialTypeResolver::resolve($substitutedBound, $effectiveFunction, $thisObj);

            foreach ($contractTypes as $paramName => $_) {
                if (! isset($vars[$paramName]) && ! \array_key_exists($paramName, $vars)) {
                    continue;
                }

                $baseType = $baseTypes[$paramName];
                $val = $vars[$paramName];
                $isSensitive = $sensitiveParams[$paramName] ?? false;

                if ($baseType instanceof GenericTypeNode && self::isClassStringTemplate($baseType, [$tName => $templateNode])) {
                    if (\is_string($val) && ! self::checkClassStringSatisfiesBound($val, $resolvedBound)) {
                        $redact = $isSensitive || Config::isRedactValuesEnabled();
                        $boundDisplay = (string) $resolvedBound;
                        $displayVal = $redact ? 'string given' : "'" . $val . "' given";

                        return ErrorFactory::createError($effectiveFunction . '(): Argument $' . $paramName . ' (class-string<' . $tName . '>) must be a class-string of ' . $boundDisplay . ', ' . $displayVal);
                    }
                } elseif (self::getTemplateName($baseType, [$tName => $templateNode]) === $tName) {
                    $sampleVal = ($baseType instanceof ArrayTypeNode && \is_array($val)) ? ($val[0] ?? null) : $val;
                    if ($sampleVal !== null) {
                        $err = $registry->validate($sampleVal, $resolvedBound, $effectiveFunction . '(): Argument $' . $paramName . ' (template ' . $tName . ')', $isSensitive);
                        if ($err !== null) {
                            return $err;
                        }
                    }
                }
            }

            if (! TemplateManager::checkVariance($finalBoundTemplates[$tName], $resolvedBound, GenericTypeNode::VARIANCE_COVARIANT)) {
                return ErrorFactory::createError(
                    $effectiveFunction . "(): Generic type argument {$finalBoundTemplates[$tName]} does not satisfy upper bound {$resolvedBound} of template {$tName}"
                );
            }
        }

        return null;
    }

    /**
     * Extracts an inferred TypeNode from a closure parameter, ignoring `mixed`.
     */
    private static function extractTypeFromClosureParameter(\ReflectionParameter $closureParam): ?TypeNode
    {
        if (! $closureParam->hasType()) {
            return null;
        }

        $cType = $closureParam->getType();
        if ($cType instanceof \ReflectionNamedType) {
            $cTypeName = $cType->getName();
            if ($cTypeName !== 'mixed' && (SpecialTypeResolver::isBuiltInTypeKeyword($cTypeName) || class_exists($cTypeName) || interface_exists($cTypeName))) {
                $node = new IdentifierTypeNode($cTypeName);

                return $cType->allowsNull() && $cTypeName !== 'null' ? new NullableTypeNode($node) : $node;
            }
        } elseif ($cType instanceof \ReflectionUnionType) {
            $unionSubTypes = [];
            foreach ($cType->getTypes() as $subNamedType) {
                if ($subNamedType instanceof \ReflectionNamedType) {
                    $subName = $subNamedType->getName();
                    if ($subName !== 'mixed' && (SpecialTypeResolver::isBuiltInTypeKeyword($subName) || class_exists($subName) || interface_exists($subName))) {
                        $unionSubTypes[] = new IdentifierTypeNode($subName);
                    }
                }
            }
            if (\count($unionSubTypes) > 0) {
                return \count($unionSubTypes) === 1 ? $unionSubTypes[0] : new UnionTypeNode($unionSubTypes);
            }
        }

        return null;
    }

    /**
     * Infers generic template parameters from array arguments.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplatesFromArrays(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        foreach ($types as $paramName => $typeNode) {
            if (! isset($vars[$paramName]) || ! \is_array($vars[$paramName]) || \count($vars[$paramName]) === 0) {
                continue;
            }

            self::inferArrayTemplatesFromAllElements($typeNode, $vars[$paramName], $effectiveFunction, $thisObj, $templates, $classTemplates);
        }
    }

    /**
     * Infers generic template parameters from array and object shapes.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplatesFromShapes(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        foreach ($types as $paramName => $typeNode) {
            if (isset($vars[$paramName])) {
                self::inferShapeNodeTemplates($typeNode, $vars[$paramName], $effectiveFunction, $thisObj, $templates, $classTemplates);
            }
        }
    }

    /**
     * Traverses shape nodes (including within intersections/unions) and inspects properties or keys.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferShapeNodeTemplates(
        TypeNode $typeNode,
        mixed $value,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        if ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof IntersectionTypeNode || $typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $subType) {
                self::inferShapeNodeTemplates($subType, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }

            return;
        }

        if ($typeNode instanceof ObjectShapeNode && \is_object($value)) {
            foreach ($typeNode->items as $item) {
                $prop = $item->keyName instanceof IdentifierTypeNode ? $item->keyName->name : (string) $item->keyName;

                // @phpstan-ignore property.dynamicName
                if (isset($value->$prop) || property_exists($value, $prop)) {
                    // @phpstan-ignore property.dynamicName
                    $propVal = $value->$prop;
                    self::inferTemplateFromTypeAndValue($item->valueType, $propVal, $effectiveFunction, $thisObj, $templates, $classTemplates);
                }
            }

            return;
        }

        if ($typeNode instanceof ArrayShapeNode && \is_array($value)) {
            $nextIdx = 0;
            foreach ($typeNode->items as $item) {
                $key = match (true) {
                    $item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode => $item->keyName->value,
                    $item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode => (int) $item->keyName->value,
                    $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                    $item->keyName !== null => (string) $item->keyName,
                    default => $nextIdx,
                };

                if (\is_int($key)) {
                    $nextIdx = max($nextIdx, $key + 1);
                }

                if (\array_key_exists($key, $value)) {
                    self::inferTemplateFromTypeAndValue($item->valueType, $value[$key], $effectiveFunction, $thisObj, $templates, $classTemplates);
                }
            }

            if ($typeNode->unsealedType !== null) {
                $unsealed = $typeNode->unsealedType;
                foreach ($value as $k => $v) {
                    self::inferTemplateFromTypeAndValue($unsealed->valueType, $v, $effectiveFunction, $thisObj, $templates, $classTemplates);
                    if ($unsealed->keyType !== null) {
                        self::inferTemplateFromTypeAndValue($unsealed->keyType, $k, $effectiveFunction, $thisObj, $templates, $classTemplates);
                    }
                }
            }
        }
    }

    /**
     * Unified type-value dispatcher: resolves any field type (bare template, generic collection, object, nested shape).
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplateFromTypeAndValue(
        TypeNode $typeNode,
        mixed $value,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        if ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if (isset($templates[$typeNode->name])) {
                $inferred = TemplateManager::inferTypeFromValue($value);
                self::bindTemplateIfUnbound($typeNode->name, $inferred, $effectiveFunction, $thisObj, $templates);
            }

            return;
        }

        if ($typeNode instanceof ArrayShapeNode || $typeNode instanceof ObjectShapeNode || $typeNode instanceof IntersectionTypeNode) {
            self::inferShapeNodeTemplates($typeNode, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);

            return;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseType = strtolower((string) $typeNode->type);
            if (isset(self::ITERABLE_TYPES[$baseType]) && \is_array($value)) {
                self::inferArrayTemplatesFromAllElements($typeNode, $value, $effectiveFunction, $thisObj, $templates);
            } elseif (\is_object($value)) {
                self::inferGenericObjectNode($typeNode, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }

            return;
        }

        if ($typeNode instanceof ArrayTypeNode && \is_array($value)) {
            self::inferArrayTemplatesFromAllElements($typeNode, $value, $effectiveFunction, $thisObj, $templates);
        }
    }

    /**
     * Extracts and unifies template parameters across all elements of an array argument.
     * Uses Beartype O(1) hybrid random sampling on arrays > 128 items when hybrid mode is active.
     *
     * @param array<mixed> $arrVal
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferArrayTemplatesFromAllElements(
        TypeNode $typeNode,
        array $arrVal,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof UnionTypeNode || $typeNode instanceof IntersectionTypeNode) {
            foreach ($typeNode->types as $subType) {
                self::inferArrayTemplatesFromAllElements($subType, $arrVal, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }

            return;
        }

        if ($typeNode instanceof ArrayTypeNode && ($typeNode->type instanceof ArrayShapeNode || $typeNode->type instanceof ObjectShapeNode)) {
            $sampleItems = self::getSampleArraySlice($arrVal);
            self::inferShapeTemplatesFromArraySamples($typeNode->type, $sampleItems, $effectiveFunction, $thisObj, $templates, $classTemplates);

            return;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseType = strtolower((string) $typeNode->type);
            if (! isset(self::ITERABLE_TYPES[$baseType])) {
                return;
            }

            $sampleItems = self::getSampleArraySlice($arrVal);
            $genericCount = \count($typeNode->genericTypes);

            if ($genericCount === 1 && $typeNode->genericTypes[0] instanceof IdentifierTypeNode) {
                self::inferSingleTemplateFromArraySamples($typeNode->genericTypes[0]->name, $sampleItems, $effectiveFunction, $thisObj, $templates, $classTemplates);
            } elseif ($genericCount === 1 && ($typeNode->genericTypes[0] instanceof ArrayShapeNode || $typeNode->genericTypes[0] instanceof ObjectShapeNode)) {
                self::inferShapeTemplatesFromArraySamples($typeNode->genericTypes[0], $sampleItems, $effectiveFunction, $thisObj, $templates, $classTemplates);
            } elseif ($genericCount >= 2) {
                $keyTName = $typeNode->genericTypes[0] instanceof IdentifierTypeNode ? $typeNode->genericTypes[0]->name : null;
                $valTypeNode = $typeNode->genericTypes[1];
                $valTName = $valTypeNode instanceof IdentifierTypeNode ? $valTypeNode->name : null;

                $inferredKeyType = null;
                $inferredValType = null;

                foreach ($sampleItems as $key => $item) {
                    if ($keyTName !== null) {
                        $keyType = TemplateManager::inferTypeFromValue($key);
                        $inferredKeyType = ($inferredKeyType === null) ? $keyType : self::unifyTypes($inferredKeyType, $keyType);
                    }
                    if ($valTName !== null) {
                        $valType = TemplateManager::inferTypeFromValue($item);
                        $inferredValType = ($inferredValType === null) ? $valType : self::unifyTypes($inferredValType, $valType);
                    }
                }

                if ($keyTName !== null && $inferredKeyType !== null) {
                    self::bindTemplateIfUnbound($keyTName, $inferredKeyType, $effectiveFunction, $thisObj, $templates, $classTemplates);
                }
                if ($valTName !== null && $inferredValType !== null) {
                    self::bindTemplateIfUnbound($valTName, $inferredValType, $effectiveFunction, $thisObj, $templates, $classTemplates);
                }
                if ($valTypeNode instanceof ArrayShapeNode || $valTypeNode instanceof ObjectShapeNode) {
                    self::inferShapeTemplatesFromArraySamples($valTypeNode, $sampleItems, $effectiveFunction, $thisObj, $templates, $classTemplates);
                }
            }
        }
    }

    /**
     * Collects and unifies shape template types across multiple elements of a collection/array.
     * Within each individual shape element, the first occurrence of a template determines that element's type;
     * across separate elements of the collection, those types are unified if they satisfy the template's bound.
     *
     * @param array<mixed> $sampleItems
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferShapeTemplatesFromArraySamples(
        TypeNode $shapeTypeNode,
        array $sampleItems,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        /** @var array<string, TypeNode> $unifiedAcrossSamples */
        $unifiedAcrossSamples = [];

        foreach ($sampleItems as $sample) {
            /** @var array<string, TypeNode> $sampleBindings */
            $sampleBindings = [];
            self::collectSingleShapeElementTemplates($shapeTypeNode, $sample, $templates, $sampleBindings);

            foreach ($sampleBindings as $tName => $inferredType) {
                if (self::doesInferredTypeSatisfyBound($tName, $inferredType, $effectiveFunction, $thisObj, $templates)) {
                    $unifiedAcrossSamples[$tName] = isset($unifiedAcrossSamples[$tName])
                        ? self::unifyTypes($unifiedAcrossSamples[$tName], $inferredType)
                        : $inferredType;
                }
            }
        }

        foreach ($unifiedAcrossSamples as $tName => $unifiedType) {
            self::bindTemplateIfUnbound($tName, $unifiedType, $effectiveFunction, $thisObj, $templates, $classTemplates);
        }
    }

    /**
     * Extracts template types from a single shape element into $sampleBindings (locking the first seen per template within that element).
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $sampleBindings
     */
    private static function collectSingleShapeElementTemplates(
        TypeNode $typeNode,
        mixed $value,
        array $templates,
        array &$sampleBindings
    ): void {
        if ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            if (isset($templates[$typeNode->name]) && ! isset($sampleBindings[$typeNode->name])) {
                $sampleBindings[$typeNode->name] = TemplateManager::inferTypeFromValue($value);
            }

            return;
        }

        if ($typeNode instanceof IntersectionTypeNode || $typeNode instanceof UnionTypeNode) {
            foreach ($typeNode->types as $subType) {
                self::collectSingleShapeElementTemplates($subType, $value, $templates, $sampleBindings);
            }

            return;
        }

        if ($typeNode instanceof ObjectShapeNode && \is_object($value)) {
            foreach ($typeNode->items as $item) {
                $prop = $item->keyName instanceof IdentifierTypeNode ? $item->keyName->name : (string) $item->keyName;

                // @phpstan-ignore property.dynamicName
                if (isset($value->$prop) || property_exists($value, $prop)) {
                    // @phpstan-ignore property.dynamicName
                    $propVal = $value->$prop;
                    self::collectSingleShapeElementTemplates($item->valueType, $propVal, $templates, $sampleBindings);
                }
            }

            return;
        }

        if ($typeNode instanceof ArrayShapeNode && \is_array($value)) {
            $nextIdx = 0;
            foreach ($typeNode->items as $item) {
                $key = match (true) {
                    $item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode => $item->keyName->value,
                    $item->keyName instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode => (int) $item->keyName->value,
                    $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                    $item->keyName !== null => (string) $item->keyName,
                    default => $nextIdx,
                };

                if (\is_int($key)) {
                    $nextIdx = max($nextIdx, $key + 1);
                }

                if (\array_key_exists($key, $value)) {
                    self::collectSingleShapeElementTemplates($item->valueType, $value[$key], $templates, $sampleBindings);
                }
            }

            if ($typeNode->unsealedType !== null) {
                $unsealed = $typeNode->unsealedType;
                foreach ($value as $k => $v) {
                    self::collectSingleShapeElementTemplates($unsealed->valueType, $v, $templates, $sampleBindings);
                    if ($unsealed->keyType !== null) {
                        self::collectSingleShapeElementTemplates($unsealed->keyType, $k, $templates, $sampleBindings);
                    }
                }
            }
        }
    }

    /**
     * Checks whether a candidate inferred TypeNode satisfies the declared upper bound of a template.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function doesInferredTypeSatisfyBound(
        string $templateName,
        TypeNode $inferredType,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): bool {
        if (! isset($templates[$templateName])) {
            return false;
        }

        $templateTag = $templates[$templateName];
        if ($templateTag->bound === null) {
            return true;
        }

        $resolvedBound = SpecialTypeResolver::resolve($templateTag->bound, $effectiveFunction, $thisObj);

        return TemplateManager::checkVariance($inferredType, $resolvedBound, GenericTypeNode::VARIANCE_COVARIANT);
    }

    /**
     * @param array<mixed> $sampleItems
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferSingleTemplateFromArraySamples(
        string $templateName,
        array $sampleItems,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        $inferredType = null;
        foreach ($sampleItems as $item) {
            $itemType = TemplateManager::inferTypeFromValue($item);
            $inferredType = ($inferredType === null) ? $itemType : self::unifyTypes($inferredType, $itemType);
        }

        if ($inferredType !== null) {
            self::bindTemplateIfUnbound($templateName, $inferredType, $effectiveFunction, $thisObj, $templates, $classTemplates);
        }
    }

    /**
     * Extracts items for template inference, using hybrid sampling for arrays > 128 items.
     *
     * @param array<mixed> $arrVal
     *
     * @return array<mixed>
     */
    private static function getSampleArraySlice(array $arrVal): array
    {
        $count = \count($arrVal);
        if ($count <= Config::HYBRID_SAMPLE_THRESHOLD || ! Config::isArrayValidationHybrid()) {
            return $arrVal;
        }

        $keys = array_keys($arrVal);
        $sampleKeys = [$keys[0], $keys[$count - 1]];
        $samplesToTake = min(3, $count - 2);
        for ($i = 0; $i < $samplesToTake; $i++) {
            $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
        }

        $samples = [];
        foreach ($sampleKeys as $k) {
            $samples[$k] = $arrVal[$k];
        }

        return $samples;
    }

    /**
     * Binds a template parameter if it is not already bound in the current scope,
     * ensuring it satisfies declared upper bounds.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function bindTemplateIfUnbound(
        string $templateName,
        TypeNode $inferredType,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        if ($classTemplates === []) {
            $contract = DocblockParser::parse($effectiveFunction);
            $classTemplates = $contract['classTemplates'] ?? [];
        }
        $isClassLevelTemplate = ! TemplateManager::isMethodTemplate($effectiveFunction, $templateName) && isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (isset($templates[$templateName]) && ! TemplateManager::isBound($effectiveFunction, $targetObj, $templateName)) {
            if (self::doesInferredTypeSatisfyBound($templateName, $inferredType, $effectiveFunction, $thisObj, $templates)) {
                TemplateManager::bindTemplate($effectiveFunction, $targetObj, $templateName, $inferredType);
            }
        }
    }

    /**
     * Pre-infers generic template parameters from generic object arguments (e.g. PBox<T> or array<K, PBox<T>>).
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplatesFromGenericObjects(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        foreach ($types as $paramName => $typeNode) {
            if (! isset($vars[$paramName])) {
                continue;
            }

            $value = $vars[$paramName];
            self::inferGenericObjectNode($typeNode, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);
        }
    }

    /**
     * Resolves the reflection context of a function or method once, memoizing hits and misses alike.
     *
     * @return \ReflectionClass<object>|\ReflectionFunction|null
     */
    private static function genericObjectContext(string $effectiveFunction): \ReflectionClass|\ReflectionFunction|null
    {
        if (\array_key_exists($effectiveFunction, self::$genericObjectContextCache)) {
            return self::$genericObjectContextCache[$effectiveFunction];
        }

        $context = null;

        if (str_contains($effectiveFunction, '::')) {
            $declaringClass = explode('::', $effectiveFunction, 2)[0];
            if (class_exists($declaringClass, false) || class_exists($declaringClass) || interface_exists($declaringClass) || trait_exists($declaringClass)) {
                $context = new \ReflectionClass($declaringClass);
            }
        } elseif (\function_exists($effectiveFunction)) {
            $context = new \ReflectionFunction($effectiveFunction);
        }

        return self::$genericObjectContextCache[$effectiveFunction] = $context;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferGenericObjectNode(
        TypeNode $typeNode,
        mixed $value,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        while ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof UnionTypeNode || $typeNode instanceof IntersectionTypeNode) {
            foreach ($typeNode->types as $subType) {
                self::inferGenericObjectNode($subType, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }

            return;
        }

        if ($typeNode instanceof GenericTypeNode && \is_object($value)) {
            $baseName = strtolower((string) $typeNode->type);

            if ($value instanceof \Traversable && isset(self::TRAVERSABLE_OBJECT_TYPES[$baseName])) {
                self::inferTemplatesFromIterableObject($typeNode, $value, $effectiveFunction, $thisObj, $templates, $classTemplates);

                return;
            }

            if (isset(self::COLLECTION_TYPES[$baseName])) {
                return;
            }

            $typeName = (string) $typeNode->type;
            $contextRef = self::genericObjectContext($effectiveFunction);
            $contextKey = $contextRef !== null ? $effectiveFunction : '@' . $value::class;

            $targetClassFqcn = self::$genericObjectTargetCache[$contextKey][$typeName] ?? null;
            if ($targetClassFqcn === null) {
                $targetClassFqcn = SpecialTypeResolver::resolveFqcn($typeName, $contextRef ?? new \ReflectionClass($value));
                self::$genericObjectTargetCache[$contextKey][$typeName] = $targetClassFqcn;
            }

            if (! is_a($value, $targetClassFqcn)) {
                return;
            }

            $boundOnInstance = TemplateManager::getBoundTemplatesForInstance($value);
            if ($boundOnInstance === []) {
                return;
            }

            if (TemplateManager::isGeneratorInferred($value)) {
                TemplateManager::markGeneratorInferred($thisObj, $effectiveFunction);
            }

            $targetTemplateNames = self::$classDeclaredTemplateNamesCache[$targetClassFqcn] ?? null;

            if ($targetTemplateNames === null) {
                $targetTemplateNames = [];
                if (class_exists($targetClassFqcn, false) || class_exists($targetClassFqcn) || interface_exists($targetClassFqcn) || trait_exists($targetClassFqcn)) {
                    try {
                        $targetRef = new \ReflectionClass($targetClassFqcn);
                        $targetDoc = StubManager::getClassDoc($targetClassFqcn) ?? $targetRef->getDocComment();
                        if ($targetDoc !== false) {
                            $targetPhpDocNode = DocblockExtractor::parseDocString($targetDoc);
                            $targetTemplateNames = array_keys(DocblockExtractor::extractTemplates($targetPhpDocNode));
                        }
                    } catch (\Throwable $e) {
                    }
                }
                self::$classDeclaredTemplateNamesCache[$targetClassFqcn] = $targetTemplateNames;
            }

            $instanceBoundTypes = array_values($boundOnInstance);

            foreach ($typeNode->genericTypes as $idx => $gtNode) {
                if ($gtNode instanceof IdentifierTypeNode && isset($templates[$gtNode->name])) {
                    $tName = $gtNode->name;
                    $isClassLevel = ! TemplateManager::isMethodTemplate($effectiveFunction, $tName) && isset($classTemplates[$tName]);
                    $targetObj = $isClassLevel ? $thisObj : null;

                    if (! TemplateManager::isBound($effectiveFunction, $targetObj, $tName)) {
                        $targetTemplateName = $targetTemplateNames[$idx] ?? null;
                        $inferredCandidate = null;

                        if ($targetTemplateName !== null && isset($boundOnInstance[$targetTemplateName])) {
                            $inferredCandidate = $boundOnInstance[$targetTemplateName];
                        } elseif (isset($instanceBoundTypes[$idx])) {
                            $inferredCandidate = $instanceBoundTypes[$idx];
                        }

                        if ($inferredCandidate !== null) {
                            $templateTag = $templates[$tName];
                            $satisfiesBound = true;

                            if ($templateTag->bound !== null) {
                                $resolvedBound = SpecialTypeResolver::resolve($templateTag->bound, $effectiveFunction, $thisObj);
                                $satisfiesBound = TemplateManager::checkVariance($inferredCandidate, $resolvedBound, GenericTypeNode::VARIANCE_COVARIANT);
                            }

                            if ($satisfiesBound) {
                                TemplateManager::bindTemplate($effectiveFunction, $targetObj, $tName, $inferredCandidate);
                            }
                        }
                    }
                }
            }
        } elseif (\is_array($value)) {
            $innerType = null;
            if ($typeNode instanceof ArrayTypeNode) {
                $innerType = $typeNode->type;
            } elseif ($typeNode instanceof GenericTypeNode) {
                $baseName = strtolower((string) $typeNode->type);
                if (isset(self::COLLECTION_TYPES[$baseName])) {
                    $innerType = $typeNode->genericTypes[1] ?? $typeNode->genericTypes[0] ?? null;
                }
            }

            if ($innerType !== null) {
                foreach ($value as $item) {
                    if (\is_object($item)) {
                        self::inferGenericObjectNode($innerType, $item, $effectiveFunction, $thisObj, $templates, $classTemplates);
                    }
                }
            }
        }
    }

    /**
     * Infers key and value template parameters when an object (Generator or Traversable)
     * is passed to an iterable<K, V> or iterable<T> parameter.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function inferTemplatesFromIterableObject(
        GenericTypeNode $typeNode,
        object $value,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $classTemplates = []
    ): void {
        $inferredKey = null;
        $inferredVal = null;

        if ($value instanceof \Generator) {
            TemplateManager::markGeneratorInferred($thisObj, $effectiveFunction);

            try {
                $refGen = new \ReflectionGenerator($value);
                $genFunc = $refGen->getFunction();
                $genDoc = $genFunc->getDocComment();
                if ($genDoc !== false) {
                    $phpDocNode = DocblockExtractor::parseDocString($genDoc);
                    $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
                    if ($returnTag !== null) {
                        $genContract = GeneratorEvaluator::extractContract($returnTag->type);
                        if ($genContract !== null) {
                            $inferredKey = $genContract['key'];
                            $inferredVal = $genContract['value'];

                            $genThis = $refGen->getThis();
                            $boundOnGenThis = ($genThis !== null)
                                ? TemplateManager::getBoundTemplatesForInstance($genThis)
                                : [];

                            $genMethodTemplates = DocblockExtractor::extractTemplates($phpDocNode);
                            $genClassTemplates = [];
                            $genAliases = [];
                            $genContext = null;

                            if ($genFunc instanceof \ReflectionMethod) {
                                $genContext = $genFunc;
                                $declaringClassName = $genFunc->getDeclaringClass()->getName();
                                $genClassTemplates = DocblockParser::parseClassTemplates($declaringClassName);
                                $genAliases = DocblockParser::parseClassAliases($declaringClassName);
                                DocblockExtractor::extractAliases($phpDocNode, $genAliases, $genFunc);
                                $genFunctionName = $declaringClassName . '::' . $genFunc->getName();
                            } elseif ($genFunc instanceof \ReflectionFunction) {
                                $genContext = $genFunc;
                                DocblockExtractor::extractAliases($phpDocNode, $genAliases, $genFunc);
                                $genFunctionName = $genFunc->getName();
                            } else {
                                $genFunctionName = $genFunc->getName();
                            }

                            $allGenDeclaredTemplates = [...$genClassTemplates, ...$genMethodTemplates];

                            if ($inferredKey !== null) {
                                if ($genAliases !== []) {
                                    $inferredKey = DocblockParser::substituteAliases($inferredKey, $genAliases);
                                }
                                $inferredKey = TemplateSubstitutor::substitute($inferredKey, $boundOnGenThis, $allGenDeclaredTemplates);
                                if ($genContext !== null) {
                                    $inferredKey = SpecialTypeResolver::resolve($inferredKey, $genContext, $genThis);
                                }
                                if (ConditionalChecker::containsConditional($inferredKey)) {
                                    $inferredKey = ConditionalChecker::resolve($inferredKey, [], $boundOnGenThis, \TypePHP\Internal\RuntimeTypeChecker::getRegistry(), $genFunctionName);
                                    if ($genContext !== null) {
                                        $inferredKey = SpecialTypeResolver::resolve($inferredKey, $genContext, $genThis);
                                    }
                                }
                            }

                            if ($inferredVal !== null) {
                                if ($genAliases !== []) {
                                    $inferredVal = DocblockParser::substituteAliases($inferredVal, $genAliases);
                                }
                                $inferredVal = TemplateSubstitutor::substitute($inferredVal, $boundOnGenThis, $allGenDeclaredTemplates);
                                if ($genContext !== null) {
                                    $inferredVal = SpecialTypeResolver::resolve($inferredVal, $genContext, $genThis);
                                }
                                if (ConditionalChecker::containsConditional($inferredVal)) {
                                    $inferredVal = ConditionalChecker::resolve($inferredVal, [], $boundOnGenThis, \TypePHP\Internal\RuntimeTypeChecker::getRegistry(), $genFunctionName);
                                    if ($genContext !== null) {
                                        $inferredVal = SpecialTypeResolver::resolve($inferredVal, $genContext, $genThis);
                                    }
                                }
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        } elseif ($value instanceof \Traversable) {
            [$inferredKey, $inferredVal] = self::resolveTraversableItemTypes($value);
        }

        $genericCount = \count($typeNode->genericTypes);

        if ($genericCount === 1) {
            $valTarget = $typeNode->genericTypes[0];
            if ($valTarget instanceof IdentifierTypeNode && isset($templates[$valTarget->name]) && $inferredVal !== null) {
                self::bindTemplateIfUnbound($valTarget->name, $inferredVal, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }
        } elseif ($genericCount >= 2) {
            $keyTarget = $typeNode->genericTypes[0];
            $valTarget = $typeNode->genericTypes[1];

            if ($keyTarget instanceof IdentifierTypeNode && isset($templates[$keyTarget->name]) && $inferredKey !== null) {
                self::bindTemplateIfUnbound($keyTarget->name, $inferredKey, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }

            if ($valTarget instanceof IdentifierTypeNode && isset($templates[$valTarget->name]) && $inferredVal !== null) {
                self::bindTemplateIfUnbound($valTarget->name, $inferredVal, $effectiveFunction, $thisObj, $templates, $classTemplates);
            }
        }
    }

    /**
     * Resolves the yielded key and value TypeNodes of a Traversable object.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode} [keyType, valueType]
     */
    private static function resolveTraversableItemTypes(object $value): array
    {
        $plan = self::traversablePlan($value);
        $boundOnInstance = TemplateManager::getBoundTemplatesForInstance($value);

        if ($plan !== null) {
            $key = $plan['key'];
            $val = $plan['value'];

            $key = $key !== null ? TemplateSubstitutor::substitute($key, $boundOnInstance, $plan['templates']) : null;
            $val = $val !== null ? TemplateSubstitutor::substitute($val, $boundOnInstance, $plan['templates']) : null;

            $key = $key !== null ? SpecialTypeResolver::resolve($key, $plan['context']) : null;
            $val = $val !== null ? SpecialTypeResolver::resolve($val, $plan['context']) : null;

            return [$key, $val];
        }

        if (\count($boundOnInstance) === 1) {
            return [null, reset($boundOnInstance)];
        }

        return [null, null];
    }

    /**
     * Builds and memoizes everything about a Traversable's iteration contract that does not
     * depend on the instance's bound templates.
     *
     * @return array{key: ?TypeNode, value: ?TypeNode, templates: array<string, TemplateTagValueNode>, context: \ReflectionClass<object>|\ReflectionMethod}|null
     */
    private static function traversablePlan(object $value): ?array
    {
        $className = $value::class;

        if (\array_key_exists($className, self::$traversablePlanCache)) {
            return self::$traversablePlanCache[$className];
        }

        $plan = null;

        if ($value instanceof \IteratorAggregate) {
            try {
                $refClass = new \ReflectionClass($className);
                $refMethod = $refClass->hasMethod('getIterator') ? $refClass->getMethod('getIterator') : null;
                $doc = $refMethod?->getDocComment() ?? false;

                if ($doc !== false && $refMethod !== null) {
                    $phpDocNode = DocblockExtractor::parseDocString($doc);
                    $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
                    $contract = $returnTag !== null ? GeneratorEvaluator::extractContract($returnTag->type) : null;

                    if ($contract !== null) {
                        $aliases = DocblockParser::parseClassAliases($refClass->getName());
                        $key = $contract['key'];
                        $val = $contract['value'];

                        if ($aliases !== []) {
                            $key = $key !== null ? DocblockParser::substituteAliases($key, $aliases) : null;
                            $val = $val !== null ? DocblockParser::substituteAliases($val, $aliases) : null;
                        }

                        $plan = [
                            'key' => $key,
                            'value' => $val,
                            'templates' => [
                                ...DocblockParser::parseClassTemplates($refMethod->getDeclaringClass()->getName()),
                                ...DocblockParser::parseClassTemplates($refClass->getName()),
                            ],
                            'context' => $refMethod,
                        ];
                    }
                }
            } catch (\Throwable) {
            }
        }

        if ($plan === null) {
            try {
                $refClass = new \ReflectionClass($className);

                foreach (HierarchyResolver::getClassHierarchy($refClass) as $hierClass) {
                    $classDoc = StubManager::getClassDoc($hierClass->getName()) ?? $hierClass->getDocComment();
                    if ($classDoc === false || $classDoc === null) {
                        continue;
                    }

                    $phpDocNode = DocblockExtractor::parseDocString($classDoc);

                    foreach (DocblockExtractor::getInheritedTags($phpDocNode) as $inheritedTag) {
                        $genericNode = $inheritedTag->type;
                        if (! $genericNode instanceof GenericTypeNode) {
                            continue;
                        }

                        $base = strtolower(ltrim((string) $genericNode->type, '\\'));
                        if ($base !== 'iteratoraggregate' && $base !== 'iterator' && $base !== 'traversable') {
                            continue;
                        }

                        $count = \count($genericNode->genericTypes);
                        $key = $count >= 2 ? $genericNode->genericTypes[0] : null;
                        $val = $count >= 2 ? $genericNode->genericTypes[1] : ($genericNode->genericTypes[0] ?? null);

                        $aliases = DocblockParser::parseClassAliases($hierClass->getName());
                        if ($aliases !== []) {
                            $key = $key !== null ? DocblockParser::substituteAliases($key, $aliases) : null;
                            $val = $val !== null ? DocblockParser::substituteAliases($val, $aliases) : null;
                        }

                        $plan = [
                            'key' => $key,
                            'value' => $val,
                            'templates' => DocblockParser::parseClassTemplates($hierClass->getName()),
                            'context' => $hierClass,
                        ];

                        break 2;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return self::$traversablePlanCache[$className] = $plan;
    }

    /**
     * Unified single-parameter validation pipeline.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $aliases
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     * @param array<string, TemplateTagValueNode> $classTemplates
     * @param array<int|string, mixed> $vars
     */
    private static function validateSingleParam(
        string $paramName,
        TypeNode $typeNode,
        mixed $val,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates,
        array $aliases,
        array $boundTemplates,
        array $declaredTemplates,
        TypeValidatorRegistry $registry,
        array $classTemplates = [],
        array $vars = [],
        object|string|null $thisOrClass = null,
        bool $isSensitive = false
    ): ?ErrorMessage {
        if (ConditionalChecker::containsConditional($typeNode)) {
            $typeNode = ConditionalChecker::resolve($typeNode, $vars, $boundTemplates, $registry, $effectiveFunction);
        }

        if (self::isUnconstrained($typeNode)) {
            return null;
        }

        $isClassStringT = ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates));
        $isBareTemplate = self::getTemplateName($typeNode, $templates) !== null;
        $shouldSkipTemplateSub = $isBareTemplate || $isClassStringT;

        if (! $shouldSkipTemplateSub && (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0)) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $declaredTemplates);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);
        }

        if (ConditionalChecker::containsConditional($typeNode)) {
            $typeNode = ConditionalChecker::resolve($typeNode, $vars, $boundTemplates, $registry, $effectiveFunction);
        }

        if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
            return self::resolveClassStringTemplate($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates, $classTemplates, $isSensitive);
        }

        if (self::getTemplateName($typeNode, $templates) !== null) {
            return self::resolveTemplateParam($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates, $registry, $classTemplates, $thisOrClass);
        }

        return $registry->validate($val, $typeNode, $effectiveFunction . '(): Argument $' . $paramName, $isSensitive);
    }

    /**
     * @param array{return: ?TypeNode, parameters: array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>, aliases: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>} $magicContract
     * @param array<int|string, mixed> $args
     */
    private static function validateMagicArguments(
        array $magicContract,
        array $args,
        string $function,
        ?object $thisObj,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        $templates = $magicContract['templates'];
        $aliases = $magicContract['aliases'];
        $parameters = $magicContract['parameters'];
        $argValues = array_values($args);
        $argKeys = array_keys($args);

        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);
        $declaredTemplates = $templates;

        foreach ($parameters as $index => $p) {
            $paramName = $p['name'];
            $typeNode = $p['type'];
            $isVariadic = $p['isVariadic'];

            if ($typeNode === null) {
                continue;
            }

            [$val, $hasVal] = self::extractMagicArgumentValue($paramName, $index, $isVariadic, $args, $argValues, $argKeys);
            if (! $hasVal) {
                continue;
            }

            if ($isVariadic) {
                $typeNode = new ArrayTypeNode($typeNode);
            }

            $err = self::validateSingleParam(
                $paramName,
                $typeNode,
                $val,
                $function,
                $thisObj,
                $templates,
                $aliases,
                $boundTemplates,
                $declaredTemplates,
                $registry,
                [],
                $args
            );

            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * @param array<int|string, mixed> $args
     * @param array<int, mixed> $argValues
     * @param array<int, int|string> $argKeys
     *
     * @return array{0: mixed, 1: bool}
     */
    private static function extractMagicArgumentValue(
        string $paramName,
        int $index,
        bool $isVariadic,
        array $args,
        array $argValues,
        array $argKeys
    ): array {
        if ($isVariadic) {
            if (\array_key_exists($paramName, $args)) {
                return [[$args[$paramName]], true];
            }

            $val = [];
            $hasVal = false;
            $count = \count($argValues);
            for ($i = $index; $i < $count; $i++) {
                if (\is_int($argKeys[$i])) {
                    $val[] = $argValues[$i];
                    $hasVal = true;
                }
            }

            return [$val, $hasVal];
        }

        if (\array_key_exists($paramName, $args)) {
            return [$args[$paramName], true];
        }

        if (\array_key_exists($index, $argValues)) {
            return [$argValues[$index], true];
        }

        return [null, false];
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function isClassStringTemplate(GenericTypeNode $typeNode, array $templates): bool
    {
        return isset($typeNode->genericTypes[0])
            && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
            && strtolower((string) $typeNode->type) === 'class-string'
            && isset($templates[$typeNode->genericTypes[0]->name]);
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function resolveClassStringTemplate(
        GenericTypeNode $typeNode,
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        array $templates,
        array $classTemplates = [],
        bool $isSensitive = false
    ): ?ErrorMessage {
        /** @var IdentifierTypeNode $innerType */
        $innerType = $typeNode->genericTypes[0];
        $templateName = $innerType->name;
        $templateNode = $templates[$templateName];
        $isClassLevelTemplate = ! TemplateManager::isMethodTemplate($function, $templateName) && isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        $redact = $isSensitive || Config::isRedactValuesEnabled();

        if (! TemplateManager::isBound($function, $targetObj, $templateName)) {
            if (! \is_string($val) || ! ClassNameValidator::isValidClassString($val)) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val, $redact) . ' given');
            }

            if ($templateNode->bound !== null) {
                $boundLower = $templateNode->bound instanceof IdentifierTypeNode ? strtolower($templateNode->bound->name) : '';
                if ($boundLower !== 'object' && $boundLower !== 'mixed') {
                    $boundNode = $templateNode->bound;
                    $classNode = TemplateManager::$classNodeCache[$val] ??= new IdentifierTypeNode($val);
                    $currentBindings = TemplateManager::getBoundTemplates($function, $thisObj, $templates);
                    $allBindings = [...$currentBindings, $templateName => $classNode];

                    if (
                        TemplateManager::isFBounded($templateNode, $function)
                        || DocblockParser::typeReferencesTemplate($boundNode, $templates)
                    ) {
                        $boundNode = TemplateSubstitutor::substitute($boundNode, $allBindings);
                    }

                    $resolvedBound = SpecialTypeResolver::resolve($boundNode, $function, $thisObj);
                    if (! self::checkClassStringSatisfiesBound($val, $resolvedBound)) {
                        $boundDisplay = (string) $resolvedBound;
                        $displayVal = $redact ? 'string given' : "'" . $val . "' given";

                        return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundDisplay . ', ' . $displayVal);
                    }
                }
            }

            $classNode = TemplateManager::$classNodeCache[$val] ??= new IdentifierTypeNode($val);
            TemplateManager::bindTemplate($function, $targetObj, $templateName, $classNode);
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $targetObj, $templateName);
            if ($expectedTypeNode !== null) {
                if (! \is_string($val) || ! self::checkClassStringSatisfiesBound($val, $expectedTypeNode)) {
                    $valStr = TypeFormatter::formatGivenValue($val, $redact);
                    $targetDisplay = (string) $expectedTypeNode;

                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetDisplay . ', ' . $valStr . ' given');
                }
            }
        }

        return null;
    }

    private static function checkClassStringSatisfiesBound(string $val, TypeNode $boundNode): bool
    {
        if ($boundNode instanceof IdentifierTypeNode) {
            $boundName = $boundNode->name;
            $lowerBound = strtolower($boundName);
            if ($lowerBound === 'object' || $lowerBound === 'mixed') {
                return true;
            }

            return is_a($val, $boundName, allow_string: true);
        }

        if ($boundNode instanceof GenericTypeNode) {
            return TemplateManager::checkVariance(new IdentifierTypeNode($val), $boundNode, GenericTypeNode::VARIANCE_COVARIANT);
        }

        if ($boundNode instanceof UnionTypeNode) {
            foreach ($boundNode->types as $unionType) {
                if (self::checkClassStringSatisfiesBound($val, $unionType)) {
                    return true;
                }
            }

            return false;
        }

        if ($boundNode instanceof IntersectionTypeNode) {
            foreach ($boundNode->types as $intersectionType) {
                if (! self::checkClassStringSatisfiesBound($val, $intersectionType)) {
                    return false;
                }
            }

            return true;
        }

        return true;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function getTemplateName(TypeNode $typeNode, array $templates): ?string
    {
        if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
            return $typeNode->name;
        }

        if ($typeNode instanceof NullableTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[(string) $typeNode->type])) {
            return (string) $typeNode->type;
        }

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[(string) $typeNode->type])) {
            return (string) $typeNode->type;
        }

        if ($typeNode instanceof UnionTypeNode && \count($typeNode->types) === 2) {
            $t0 = $typeNode->types[0];
            $t1 = $typeNode->types[1];

            if ($t0 instanceof IdentifierTypeNode && isset($templates[$t0->name]) && $t1 instanceof IdentifierTypeNode && strtolower($t1->name) === 'null') {
                return $t0->name;
            }
            if ($t1 instanceof IdentifierTypeNode && isset($templates[$t1->name]) && $t0 instanceof IdentifierTypeNode && strtolower($t0->name) === 'null') {
                return $t1->name;
            }
        }

        return null;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function resolveTemplateParam(
        TypeNode $typeNode,
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        array $templates,
        TypeValidatorRegistry $registry,
        array $classTemplates = [],
        object|string|null $thisOrClass = null
    ): ?ErrorMessage {
        $templateName = self::getTemplateName($typeNode, $templates);
        if ($templateName === null || ! isset($templates[$templateName])) {
            return null;
        }

        $templateNode = $templates[$templateName];
        $isVariadic = $typeNode instanceof ArrayTypeNode;
        $isNullable = ($typeNode instanceof NullableTypeNode) || ($typeNode instanceof UnionTypeNode && self::typeContainsNull($typeNode));
        $isClassLevelTemplate = ! TemplateManager::isMethodTemplate($function, $templateName) && isset($classTemplates[$templateName]);
        $targetObjOrClass = $isClassLevelTemplate ? ($thisObj ?? $thisOrClass) : null;
        $allowsNullInBound = ($templateNode->bound !== null && self::typeContainsNull($templateNode->bound));

        if ($isNullable && $val === null) {
            if ($allowsNullInBound && ! TemplateManager::isBound($function, $targetObjOrClass, $templateName)) {
                TemplateManager::bindTemplate($function, $thisObj, $templateName, new IdentifierTypeNode('null'));
            }

            return null;
        }

        if (! TemplateManager::isBound($function, $targetObjOrClass, $templateName)) {
            return self::bindInitialTemplate(
                $val,
                $paramName,
                $function,
                $thisObj,
                $thisObj,
                $templateName,
                $templateNode,
                $isVariadic,
                $isClassLevelTemplate,
                $registry,
                $templates
            );
        }

        return self::validateBoundTemplate(
            $val,
            $paramName,
            $function,
            $thisObj,
            $targetObjOrClass,
            $templateName,
            $templateNode,
            $isVariadic,
            $isClassLevelTemplate,
            $registry
        );
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function bindInitialTemplate(
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        ?object $targetObj,
        string $templateName,
        TemplateTagValueNode $templateNode,
        bool $isVariadic,
        bool $isClassLevelTemplate,
        TypeValidatorRegistry $registry,
        array $templates = []
    ): ?ErrorMessage {
        $sampleVal = ($isVariadic && \is_array($val)) ? ($val[0] ?? null) : $val;
        $inferredType = TemplateManager::inferTypeFromValue($sampleVal);

        if ($templateNode->bound !== null) {
            $boundNode = $templateNode->bound;
            $currentBindings = TemplateManager::getBoundTemplates($function, $thisObj, $templates);
            $allBindings = [...$currentBindings, $templateName => $inferredType];

            if (
                TemplateManager::isFBounded($templateNode, $function)
                || DocblockParser::typeReferencesTemplate($boundNode, $templates)
            ) {
                $boundNode = TemplateSubstitutor::substitute($boundNode, $allBindings);
            }

            $resolvedBound = SpecialTypeResolver::resolve($boundNode, $function, $thisObj);
            $inferredType = self::refineInferredTypeFromBound($sampleVal, $resolvedBound, $inferredType);

            $err = $registry->validate($sampleVal, $resolvedBound, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ')');
            if ($err !== null) {
                return $err;
            }
        }

        TemplateManager::bindTemplate($function, $targetObj, $templateName, $inferredType);

        if ($isVariadic && \is_array($val)) {
            return self::validateVariadicList(
                $val,
                $paramName,
                $function,
                $thisObj,
                $targetObj,
                $templateName,
                $templateNode,
                $inferredType,
                $isClassLevelTemplate,
                $registry
            );
        }

        return null;
    }

    private static function validateBoundTemplate(
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        object|string|null $targetObj,
        string $templateName,
        TemplateTagValueNode $templateNode,
        bool $isVariadic,
        bool $isClassLevelTemplate,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        $expectedTypeNode = TemplateManager::getBoundType($function, $targetObj, $templateName);
        if ($expectedTypeNode === null) {
            return null;
        }

        $targetObjOnly = \is_object($targetObj) ? $targetObj : null;

        if ($expectedTypeNode instanceof IdentifierTypeNode && $expectedTypeNode->name === $templateName) {
            $inferredType = TemplateManager::inferTypeFromValue($val);
            TemplateManager::bindTemplate($function, $targetObjOnly, $templateName, $inferredType);

            return null;
        }

        if ($isVariadic && \is_array($val)) {
            return self::validateVariadicList(
                $val,
                $paramName,
                $function,
                $thisObj,
                $targetObjOnly,
                $templateName,
                $templateNode,
                $expectedTypeNode,
                $isClassLevelTemplate,
                $registry
            );
        }

        $context = $function . '(): Argument $' . $paramName;
        $err = $registry->validate($val, $expectedTypeNode, $context . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')');

        if ($err !== null) {
            return self::tryWidenTemplate(
                $val,
                $expectedTypeNode,
                $templateNode,
                $isClassLevelTemplate,
                $function,
                $thisObj,
                $targetObjOnly,
                $templateName,
                $context,
                $err,
                $registry
            );
        }

        return null;
    }

    /**
     * @param array<mixed> $items
     */
    private static function validateVariadicList(
        array $items,
        string $paramName,
        string $function,
        ?object $thisObj,
        ?object $targetObj,
        string $templateName,
        TemplateTagValueNode $templateNode,
        TypeNode &$currentType,
        bool $isClassLevelTemplate,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        foreach ($items as $idx => $item) {
            $context = $function . '(): Argument $' . $paramName . '[' . $idx . ']';
            $err = $registry->validate($item, $currentType, $context . ' (template ' . $templateName . ' = ' . $currentType . ')');

            if ($err !== null) {
                $widenErr = self::tryWidenTemplate(
                    $item,
                    $currentType,
                    $templateNode,
                    $isClassLevelTemplate,
                    $function,
                    $thisObj,
                    $targetObj,
                    $templateName,
                    $context,
                    $err,
                    $registry
                );
                if ($widenErr !== null) {
                    return $widenErr;
                }
                $currentType = TemplateManager::getBoundType($function, $targetObj, $templateName) ?? $currentType;
            }
        }

        return null;
    }

    private static function tryWidenTemplate(
        mixed $val,
        TypeNode $currentType,
        TemplateTagValueNode $templateNode,
        bool $isClassLevelTemplate,
        string $function,
        ?object $thisObj,
        ?object $targetObj,
        string $templateName,
        string $context,
        ErrorMessage $originalError,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        if ($isClassLevelTemplate || $templateNode->bound === null || TemplateManager::isFBounded($templateNode, $function)) {
            return $originalError;
        }

        $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
        $boundErr = $registry->validate($val, $resolvedBound, $context . ' (template ' . $templateName . ')');

        if ($boundErr === null) {
            $newInferred = TemplateManager::inferTypeFromValue($val);
            $unifiedType = self::unifyTypes($currentType, $newInferred);
            TemplateManager::bindTemplate($function, $targetObj, $templateName, $unifiedType);

            return null;
        }

        return $originalError;
    }

    /**
     * Unifies two types into a combined UnionTypeNode, deduplicating identical member types.
     */
    private static function unifyTypes(TypeNode $type1, TypeNode $type2): TypeNode
    {
        if ((string) $type1 === (string) $type2) {
            return $type1;
        }

        $types = [];
        if ($type1 instanceof UnionTypeNode) {
            $types = $type1->types;
        } else {
            $types[] = $type1;
        }

        if ($type2 instanceof UnionTypeNode) {
            foreach ($type2->types as $t) {
                $types[] = $t;
            }
        } else {
            $types[] = $type2;
        }

        $unique = [];
        $deduped = [];
        foreach ($types as $t) {
            $str = (string) $t;
            if (! isset($unique[$str])) {
                $unique[$str] = true;
                $deduped[] = $t;
            }
        }

        return \count($deduped) === 1 ? $deduped[0] : new UnionTypeNode($deduped);
    }

    private static function typeContainsNull(TypeNode $node): bool
    {
        if ($node instanceof NullableTypeNode) {
            return true;
        }

        if ($node instanceof IdentifierTypeNode && strtolower($node->name) === 'null') {
            return true;
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $t) {
                if (self::typeContainsNull($t)) {
                    return true;
                }
            }
        }

        return false;
    }
}
