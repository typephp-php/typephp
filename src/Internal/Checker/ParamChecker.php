<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates function and method parameter contract validations (including dynamic @method calls via __call / __callStatic).
 */
final class ParamChecker
{
    private const HYBRID_SAMPLE_THRESHOLD = 128;

    /**
     * @var array<string, string>
     */
    private static array $effectiveFunctionCache = [];

    /**
     * Resets the effective function cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$effectiveFunctionCache = [];
    }

    /**
     * @param array<string, mixed> $vars
     */
    public static function checkParams(
        string $function,
        array $vars,
        object|string|null $thisOrClass,
        TypeValidatorRegistry $registry,
        string $effectiveFunction = ''
    ): ?ErrorMessage {
        if (! Config::isParamsEnabled()) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        if ($effectiveFunction === '') {
            $effectiveFunction = self::resolveEffectiveFunction($function, $thisOrClass, $thisObj);
        }

        $magicError = self::handleMagicCall($effectiveFunction, $vars, $thisObj, $registry);
        if ($magicError !== null) {
            return $magicError;
        }

        $contract = ContractParser::parse($effectiveFunction);

        if (! $contract['hasParamContract']) {
            return null;
        }

        $methodTemplates = $contract['templates'];
        $classTemplates = $contract['classTemplates'] ?? [];
        $aliases = $contract['aliases'];
        $hasGenerics = (\count($methodTemplates) > 0 || \count($classTemplates) > 0);

        if (! $hasGenerics && \count($aliases) === 0) {
            foreach ($contract['types'] as $paramName => $typeNode) {
                if (\array_key_exists($paramName, $vars)) {
                    $err = $registry->validate($vars[$paramName], $typeNode, $effectiveFunction . '(): Argument $' . $paramName);
                    if ($err !== null) {
                        return $err;
                    }
                }
            }

            return null;
        }

        if (\count($methodTemplates) > 0) {
            TemplateManager::clearCallBindings($effectiveFunction, $methodTemplates);
        }

        if ($thisObj !== null && \count($classTemplates) > 0 && str_contains($effectiveFunction, '::')) {
            $declaringClass = explode('::', $effectiveFunction, 2)[0];
            TemplateManager::resolveInheritedTemplates($thisObj, $declaringClass);
        }

        $allTemplates = [...$classTemplates, ...$methodTemplates];
        if (\count($allTemplates) > 0) {
            self::preInferGenericTemplates($contract['types'], $vars, $effectiveFunction, $thisObj, $allTemplates);
        }

        $boundTemplates = TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $allTemplates);
        $declaredTemplates = $allTemplates;

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! \array_key_exists($paramName, $vars)) {
                continue;
            }

            $err = self::validateSingleParam(
                $paramName,
                $typeNode,
                $vars[$paramName],
                $effectiveFunction,
                $thisObj,
                $allTemplates,
                $aliases,
                $boundTemplates,
                $declaredTemplates,
                $registry
            );

            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * Resolves the actual runtime class name vs trait name with O(1) memoization.
     */
    public static function resolveEffectiveFunction(string $function, object|string|null $thisOrClass, ?object $thisObj = null): string
    {
        if (! str_contains($function, '::')) {
            return $function;
        }

        $actualClassName = \is_object($thisOrClass) ? \get_class($thisOrClass) : (\is_string($thisOrClass) ? $thisOrClass : '');
        if ($actualClassName === '') {
            return $function;
        }

        [$classOrTrait, $methodName] = explode('::', $function, 2);

        $cacheKey = $function . '|' . $actualClassName;
        if (isset(self::$effectiveFunctionCache[$cacheKey])) {
            return self::$effectiveFunctionCache[$cacheKey];
        }

        $effectiveFunction = ($actualClassName !== $classOrTrait)
            ? $actualClassName . '::' . $methodName
            : $function;

        if ($thisObj !== null) {
            $targetClass = $actualClassName;
            $traitAliases = HierarchyResolver::getTraitAliases($targetClass);

            if (\count($traitAliases) > 0) {
                $isPotentialAlias = isset($traitAliases[$methodName]);
                if (! $isPotentialAlias) {
                    foreach ($traitAliases as $originalTarget) {
                        if (str_ends_with($originalTarget, '::' . $methodName)) {
                            $isPotentialAlias = true;

                            break;
                        }
                    }
                }

                if ($isPotentialAlias) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                    foreach ($trace as $frame) {
                        $frameFunc = $frame['function'];
                        $frameClass = $frame['class'] ?? '';
                        if (($frameClass === $actualClassName || $frameClass === $classOrTrait) && isset($traitAliases[$frameFunc])) {
                            return self::$effectiveFunctionCache[$cacheKey] = $targetClass . '::' . $frameFunc;
                        }
                    }
                }
            }
        }

        return self::$effectiveFunctionCache[$cacheKey] = $effectiveFunction;
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
        $magicContract = ContractParser::parseMagicMethod($className, $magicMethodName);

        if ($magicContract === null) {
            return null;
        }

        $magicFunction = $className . '::' . $magicMethodName;

        return self::validateMagicArguments($magicContract, $magicArgs, $magicFunction, $thisObj, $registry);
    }

    /**
     * Pre-infers generic template parameters from closure typehints and array arguments.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function preInferGenericTemplates(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        self::inferTemplatesFromClosures($types, $vars, $effectiveFunction, $thisObj, $templates);
        self::inferTemplatesFromArrays($types, $vars, $effectiveFunction, $thisObj, $templates);
    }

    /**
     * Infers generic template parameters from closure parameter typehints.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function inferTemplatesFromClosures(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        $callableNodes = self::extractCallableNodes($types);
        if (\count($callableNodes) === 0) {
            return;
        }

        $contract = ContractParser::parse($effectiveFunction);
        $classTemplates = $contract['classTemplates'] ?? [];

        foreach ($callableNodes as $cParamName => $cTypeNode) {
            if (! \array_key_exists($cParamName, $vars) || ! ($vars[$cParamName] instanceof \Closure)) {
                continue;
            }

            try {
                $refClosure = new \ReflectionFunction($vars[$cParamName]);
                $closureParams = $refClosure->getParameters();

                foreach ($cTypeNode->parameters as $idx => $pNode) {
                    if ($pNode->type instanceof IdentifierTypeNode && isset($templates[$pNode->type->name]) && isset($closureParams[$idx])) {
                        $tName = $pNode->type->name;
                        $isClassLevel = isset($classTemplates[$tName]);
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
            } catch (\Throwable $e) {
                // Silently ignore reflection errors
            }
        }
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
            if ($cTypeName !== 'mixed' && (class_exists($cTypeName) || interface_exists($cTypeName) || SpecialTypeResolver::isBuiltInTypeKeyword($cTypeName))) {
                return new IdentifierTypeNode($cTypeName);
            }
        } elseif ($cType instanceof \ReflectionUnionType) {
            $unionSubTypes = [];
            foreach ($cType->getTypes() as $subNamedType) {
                if ($subNamedType instanceof \ReflectionNamedType) {
                    $subName = $subNamedType->getName();
                    if ($subName !== 'mixed' && (class_exists($subName) || interface_exists($subName) || SpecialTypeResolver::isBuiltInTypeKeyword($subName))) {
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
     */
    private static function inferTemplatesFromArrays(
        array $types,
        array $vars,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        foreach ($types as $paramName => $typeNode) {
            if (! \array_key_exists($paramName, $vars) || ! \is_array($vars[$paramName]) || \count($vars[$paramName]) === 0) {
                continue;
            }

            self::inferArrayTemplatesFromAllElements($typeNode, $vars[$paramName], $effectiveFunction, $thisObj, $templates);
        }
    }

    /**
     * Extracts and unifies template parameters across all elements of an array argument.
     * Uses Beartype O(1) hybrid random sampling on arrays > 128 items when hybrid mode is active.
     *
     * @param array<mixed> $arrVal
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function inferArrayTemplatesFromAllElements(
        TypeNode $typeNode,
        array $arrVal,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        if ($typeNode instanceof NullableTypeNode) {
            $typeNode = $typeNode->type;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseType = strtolower($typeNode->type->name);
            if (! \in_array($baseType, ['array', 'list', 'iterable', 'traversable'], true)) {
                return;
            }

            $sampleItems = self::getSampleArraySlice($arrVal);
            $genericCount = \count($typeNode->genericTypes);

            if ($genericCount === 1 && $typeNode->genericTypes[0] instanceof IdentifierTypeNode) {
                self::inferSingleTemplateFromArraySamples($typeNode->genericTypes[0]->name, $sampleItems, $effectiveFunction, $thisObj, $templates);
            } elseif ($genericCount >= 2) {
                $keyTName = $typeNode->genericTypes[0] instanceof IdentifierTypeNode ? $typeNode->genericTypes[0]->name : null;
                $valTName = $typeNode->genericTypes[1] instanceof IdentifierTypeNode ? $typeNode->genericTypes[1]->name : null;

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
                    self::bindTemplateIfUnbound($keyTName, $inferredKeyType, $effectiveFunction, $thisObj, $templates);
                }
                if ($valTName !== null && $inferredValType !== null) {
                    self::bindTemplateIfUnbound($valTName, $inferredValType, $effectiveFunction, $thisObj, $templates);
                }
            }
        }
    }

    /**
     * @param array<mixed> $sampleItems
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function inferSingleTemplateFromArraySamples(
        string $templateName,
        array $sampleItems,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        $inferredType = null;

        foreach ($sampleItems as $item) {
            $itemType = TemplateManager::inferTypeFromValue($item);
            $inferredType = ($inferredType === null) ? $itemType : self::unifyTypes($inferredType, $itemType);
        }

        if ($inferredType !== null) {
            self::bindTemplateIfUnbound($templateName, $inferredType, $effectiveFunction, $thisObj, $templates);
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
        if ($count <= self::HYBRID_SAMPLE_THRESHOLD || ! Config::isArrayValidationHybrid()) {
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
     * Binds a template parameter if it is not already bound in the current scope.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function bindTemplateIfUnbound(
        string $templateName,
        TypeNode $inferredType,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        $contract = ContractParser::parse($effectiveFunction);
        $classTemplates = $contract['classTemplates'] ?? [];
        $isClassLevelTemplate = isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (isset($templates[$templateName]) && ! TemplateManager::isBound($effectiveFunction, $targetObj, $templateName)) {
            TemplateManager::bindTemplate($effectiveFunction, $targetObj, $templateName, $inferredType);
        }
    }

    /**
     * Unified single-parameter validation pipeline.
     *
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $aliases
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
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
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $isClassStringT = ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates));
        $isBareTemplate = self::getTemplateName($typeNode, $templates) !== null;
        $shouldSkipTemplateSub = $isBareTemplate || $isClassStringT;

        if (! $shouldSkipTemplateSub && (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0)) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $declaredTemplates);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);
        }

        if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
            return self::resolveClassStringTemplate($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates);
        }

        if (self::getTemplateName($typeNode, $templates) !== null) {
            return self::resolveTemplateParam($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates, $registry);
        }

        return $registry->validate($val, $typeNode, $effectiveFunction . '(): Argument $' . $paramName);
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
                $registry
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
            && strtolower($typeNode->type->name) === 'class-string'
            && isset($templates[$typeNode->genericTypes[0]->name]);
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveClassStringTemplate(
        GenericTypeNode $typeNode,
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        array $templates
    ): ?ErrorMessage {
        /** @var IdentifierTypeNode $innerType */
        $innerType = $typeNode->genericTypes[0];
        $templateName = $innerType->name;
        $templateNode = $templates[$templateName];

        $contract = ContractParser::parse($function);
        $classTemplates = $contract['classTemplates'] ?? [];
        $isClassLevelTemplate = isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (! TemplateManager::isBound($function, $targetObj, $templateName)) {
            if (! \is_string($val) || ! ClassNameValidator::isValidClassString($val)) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
            }

            if ($templateNode->bound !== null) {
                $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
                if (! self::checkClassStringSatisfiesBound($val, $resolvedBound)) {
                    $boundDisplay = (string) $resolvedBound;

                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundDisplay . ", '" . $val . "' given");
                }
            }

            TemplateManager::bindTemplate($function, $targetObj, $templateName, new IdentifierTypeNode($val));
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $targetObj, $templateName);
            if ($expectedTypeNode !== null) {
                if (! \is_string($val) || ! self::checkClassStringSatisfiesBound($val, $expectedTypeNode)) {
                    $valStr = TypeFormatter::formatGivenValue($val);
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

        if ($typeNode instanceof NullableTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
        }

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
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
     */
    private static function resolveTemplateParam(
        TypeNode $typeNode,
        mixed $val,
        string $paramName,
        string $function,
        ?object $thisObj,
        array $templates,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        $templateName = self::getTemplateName($typeNode, $templates);
        if ($templateName === null || ! isset($templates[$templateName])) {
            return null;
        }

        $templateNode = $templates[$templateName];
        $isVariadic = $typeNode instanceof ArrayTypeNode;
        $isNullable = ($typeNode instanceof NullableTypeNode) || ($typeNode instanceof UnionTypeNode && self::typeContainsNull($typeNode));

        if ($isNullable && $val === null) {
            return null;
        }

        $contract = ContractParser::parse($function);
        $classTemplates = $contract['classTemplates'] ?? [];
        $isClassLevelTemplate = isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (! TemplateManager::isBound($function, $targetObj, $templateName)) {
            return self::bindInitialTemplate(
                $val,
                $paramName,
                $function,
                $thisObj,
                $targetObj,
                $templateName,
                $templateNode,
                $isVariadic,
                $isClassLevelTemplate,
                $registry
            );
        }

        return self::validateBoundTemplate(
            $val,
            $paramName,
            $function,
            $thisObj,
            $targetObj,
            $templateName,
            $templateNode,
            $isVariadic,
            $isClassLevelTemplate,
            $registry
        );
    }

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
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        $sampleVal = ($isVariadic && \is_array($val)) ? ($val[0] ?? null) : $val;
        $inferredType = TemplateManager::inferTypeFromValue($sampleVal);

        if ($templateNode->bound !== null) {
            $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
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
        ?object $targetObj,
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

        if ($expectedTypeNode instanceof IdentifierTypeNode && $expectedTypeNode->name === $templateName) {
            $inferredType = TemplateManager::inferTypeFromValue($val);
            TemplateManager::bindTemplate($function, $targetObj, $templateName, $inferredType);

            return null;
        }

        if ($isVariadic && \is_array($val)) {
            return self::validateVariadicList(
                $val,
                $paramName,
                $function,
                $thisObj,
                $targetObj,
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
                $targetObj,
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
        if ($isClassLevelTemplate || $templateNode->bound === null) {
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

        return $boundErr;
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
