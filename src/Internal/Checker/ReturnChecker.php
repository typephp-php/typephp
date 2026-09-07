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
use ReflectionClass;
use Traversable;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

/**
 * @internal Evaluates function and method return contract validations (including dynamic @method calls via __call / __callStatic).
 */
final class ReturnChecker
{
    /**
     * O(1) Fast-path cache for methods determined to have no return contracts.
     *
     * @var array<string, true>
     */
    public static array $noReturnContractCache = [];

    /**
     * In-memory cache for unbound generic return types.
     *
     * @var array<string, TypeNode>
     */
    public static array $unboundReturnCache = [];

    /**
     * Memoized static return type resolutions for non-dynamic, non-generic methods.
     *
     * @var array<string, TypeNode>
     */
    private static array $resolvedStaticReturnCache = [];

    /**
     * In-memory cache for concrete substituted generic return types.
     *
     * @var array<string, TypeNode>
     */
    public static array $substitutedReturnCache = [];

    /**
     * Cache for whether a return type is unconstrained (mixed or array).
     *
     * @var array<string, bool>
     */
    private static array $returnUnconstrainedCache = [];

    /**
     * Resets internal caches. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$noReturnContractCache = [];
        self::$resolvedStaticReturnCache = [];
        self::$unboundReturnCache = [];
        self::$substitutedReturnCache = [];
        self::$returnUnconstrainedCache = [];
    }

    /**
     * Checks if the return type of a function is unconstrained (mixed or array).
     * Uses memoization to avoid repeated docblock parsing.
     */
    public static function isReturnUnconstrained(string $effectiveFunction): bool
    {
        if (str_contains($effectiveFunction, '__call')) {
            return false;
        }

        $cacheKey = $effectiveFunction . '|return_unconstrained';
        if (! isset(self::$returnUnconstrainedCache[$cacheKey])) {
            $contract = DocblockParser::parse($effectiveFunction);
            $returnNode = $contract['return'] ?? null;
            $unconstrained = false;
            if ($returnNode instanceof IdentifierTypeNode) {
                $lower = strtolower($returnNode->name);
                if ($lower === 'mixed' || $lower === 'array') {
                    $unconstrained = true;
                }
            }
            self::$returnUnconstrainedCache[$cacheKey] = $unconstrained;
        }

        return self::$returnUnconstrainedCache[$cacheKey];
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

        if (isset(self::$noReturnContractCache[$function])) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (isset(self::$noReturnContractCache[$effectiveFunction])) {
            self::$noReturnContractCache[$function] = true;

            return $value;
        }

        $isMagicCall = str_contains($effectiveFunction, '__call');

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

        if ($isMagicCall) {
            return $value;
        }

        $contract = DocblockParser::parse($effectiveFunction);

        if (! ($contract['hasReturnContract'] ?? ($contract['return'] !== null))) {
            self::$noReturnContractCache[$effectiveFunction] = true;
            self::$noReturnContractCache[$function] = true;

            return $value;
        }

        $returnTypeNode = $contract['return'];
        if ($returnTypeNode === null) {
            self::$noReturnContractCache[$effectiveFunction] = true;
            self::$noReturnContractCache[$function] = true;

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
            $wrapIterableCallback,
            $contract
        );
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
        $magicContract = DocblockParser::parseMagicMethod($className, $magicMethodName);

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
        callable $wrapIterableCallback,
        array $contract = []
    ): mixed {
        if ($contract['returnIsThis'] ?? false) {
            $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $function);
            if ($err !== null) {
                return $err;
            }
        }

        $hasGenerics = (\count($templates) > 0);
        $hasAliases = (\count($aliases) > 0);
        $isParamConditional = ($returnTypeNode instanceof ConditionalTypeForParameterNode);

