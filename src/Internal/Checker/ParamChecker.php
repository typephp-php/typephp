<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
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
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        if (! Config::isParamsEnabled()) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = self::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

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
            self::preInferGenericArrayTemplates($contract['types'], $vars, $effectiveFunction, $thisObj, $allTemplates);
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
        $cacheKey = $function . '|' . $actualClassName;

        if (isset(self::$effectiveFunctionCache[$cacheKey])) {
            return self::$effectiveFunctionCache[$cacheKey];
        }

        [$classOrTrait, $methodName] = explode('::', $function, 2);

        $effectiveFunction = ($actualClassName !== '' && $actualClassName !== $classOrTrait)
            ? $actualClassName . '::' . $methodName
            : $function;

        if ($thisObj !== null) {
            $targetClass = $actualClassName !== '' ? $actualClassName : $classOrTrait;
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
        $isMagicCall = str_ends_with($effectiveFunction, '::__call') || str_ends_with($effectiveFunction, '::__callStatic');
        if (! $isMagicCall || ! Config::isMagicMethodsEnabled()) {
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
     * Pre-infers generic template parameters from array arguments before callback wrapping.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, mixed> $vars
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function preInferGenericArrayTemplates(
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

            $arrVal = $vars[$paramName];
            $sampleKey = array_key_first($arrVal);
            $sampleItem = reset($arrVal);

            self::inferFromTypeNode($typeNode, $sampleKey, $sampleItem, $effectiveFunction, $thisObj, $templates);
        }
    }

    /**
     * Extracts and binds template parameters from GenericTypeNode or ArrayTypeNode.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function inferFromTypeNode(
        TypeNode $typeNode,
        mixed $sampleKey,
        mixed $sampleItem,
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

            $genericCount = \count($typeNode->genericTypes);
            if ($genericCount === 1 && $typeNode->genericTypes[0] instanceof IdentifierTypeNode) {
                self::bindTemplateIfUnbound($typeNode->genericTypes[0]->name, $sampleItem, $effectiveFunction, $thisObj, $templates);
            } elseif ($genericCount >= 2) {
                if ($typeNode->genericTypes[0] instanceof IdentifierTypeNode) {
                    self::bindTemplateIfUnbound($typeNode->genericTypes[0]->name, $sampleKey, $effectiveFunction, $thisObj, $templates);
                }
                if ($typeNode->genericTypes[1] instanceof IdentifierTypeNode) {
                    self::bindTemplateIfUnbound($typeNode->genericTypes[1]->name, $sampleItem, $effectiveFunction, $thisObj, $templates);
                }
            }
        } elseif ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode) {
            self::bindTemplateIfUnbound($typeNode->type->name, $sampleItem, $effectiveFunction, $thisObj, $templates);
        }
    }

    /**
     * Binds a template parameter if it is not already bound in the current scope.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function bindTemplateIfUnbound(
        string $templateName,
        mixed $sampleValue,
        string $effectiveFunction,
        ?object $thisObj,
        array $templates
    ): void {
        $contract = ContractParser::parse($effectiveFunction);
        $classTemplates = $contract['classTemplates'] ?? [];
        $isClassLevelTemplate = isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (isset($templates[$templateName]) && ! TemplateManager::isBound($effectiveFunction, $targetObj, $templateName)) {
            TemplateManager::bindTemplate($effectiveFunction, $targetObj, $templateName, TemplateManager::inferTypeFromValue($sampleValue));
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

        $isBareTemplate = ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name]))
            || ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name]));

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

            $val = null;
            $hasVal = false;

            if ($isVariadic) {
                if (\array_key_exists($paramName, $args)) {
                    $val = [$args[$paramName]];
                    $hasVal = true;
                } else {
                    $val = [];
                    for ($i = $index; $i < \count($argValues); $i++) {
                        if (\is_int($argKeys[$i])) {
                            $val[] = $argValues[$i];
                            $hasVal = true;
                        }
                    }
                }
                $typeNode = new ArrayTypeNode($typeNode);
            } else {
                if (\array_key_exists($paramName, $args)) {
                    $val = $args[$paramName];
                    $hasVal = true;
                } elseif (\array_key_exists($index, $argValues)) {
                    $val = $argValues[$index];
                    $hasVal = true;
                }
            }

            if (! $hasVal) {
                continue;
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
            if (! \is_string($val) || ! ClassNameValidator::isValid($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
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

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
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

        $contract = ContractParser::parse($function);
        $classTemplates = $contract['classTemplates'] ?? [];
        $isClassLevelTemplate = isset($classTemplates[$templateName]);
        $targetObj = $isClassLevelTemplate ? $thisObj : null;

        if (! TemplateManager::isBound($function, $targetObj, $templateName)) {
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
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $inferredType, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $inferredType . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        } else {
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
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $expectedTypeNode, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            } else {
                $err = $registry->validate($val, $expectedTypeNode, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return null;
    }
}
