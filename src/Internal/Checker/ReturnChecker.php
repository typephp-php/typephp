<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;

/**
 * @internal Evaluates function and method return contract validations (including dynamic @method calls via __call / __callStatic).
 */
final class ReturnChecker
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
    public static function checkReturn(
        string $function,
        mixed $value,
        object|string|null $thisOrClass,
        array $vars,
        TypeValidatorRegistry $registry,
        callable $wrapIterableCallback
    ): mixed {
        if (! Config::isReturnsEnabled()) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = self::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        $magicResult = self::handleMagicReturn(
            $effectiveFunction,
            $value,
            $thisObj,
            $vars,
            $registry,
            $wrapIterableCallback
        );

        if ($magicResult !== null) {
            return $magicResult;
        }

        $contract = ContractParser::parse($effectiveFunction);

        if (! ($contract['hasReturnContract'] ?? ($contract['return'] !== null))) {
            return $value;
        }

        $returnTypeNode = $contract['return'];
        if ($returnTypeNode === null) {
            return $value;
        }

        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];

        return self::evaluateReturn(
            $returnTypeNode,
            $value,
            $effectiveFunction,
            $thisObj,
            $vars,
            $contract['aliases'] ?? [],
            $allTemplates,
            $registry,
            $wrapIterableCallback
        );
    }

    /**
     * Resolves the actual runtime class name vs trait name with O(1) memoization.
     */
    private static function resolveEffectiveFunction(string $function, object|string|null $thisOrClass, ?object $thisObj): string
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
     * Intercepts and evaluates return contracts for dynamic @method calls routed via __call and __callStatic.
     *
     * @param array<string, mixed> $vars
     */
    private static function handleMagicReturn(
        string $effectiveFunction,
        mixed $value,
        ?object $thisObj,
        array $vars,
        TypeValidatorRegistry $registry,
        callable $wrapIterableCallback
    ): mixed {
        if (! Config::isMagicMethodsEnabled()) {
            return null;
        }

        if (! str_ends_with($effectiveFunction, '::__call') && ! str_ends_with($effectiveFunction, '::__callStatic')) {
            return null;
        }

        $magicMethodName = array_values($vars)[0] ?? null;
        $rawMagicArgs = array_values($vars)[1] ?? [];
        /** @var array<int|string, mixed> $magicArgs */
        $magicArgs = \is_array($rawMagicArgs) ? $rawMagicArgs : [];

        if (! \is_string($magicMethodName)) {
            return null;
        }

        $className = explode('::', $effectiveFunction, 2)[0];
        $magicContract = ContractParser::parseMagicMethod($className, $magicMethodName);

        if ($magicContract === null || $magicContract['return'] === null) {
            return null;
        }

        $magicFunction = $className . '::' . $magicMethodName;

        return self::evaluateReturn(
            $magicContract['return'],
            $value,
            $magicFunction,
            $thisObj,
            $magicArgs,
            $magicContract['aliases'] ?? [],
            $magicContract['templates'] ?? [],
            $registry,
            $wrapIterableCallback
        );
    }

    /**
     * Unified return value validation pipeline.
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $aliases
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function evaluateReturn(
        TypeNode $returnTypeNode,
        mixed $value,
        string $function,
        ?object $thisObj,
        array $vars,
        array $aliases,
        array $templates,
        TypeValidatorRegistry $registry,
        callable $wrapIterableCallback
    ): mixed {
        $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $function);
        if ($err !== null) {
            return $err;
        }

        $hasGenerics = (\count($templates) > 0);
        $hasAliases = (\count($aliases) > 0);
        $isConditional = ($returnTypeNode instanceof ConditionalTypeForParameterNode || $returnTypeNode instanceof ConditionalTypeNode);

        if (! $hasGenerics && ! $hasAliases && ! $isConditional && ! ($returnTypeNode instanceof CallableTypeNode)) {
            $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
            $err = $registry->validate($value, $resolvedType, $function . '(): Return value');
            if ($err !== null) {
                return $err;
            }

            if ($value instanceof \Traversable) {
                $baseName = '';
                if ($resolvedType instanceof IdentifierTypeNode) {
                    $baseName = strtolower(ltrim($resolvedType->name, '\\'));
                } elseif ($resolvedType instanceof GenericTypeNode) {
                    $baseName = strtolower(ltrim($resolvedType->type->name, '\\'));
                }

                $genericIterables = ['iterable', 'traversable', 'iterator', 'generator'];
                if (\in_array($baseName, $genericIterables, true)) {
                    return $wrapIterableCallback($function, 'return', $value);
                }
            }

            return $value;
        }

        $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

        if ($resolvedType instanceof IdentifierTypeNode && isset($aliases[$resolvedType->name])) {
            $resolvedType = $aliases[$resolvedType->name];
        }

        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);

        if (\count($boundTemplates) > 0 || \count($templates) > 0) {
            $resolvedType = TemplateSubstitutor::substitute($resolvedType, $boundTemplates, $templates);
            $resolvedType = SpecialTypeResolver::resolve($resolvedType, $function, $thisObj);
        }

        $resolvedType = self::resolveConditionalReturnType($resolvedType, $vars, $boundTemplates, $registry, $function);

        $err = $registry->validate($value, $resolvedType, $function . '(): Return value');
        if ($err !== null) {
            return $err;
        }

        if (\is_callable($value) && $resolvedType instanceof CallableTypeNode) {
            return CallableWrapper::wrapTypeNode($resolvedType, $value, $function . '(): Return value', $registry);
        }

        if ($value instanceof \Traversable) {
            $baseName = '';
            if ($resolvedType instanceof IdentifierTypeNode) {
                $baseName = strtolower(ltrim($resolvedType->name, '\\'));
            } elseif ($resolvedType instanceof GenericTypeNode) {
                $baseName = strtolower(ltrim($resolvedType->type->name, '\\'));
            }

            $genericIterables = ['iterable', 'traversable', 'iterator', 'generator'];
            if (\in_array($baseName, $genericIterables, true)) {
                return $wrapIterableCallback($function, 'return', $value);
            }
        }

        return $value;
    }

    /**
     * Recursively resolves multi-branch nested conditional return types.
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    private static function resolveConditionalReturnType(
        TypeNode $returnTypeNode,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry,
        string $function = ''
    ): TypeNode {
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            return self::resolveParameterConditional($returnTypeNode, $vars, $boundTemplates, $registry, $function);
        }

        if ($returnTypeNode instanceof ConditionalTypeNode) {
            return self::resolveTemplateConditional($returnTypeNode, $vars, $boundTemplates, $registry);
        }

        return $returnTypeNode;
    }

    /**
     * Resolves parameter-based conditional return types ($param is Target ? If : Else).
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    private static function resolveParameterConditional(
        ConditionalTypeForParameterNode $node,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry,
        string $function = ''
    ): TypeNode {
        $paramName = ltrim($node->parameterName, '$');
        $paramValue = null;

        if (\array_key_exists($paramName, $vars)) {
            $paramValue = $vars[$paramName];
        } elseif (\count($vars) > 0 && $function !== '' && str_contains($function, '::')) {
            $paramValue = self::resolveRenamedParamValue($function, $paramName, $vars);
        }

        $targetErr = $registry->validate($paramValue, $node->targetType, 'condition');
        $isTargetMatch = ($targetErr === null);

        if ($node->negated) {
            $isTargetMatch = ! $isTargetMatch;
        }

        $selectedBranch = $isTargetMatch ? $node->if : $node->else;

        return self::resolveConditionalReturnType($selectedBranch, $vars, $boundTemplates, $registry, $function);
    }

    /**
     * Disambiguates parameter value by positional index in method hierarchy when renamed in child class.
     *
     * @param array<int|string, mixed> $vars
     */
    private static function resolveRenamedParamValue(string $function, string $paramName, array $vars): mixed
    {
        [$className, $methodName] = explode('::', $function, 2);

        if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className) && ! enum_exists($className)) {
            return null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);
            if (! $refClass->hasMethod($methodName)) {
                return null;
            }

            $refMethod = $refClass->getMethod($methodName);
            $hierarchy = HierarchyResolver::getMethodHierarchy($refMethod);

            $targetIndex = null;
            foreach ($hierarchy as $hierMethod) {
                foreach ($hierMethod->getParameters() as $idx => $p) {
                    if ($p->getName() === $paramName) {
                        $targetIndex = $idx;

                        break 2;
                    }
                }
            }

            if ($targetIndex !== null) {
                $values = array_values($vars);
                if (\array_key_exists($targetIndex, $values)) {
                    return $values[$targetIndex];
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection errors
        }

        return null;
    }

    /**
     * Resolves template-based conditional return types (T is Target ? If : Else).
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    private static function resolveTemplateConditional(
        ConditionalTypeNode $node,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry
    ): TypeNode {
        $subjectTypeNode = $node->subjectType;

        if ($subjectTypeNode instanceof IdentifierTypeNode && isset($boundTemplates[$subjectTypeNode->name])) {
            $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
        }

        $subStr = (string) $subjectTypeNode;
        $targetStr = (string) $node->targetType;

        $isTargetMatch = ($subStr === $targetStr);
        if (! $isTargetMatch) {
            $isTargetMatch = ClassNameValidator::isValid($subStr) && ClassNameValidator::isValid($targetStr) &&
                (class_exists($subStr) || interface_exists($subStr)) &&
                (class_exists($targetStr) || interface_exists($targetStr)) &&
                is_a($subStr, $targetStr, true);
        }

        if ($node->negated) {
            $isTargetMatch = ! $isTargetMatch;
        }

        $selectedBranch = $isTargetMatch ? $node->if : $node->else;

        return self::resolveConditionalReturnType($selectedBranch, $vars, $boundTemplates, $registry);
    }
}
