<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Traversable;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;

/**
 * Evaluates function and method return contracts, including dynamic `@method` returns
 * (`__call` / `__callStatic`) and dynamic `@property` reads (`__get`).
 *
 * Performance & Mechanical Sympathy Architecture:
 * Serves the fallback path for generic, aliased, conditional, dynamic (`$this`/`static`),
 * and magic return contracts not handled by `RuntimeTypeChecker::$simpleReturnPlanCache`:
 *
 * - Fast-exit checks handle `$this` identity, `static` instanceof, and unconstrained
 *   (`mixed`/`array`) returns before allocating template binding arrays or running substitution.
 * - Direct `$topCallFrame` and `TemplateManager::getInstanceBindingsFast()` lookups avoid full
 *   binding merges when a method uses only class-level or only method-level templates.
 * - Multi-tier AST resolution caches (`$noReturnContractCache`, `$resolvedStaticReturnCache`,
 *   `$unboundReturnCache`, `$substitutedReturnCache`, `$substitutedConditionalReturnCache`)
 *   memoize substituted return types by compact template signatures to avoid repeated
 *   `TemplateSubstitutor` and `SpecialTypeResolver` traversals.
 *
 * @phpstan-import-type FunctionContract from DocblockParser
 *
 * @internal
 */
final class ReturnChecker
{
    /**
     * Fast O(1) lookup set for generic iterable return types.
     */
    public const GENERIC_ITERABLES = [
        'iterable' => true,
        'traversable' => true,
        'iterator' => true,
        'generator' => true,
    ];

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
    public static array $resolvedStaticReturnCache = [];

    /**
     * In-memory 2D cache for concrete substituted generic return types: [$function][$templateSignature] => TypeNode.
     *
     * @var array<string, array<string, TypeNode>>
     */
    public static array $substitutedReturnCache = [];

    /**
     * Cache for substituted conditional return branches: [$cacheFunc][$branchStr][$sig] => TypeNode.
     *
     * @var array<string, array<string, array<string, TypeNode>>>
     */
    public static array $substitutedConditionalReturnCache = [];

    /**
     * @var array<string, array{0: int, 1: array<int, true>}>
     */
    public static array $unboundReturnFallbackCache = [];

    /**
     * @var array<string, array<string, array{0: int, 1: array<int, true>}>>
     */
    public static array $substitutedReturnFallbackCache = [];

    /**
     * @var array<string, array<string, array<string, array{0: int, 1: array<int, true>}>>>
     */
    public static array $substitutedCondReturnFallbackCache = [];

    /**
     * Resets internal caches. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$substitutedConditionalReturnCache = [];
        self::$noReturnContractCache = [];
        self::$resolvedStaticReturnCache = [];
        self::$unboundReturnCache = [];
        self::$substitutedReturnCache = [];
        self::$unboundReturnFallbackCache = [];
        self::$substitutedReturnFallbackCache = [];
        self::$substitutedCondReturnFallbackCache = [];
    }

    /**
     * Checks if the return type of a function is unconstrained (mixed or array).
     * Uses the pre-computed flag from DocblockParser contract when available.
     */
    public static function isReturnUnconstrained(string $effectiveFunction): bool
    {
        if ((ParamChecker::$functionKindCache[$effectiveFunction] ??= ParamChecker::detectFunctionKind($effectiveFunction)) >= ParamChecker::KIND_MAGIC_CALL) {
            return false;
        }

        $contract = DocblockParser::parse($effectiveFunction);

        return $contract['returnUnconstrained'] ?? false;
    }

    /**
     * @param array<string, mixed> $vars
     * @param FunctionContract|null $contract Pre-resolved contract to avoid re-parsing
     */
    public static function checkReturn(
        string $function,
        mixed $value,
        object|string|null $thisOrClass,
        array $vars,
        TypeValidatorRegistry $registry,
        callable $wrapIterableCallback,
        string $effectiveFunction = '',
        ?array $contract = null
    ): mixed {
        if (! Config::isReturnsEnabled()) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

        if ($effectiveFunction === '') {
            $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);
        }

        $functionKind = ParamChecker::$functionKindCache[$effectiveFunction] ??= ParamChecker::detectFunctionKind($effectiveFunction);

