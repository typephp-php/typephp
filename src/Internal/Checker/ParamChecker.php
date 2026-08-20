<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
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
        if (\count($contract['types']) === 0) {
            return null;
        }

        $methodTemplates = $contract['templates'];
        $classTemplates = $contract['classTemplates'] ?? [];
        $aliases = $contract['aliases'];

        if (\count($methodTemplates) > 0) {
            TemplateManager::clearCallBindings($effectiveFunction, $methodTemplates);
        }

        if ($thisObj !== null && \count($classTemplates) > 0 && str_contains($effectiveFunction, '::')) {
            $declaringClass = explode('::', $effectiveFunction, 2)[0];
            TemplateManager::resolveInheritedTemplates($thisObj, $declaringClass);
        }

        $allTemplates = [...$classTemplates, ...$methodTemplates];
        self::preInferGenericArrayTemplates($contract['types'], $vars, $effectiveFunction, $thisObj, $allTemplates);

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
     * Resolves the actual runtime class name vs trait name and matches any active trait aliases.
     */
    private static function resolveEffectiveFunction(string $function, object|string|null $thisOrClass, ?object $thisObj): string
    {
        if (! str_contains($function, '::')) {
            return $function;
        }

        [$classOrTrait, $methodName] = explode('::', $function, 2);
        $actualClassName = \is_object($thisOrClass) ? \get_class($thisOrClass) : (\is_string($thisOrClass) ? $thisOrClass : null);

        $effectiveFunction = ($actualClassName !== null && $actualClassName !== $classOrTrait)
            ? $actualClassName . '::' . $methodName
            : $function;

        if ($thisObj !== null) {
            $targetClass = $actualClassName ?? $classOrTrait;
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
                            return $targetClass . '::' . $frameFunc;
                        }
                    }
                }
            }
        }

        return $effectiveFunction;
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
                $boundName = $resolvedBound instanceof IdentifierTypeNode ? $resolvedBound->name : (string) $resolvedBound;
                $lowerBound = strtolower($boundName);

                if ($lowerBound !== 'object' && $lowerBound !== 'mixed' && ! is_a($val, $boundName, true)) {
                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundName . ", '" . $val . "' given");
                }
            }

            TemplateManager::bindTemplate($function, $targetObj, $templateName, new IdentifierTypeNode($val));
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $targetObj, $templateName);
            $targetClass = $expectedTypeNode instanceof IdentifierTypeNode ? $expectedTypeNode->name : (string) $expectedTypeNode;

            if (! \is_string($val) || ! is_a($val, $targetClass, true)) {
                $valStr = TypeFormatter::formatGivenValue($val);

                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetClass . ', ' . $valStr . ' given');
            }
        }

        return null;
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