        if (! $hasGenerics && ! $hasAliases && ! $isParamConditional && ! ($returnTypeNode instanceof CallableTypeNode)) {
            $isDynamic = $contract['returnIsDynamic'] ?? (str_contains((string) $returnTypeNode, 'static') || str_contains((string) $returnTypeNode, '$this'));

            if (! $isDynamic) {
                $resolvedType = self::$resolvedStaticReturnCache[$function] ??= SpecialTypeResolver::resolve($returnTypeNode, $function, null);
            } else {
                $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
            }

            // Fast-path: if return type is mixed or array, skip validation entirely.
            if ($resolvedType instanceof IdentifierTypeNode) {
                $lower = strtolower($resolvedType->name);
                if ($lower === 'mixed' || $lower === 'array') {
                    return $value;
                }
            }

            $err = $registry->validate($value, $resolvedType, 'Return value');
            if ($err !== null) {
                return ErrorFactory::createError($function . '(): ' . $err->getMessage());
            }

            if ($value instanceof Traversable) {
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

        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);

        $cacheKey = null;
        if (\count($boundTemplates) <= 2 && ! $isParamConditional && \count($aliases) === 0 && $thisObj === null) {
            $cacheKey = $function;
            foreach ($boundTemplates as $k => $v) {
                $cacheKey .= '|' . $k . ':' . ($v instanceof IdentifierTypeNode ? $v->name : (string) $v);
            }
            if (isset(self::$substitutedReturnCache[$cacheKey])) {
                $resolvedType = self::$substitutedReturnCache[$cacheKey];
            }
        }

        if (! isset($resolvedType)) {
            $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

            if ($resolvedType instanceof IdentifierTypeNode && isset($aliases[$resolvedType->name])) {
                $resolvedType = $aliases[$resolvedType->name];
            }

            if (\count($boundTemplates) === 0 && \count($templates) > 0 && ! $isParamConditional && $thisObj === null) {
                $resolvedType = self::$unboundReturnCache[$function] ??= SpecialTypeResolver::resolve(
                    TemplateSubstitutor::substitute($returnTypeNode, [], $templates),
                    $function,
                    null
                );
            } elseif (\count($boundTemplates) > 0 || \count($templates) > 0) {
                $resolvedType = TemplateSubstitutor::substitute($resolvedType, $boundTemplates, $templates);
                $resolvedType = SpecialTypeResolver::resolve($resolvedType, $function, $thisObj);
            }

            $resolvedType = self::resolveConditionalReturnType($resolvedType, $vars, $boundTemplates, $registry, $function);

            if ($cacheKey !== null) {
                self::$substitutedReturnCache[$cacheKey] = $resolvedType;
            }
        }

        // Fast-path: if return type is mixed or array, skip validation.
        if ($resolvedType instanceof IdentifierTypeNode) {
            $lower = strtolower($resolvedType->name);
            if ($lower === 'mixed' || $lower === 'array') {
                return $value;
            }
        }

        $err = $registry->validate($value, $resolvedType, 'Return value');
        if ($err !== null) {
            return ErrorFactory::createError($function . '(): ' . $err->getMessage());
        }

        if ($resolvedType instanceof CallableTypeNode && CallableWrapper::isCallable($value)) {
            return CallableWrapper::wrapTypeNode($resolvedType, $value, $function . '(): Return value', $registry);
        }

        if ($value instanceof Traversable) {
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

        if (isset($vars[$paramName]) || \array_key_exists($paramName, $vars)) {
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
            $refClass = new ReflectionClass($className);
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
                if (isset($values[$targetIndex]) || \array_key_exists($targetIndex, $values)) {
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

        $isTargetMatch = TemplateManager::checkVariance($subjectTypeNode, $node->targetType, GenericTypeNode::VARIANCE_COVARIANT);

        if ($node->negated) {
            $isTargetMatch = ! $isTargetMatch;
        }

        $selectedBranch = $isTargetMatch ? $node->if : $node->else;

        return self::resolveConditionalReturnType($selectedBranch, $vars, $boundTemplates, $registry);
    }
}