        if ($functionKind === ParamChecker::KIND_MAGIC_CALL) {
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

            return $value;
        }

        if ($functionKind === ParamChecker::KIND_MAGIC_GET) {
            return self::handleMagicPropertyRead(
                $effectiveFunction,
                $value,
                $thisObj,
                $vars,
                $registry,
                $wrapIterableCallback
            );
        }

        if (isset(self::$noReturnContractCache[$effectiveFunction])) {
            return $value;
        }

        $contract ??= DocblockParser::parse($effectiveFunction);

        if (! ($contract['hasReturnContract'] ?? ($contract['return'] !== null))) {
            self::$noReturnContractCache[$effectiveFunction] = true;

            return $value;
        }

        $returnTypeNode = $contract['return'];
        if ($returnTypeNode === null) {
            self::$noReturnContractCache[$effectiveFunction] = true;

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
     * Intercepts and evaluates return contracts for dynamic @property and @property-read accesses routed via __get.
     *
     * @param array<string, mixed> $vars
     */
    private static function handleMagicPropertyRead(
        string $effectiveFunction,
        mixed $value,
        ?object $thisObj,
        array $vars,
        TypeValidatorRegistry $registry,
        callable $wrapIterableCallback
    ): mixed {
        if (! Config::isMagicPropertiesEnabled()) {
            return $value;
        }

        $propName = array_values($vars)[0] ?? null;
        if (! \is_string($propName)) {
            return $value;
        }

        $className = explode('::', $effectiveFunction, 2)[0];
        $magicContract = DocblockParser::parseMagicPropertyContract($className, $propName);

        if ($magicContract === null) {
            return $value;
        }
        if (! $magicContract['readable']) {
            return ErrorFactory::createError("Cannot read from write-only property {$className}::\${$propName}");
        }

        if (! Config::isMagicPropertyReadsEnabled() || $magicContract['readType'] === null) {
            return $value;
        }

        $typeNode = $magicContract['readType'];

        if ($thisObj !== null) {
            $allTemplates = DocblockParser::parseClassTemplates($className);

            if ($allTemplates === []) {
                $constructorTarget = $className . '::__construct';
                $contract = DocblockParser::parse($constructorTarget);
                $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
            }

            $boundTemplates = TemplateManager::getBoundTemplates('none', $thisObj, $allTemplates);

            if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $allTemplates);
                $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);
            }
        }

        $context = 'Property ' . $className . '::$' . $propName;
        $err = $registry->validate($value, $typeNode, $context);

        if ($err !== null) {
            $msg = $err->getMessage();
            if (str_ends_with($msg, ' given')) {
                $msg = substr($msg, 0, -\strlen(' given')) . ' returned';
            }

            return ErrorFactory::createError($msg);
        }

        if ($value instanceof Traversable) {
            $baseName = '';
            if ($typeNode instanceof IdentifierTypeNode) {
                $baseName = strtolower(ltrim($typeNode->name, '\\'));
            } elseif ($typeNode instanceof GenericTypeNode) {
                $baseName = strtolower(ltrim((string) $typeNode->type, '\\'));
            }

            if (isset(self::GENERIC_ITERABLES[$baseName])) {
                return $wrapIterableCallback($effectiveFunction, 'return', $value);
            }
        }

        return $value;
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
     * @param FunctionContract|array{} $contract
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

            if ($thisObj !== null) {
                return $value;
            }
        }

        if ($thisObj !== null && $returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === 'static') {
            if ($value instanceof $thisObj) {
                return $value;
            }
        }

        $hasGenerics = (\count($templates) > 0);
        $hasAliases = (\count($aliases) > 0);
        $hasConditionals = $contract['returnHasConditional']
            ?? (! ($returnTypeNode instanceof IdentifierTypeNode) && ConditionalChecker::containsConditional($returnTypeNode));

        if (! $hasGenerics && ! $hasAliases && ! $hasConditionals && ! ($returnTypeNode instanceof CallableTypeNode)) {
            $isDynamic = $contract['returnIsDynamic'] ?? (str_contains((string) $returnTypeNode, 'static') || str_contains((string) $returnTypeNode, '$this'));

            if (! $isDynamic) {
                $resolvedType = self::$resolvedStaticReturnCache[$function] ??= SpecialTypeResolver::resolve($returnTypeNode, $function, null);
            } else {
                $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
            }

            if ($resolvedType instanceof IdentifierTypeNode) {
                $name = $resolvedType->name;
                if ($name === 'mixed' || $name === 'array' || strtolower($name) === 'mixed' || strtolower($name) === 'array') {
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
                    $baseName = strtolower(ltrim((string) $resolvedType->type, '\\'));
                }

                if (isset(self::GENERIC_ITERABLES[$baseName])) {
                    return $wrapIterableCallback($function, 'return', $value);
                }
            }

            return $value;
        }

        $hasClassTemplates = ($contract['classTemplates'] ?? []) !== [];
        $hasMethodTemplates = ($contract['templates'] ?? []) !== [];

        if ($thisObj !== null && ! $hasMethodTemplates) {
            $boundTemplates = $hasClassTemplates ? TemplateManager::getInstanceBindingsFast($thisObj) : [];
        } elseif ($thisObj !== null && ! $hasClassTemplates) {
            $boundTemplates = TemplateManager::$topCallFrame[$function] ?? [];
        } else {
            $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);
        }

        $isDynamicReturn = $contract['returnIsDynamic']
            ?? (str_contains((string) $returnTypeNode, 'static') || str_contains((string) $returnTypeNode, '$this'));

        $sig = null;
        $cacheFunc = null;
        $boundCount = \count($boundTemplates);
        $fallbackMeta = [0, []];
        $thisFallbackKeys = $thisObj !== null ? TemplateManager::getFallbackBoundTemplates($thisObj) : [];

        if ($boundCount <= 5 && ($thisObj !== null || ! $isDynamicReturn)) {
            $cacheFunc = ($isDynamicReturn && $thisObj !== null) ? ($function . '@' . $thisObj::class) : $function;

            if ($boundCount === 0) {
                $sig = '';
            } elseif ($boundCount === 1) {
                $firstKey = (string) array_key_first($boundTemplates);
                $first = $boundTemplates[$firstKey];
                $sig = $firstKey . '=' . ($first instanceof IdentifierTypeNode ? $first->name : (string) $first);
            } else {
                $sig = '';
                foreach ($boundTemplates as $k => $v) {
                    $sig .= $k . '=' . ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
                }
            }

            if (str_contains($sig, 'static') || str_contains($sig, '$this')) {
                $sig = null;
            } else {
                if ($thisFallbackKeys !== []) {
                    $sig .= '!fb:' . implode(',', array_keys($thisFallbackKeys));
                }
                if (! $hasConditionals && isset(self::$substitutedReturnCache[$cacheFunc][$sig])) {
                    $resolvedType = self::$substitutedReturnCache[$cacheFunc][$sig];
                    $fallbackMeta = self::$substitutedReturnFallbackCache[$cacheFunc][$sig] ?? [0, []];
                }
            }
        }

        if (! isset($resolvedType)) {
            $resolvedType = $isDynamicReturn
                ? SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj)
                : $returnTypeNode;

            if ($resolvedType instanceof IdentifierTypeNode && isset($aliases[$resolvedType->name])) {
                $resolvedType = $aliases[$resolvedType->name];
            }

            if ($hasConditionals || (! ($resolvedType instanceof IdentifierTypeNode) && ConditionalChecker::containsConditional($resolvedType))) {
                $resolvedType = ConditionalChecker::resolve($resolvedType, $vars, $boundTemplates, $registry, $function);
            }

            $condBranchKey = null;
            $condCacheHit = false;
            if ($hasConditionals && $sig !== null && $cacheFunc !== null) {
                $condBranchKey = (string) $resolvedType;
                if (isset(self::$substitutedConditionalReturnCache[$cacheFunc][$condBranchKey][$sig])) {
                    $resolvedType = self::$substitutedConditionalReturnCache[$cacheFunc][$condBranchKey][$sig];
                    $fallbackMeta = self::$substitutedCondReturnFallbackCache[$cacheFunc][$condBranchKey][$sig] ?? [0, []];
                    $condCacheHit = true;
                }
            }

            if (! $condCacheHit) {
                if ($boundCount === 0 && \count($templates) > 0 && ! $hasConditionals && $thisObj === null) {
                    if (isset(self::$unboundReturnCache[$function])) {
                        $resolvedType = self::$unboundReturnCache[$function];
                        $fallbackMeta = self::$unboundReturnFallbackCache[$function] ?? [0, []];
                    } else {
                        $fallbackMeta = self::collectUnboundReturnArgIndices($resolvedType, [], $templates);
                        self::$unboundReturnFallbackCache[$function] = $fallbackMeta;
                        $resolvedType = self::$unboundReturnCache[$function] = SpecialTypeResolver::resolve(
                            TemplateSubstitutor::substitute($resolvedType, [], $templates),
                            $function,
                            null
                        );
                    }
                } elseif ($boundCount > 0 || \count($templates) > 0) {
                    $concreteBound = $thisFallbackKeys !== []
                        ? array_diff_key($boundTemplates, $thisFallbackKeys)
                        : $boundTemplates;
                    $fallbackMeta = self::collectUnboundReturnArgIndices($resolvedType, $concreteBound, $templates);
                    $subType = TemplateSubstitutor::substitute($resolvedType, $boundTemplates, $templates);
                    $resolvedType = ($isDynamicReturn || $subType instanceof OffsetAccessTypeNode)
                        ? SpecialTypeResolver::resolve($subType, $function, $thisObj)
                        : $subType;
                }

                if (! ($resolvedType instanceof IdentifierTypeNode) && ConditionalChecker::containsConditional($resolvedType)) {
                    $resolvedType = ConditionalChecker::resolve($resolvedType, $vars, $boundTemplates, $registry, $function);
                }

                if ($sig !== null && $cacheFunc !== null) {
                    if ($hasConditionals && $condBranchKey !== null) {
                        self::$substitutedConditionalReturnCache[$cacheFunc][$condBranchKey][$sig] = $resolvedType;
                        self::$substitutedCondReturnFallbackCache[$cacheFunc][$condBranchKey][$sig] = $fallbackMeta;
                    } elseif (! $hasConditionals) {
                        self::$substitutedReturnCache[$cacheFunc][$sig] = $resolvedType;
                        self::$substitutedReturnFallbackCache[$cacheFunc][$sig] = $fallbackMeta;
                    }
                }
            }
        }

        if ($resolvedType instanceof IdentifierTypeNode) {
            $lower = strtolower($resolvedType->name);
            if ($lower === 'mixed' || $lower === 'array') {
                return $value;
            }
        }

        $preExistingBindings = null;
        if (($fallbackMeta[0] === -1 || $fallbackMeta[1] !== []) && \is_object($value) && $resolvedType instanceof GenericTypeNode) {
            $preExistingBindings = TemplateManager::getConcreteInstanceBindings($value);
        }

        $err = $registry->validate($value, $resolvedType, 'Return value');
        if ($err !== null) {
            return ErrorFactory::createError($function . '(): ' . $err->getMessage());
        }

        if ($preExistingBindings !== null && \is_object($value) && $resolvedType instanceof GenericTypeNode) {
            TemplateManager::markFallbackReturnBindings($value, $resolvedType, $fallbackMeta[1], $fallbackMeta[0], $preExistingBindings);
        }

        if ($resolvedType instanceof CallableTypeNode && CallableWrapper::isCallable($value)) {
            return CallableWrapper::wrapTypeNode($resolvedType, $value, $function . '(): Return value', $registry);
        }

        if ($value instanceof Traversable) {
            $baseName = '';
            if ($resolvedType instanceof IdentifierTypeNode) {
                $baseName = strtolower(ltrim($resolvedType->name, '\\'));
            } elseif ($resolvedType instanceof GenericTypeNode) {
                $baseName = strtolower(ltrim((string) $resolvedType->type, '\\'));
            }

            if (isset(self::GENERIC_ITERABLES[$baseName])) {
                return $wrapIterableCallback($function, 'return', $value);
            }
        }

        return $value;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     *
     * @return array{0: int, 1: array<int, true>}
     */
    private static function collectUnboundReturnArgIndices(
        TypeNode $preSubNode,
        array $boundTemplates,
        array $declaredTemplates
    ): array {
        return TemplateManager::collectUnboundGenericArgIndices($preSubNode, $boundTemplates, $declaredTemplates);
    }
}
