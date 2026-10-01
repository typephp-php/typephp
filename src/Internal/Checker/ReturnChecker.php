<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
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
 * @phpstan-import-type FunctionContract from DocblockParser
 *
 * @internal Evaluates function and method return contract validations (including dynamic @method calls via __call / __callStatic and dynamic @property reads via __get).
 */
final class ReturnChecker
{
    /**
     * Fast O(1) lookup set for generic iterable return types.
     */
    private const GENERIC_ITERABLES = [
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
    private static array $resolvedStaticReturnCache = [];

    /**
     * In-memory 2D cache for concrete substituted generic return types: [$function][$templateSignature] => TypeNode.
     *
     * @var array<string, array<string, TypeNode>>
     */
    public static array $substitutedReturnCache = [];

    /**
     * Resets internal caches. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$noReturnContractCache = [];
        self::$resolvedStaticReturnCache = [];
        self::$unboundReturnCache = [];
        self::$substitutedReturnCache = [];
    }

    /**
     * Checks if the return type of a function is unconstrained (mixed or array).
     * Uses the pre-computed flag from DocblockParser contract when available.
     */
    public static function isReturnUnconstrained(string $effectiveFunction): bool
    {
        if (str_contains($effectiveFunction, '__call') || str_ends_with($effectiveFunction, '::__get')) {
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

        $isMagicCall = str_contains($effectiveFunction, '__call');
        $isMagicGet = str_ends_with($effectiveFunction, '::__get');

        if (! $isMagicCall && ! $isMagicGet) {
            if (isset(self::$noReturnContractCache[$function]) || isset(self::$noReturnContractCache[$effectiveFunction])) {
                self::$noReturnContractCache[$function] = true;

                return $value;
            }
        }

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

        if ($isMagicGet) {
            return self::handleMagicPropertyRead(
                $effectiveFunction,
                $value,
                $thisObj,
                $vars,
                $registry,
                $wrapIterableCallback
            );
        }

        $contract ??= DocblockParser::parse($effectiveFunction);

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
            $constructorTarget = $className . '::__construct';
            $contract = DocblockParser::parse($constructorTarget);
            $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
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
                $baseName = strtolower(ltrim($typeNode->type->name, '\\'));
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
        }

        $hasGenerics = (\count($templates) > 0);
        $hasAliases = (\count($aliases) > 0);
        $hasConditionals = ConditionalChecker::containsConditional($returnTypeNode);

        if (! $hasGenerics && ! $hasAliases && ! $hasConditionals && ! ($returnTypeNode instanceof CallableTypeNode)) {
            $isDynamic = $contract['returnIsDynamic'] ?? (str_contains((string) $returnTypeNode, 'static') || str_contains((string) $returnTypeNode, '$this'));

            if (! $isDynamic) {
                $resolvedType = self::$resolvedStaticReturnCache[$function] ??= SpecialTypeResolver::resolve($returnTypeNode, $function, null);
            } else {
                $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
            }

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

                if (isset(self::GENERIC_ITERABLES[$baseName])) {
                    return $wrapIterableCallback($function, 'return', $value);
                }
            }

            return $value;
        }

        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);

        $sig = null;
        $boundCount = \count($boundTemplates);
        if ($boundCount > 0 && $boundCount <= 2 && ! $hasConditionals && \count($aliases) === 0 && $thisObj === null) {
            if ($boundCount === 1) {
                $first = reset($boundTemplates);
                $sig = $first instanceof IdentifierTypeNode ? $first->name : (string) $first;
            } else {
                $sig = '';
                foreach ($boundTemplates as $v) {
                    $sig .= ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
                }
            }

            if (isset(self::$substitutedReturnCache[$function][$sig])) {
                $resolvedType = self::$substitutedReturnCache[$function][$sig];
            }
        }

        if (! isset($resolvedType)) {
            $resolvedType = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);

            if ($resolvedType instanceof IdentifierTypeNode && isset($aliases[$resolvedType->name])) {
                $resolvedType = $aliases[$resolvedType->name];
            }

            if (\count($boundTemplates) === 0 && \count($templates) > 0 && ! $hasConditionals && $thisObj === null) {
                $resolvedType = self::$unboundReturnCache[$function] ??= SpecialTypeResolver::resolve(
                    TemplateSubstitutor::substitute($returnTypeNode, [], $templates),
                    $function,
                    null
                );
            } elseif (\count($boundTemplates) > 0 || \count($templates) > 0) {
                $resolvedType = TemplateSubstitutor::substitute($resolvedType, $boundTemplates, $templates);
                $resolvedType = SpecialTypeResolver::resolve($resolvedType, $function, $thisObj);
            }

            $resolvedType = ConditionalChecker::resolve($resolvedType, $vars, $boundTemplates, $registry, $function);

            if ($sig !== null) {
                self::$substitutedReturnCache[$function][$sig] = $resolvedType;
            }
        }

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

            if (isset(self::GENERIC_ITERABLES[$baseName])) {
                return $wrapIterableCallback($function, 'return', $value);
            }
        }

        return $value;
    }
}
