<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Ast\ScopeCleaner;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use WeakMap;

/**
 * @internal Evaluates generator yield, send (TSend), and return (TReturn) type validations.
 */
final class GeneratorChecker
{
    private const OP_NONE = 0;

    private const OP_INT = 1;

    private const OP_STRING = 2;

    private const OP_BOOL = 3;

    private const OP_FLOAT = 4;

    private const OP_POSITIVE_INT = 5;

    private const OP_NON_EMPTY_STRING = 6;

    private const OP_ARRAY_KEY = 7;

    private const OP_ARRAY = 8;

    private const OP_OBJECT = 9;

    private const OP_CLASS = 10;

    private const OP_MASK = 11;

    private const OP_FALLBACK = 12;

    private const BIT_INT = 1;

    private const BIT_STRING = 2;

    private const BIT_BOOL = 4;

    private const BIT_FLOAT = 8;

    private const BIT_OBJECT = 16;

    private const BIT_NULL = 32;

    private const BIT_ARRAY = 64;

    /**
     * O(1) Fast-path cache for generators determined to have no return contracts.
     *
     * @var array<string, true>
     */
    private static array $noContractCache = [];

    /**
     * Per-function static plan cache avoiding repeated DocblockParser/ConditionalChecker calls.
     *
     * @var array<string, array{
     *     returnType: TypeNode,
     *     allTemplates: array<string, TemplateTagValueNode>,
     *     aliases: array<string, TypeNode>,
     *     hasGenerics: bool,
     *     hasMethodTemplates: bool,
     *     hasAliases: bool,
     *     hasConditionals: bool,
     *     isDynamic: bool
     * }>
     */
    private static array $generatorPlanCache = [];

    /**
     * Cache for resolved yield & send types of non-generic generators:
     * [$effectiveFunction] => compiled tuple.
     *
     * @var array<string, array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}>
     */
    private static array $staticYieldTypeCache = [];

    /**
     * 2D Cache for resolved yield & send types of generic generators:
     * [$effectiveFunction][$templateSignature] => compiled tuple.
     *
     * @var array<string, array<string, array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}>>
     */
    private static array $genericYieldTypeCache = [];

    /**
     * Per-instance yield contract cache keyed by object identity and raw instance bindings.
     *
     * @var WeakMap<object, array<string, array{
     *     rawBindings: ?array<string, TypeNode>,
     *     types: array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}
     * }>>|null
     */
    private static ?WeakMap $instanceYieldCache = null;

    public static function reset(): void
    {
        self::$noContractCache = [];
        self::$generatorPlanCache = [];
        self::$staticYieldTypeCache = [];
        self::$genericYieldTypeCache = [];
        self::$instanceYieldCache = null;
    }

    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(
        string $function,
        mixed $sendValue,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null,
        string $effectiveFunction = ''
    ): mixed {
        if ($sendValue === null) {
            return null;
        }

        $targetFunction = $effectiveFunction !== '' ? $effectiveFunction : $function;
        $types = ($scopeCleaner !== null && $scopeCleaner->resolvedYieldTypes !== null)
            ? $scopeCleaner->resolvedYieldTypes
            : self::resolveYieldAndSendTypes($targetFunction, $thisOrClass, $registry, $scopeCleaner);
        if ($types === null) {
            return $sendValue;
        }

        $sendTypeNode = $types[2];
        if ($sendTypeNode === null) {
            return $sendValue;
        }

        $err = $registry->validate($sendValue, $sendTypeNode, "$targetFunction(): Generator sent value (TSend)");

        return $err ?? $sendValue;
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue with zero-allocation caching.
     */
    public static function checkYield(
        string $function,
        mixed $key,
        mixed $value,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null,
        string $effectiveFunction = ''
    ): mixed {
        $targetFunction = $effectiveFunction !== '' ? $effectiveFunction : $function;
        $types = ($scopeCleaner !== null && $scopeCleaner->resolvedYieldTypes !== null)
            ? $scopeCleaner->resolvedYieldTypes
            : self::resolveYieldAndSendTypes($targetFunction, $thisOrClass, $registry, $scopeCleaner);
        if ($types === null) {
            return $value;
        }

        $keyOp = $types[4];
        if ($key !== null && $keyOp !== self::OP_NONE) {
            $keyExtra = $types[5];
            $keyOk = match ($keyOp) {
                self::OP_INT => \is_int($key),
                self::OP_STRING => \is_string($key),
                self::OP_ARRAY_KEY => \is_int($key) || \is_string($key),
                self::OP_POSITIVE_INT => \is_int($key) && $key > 0,
                self::OP_NON_EMPTY_STRING => \is_string($key) && $key !== '',
                self::OP_BOOL => \is_bool($key),
                self::OP_FLOAT => \is_float($key) || \is_int($key),
                self::OP_OBJECT => \is_object($key),
                self::OP_CLASS => \is_object($key) && \is_string($keyExtra) && ($key instanceof $keyExtra),
                self::OP_MASK => \is_int($keyExtra) && (
                    (\is_string($key) && ($keyExtra & self::BIT_STRING) !== 0)
                    || (\is_int($key) && ($keyExtra & (self::BIT_INT | self::BIT_FLOAT)) !== 0)
                    || (\is_object($key) && ($keyExtra & self::BIT_OBJECT) !== 0)
                    || (\is_bool($key) && ($keyExtra & self::BIT_BOOL) !== 0)
                    || (\is_float($key) && ($keyExtra & self::BIT_FLOAT) !== 0)
                    || (\is_array($key) && ($keyExtra & self::BIT_ARRAY) !== 0)
                ),
                default => false,
            };

            if (! $keyOk && $types[0] !== null) {
                $err = $registry->validate($key, $types[0], "$targetFunction(): Return iterator key");
                if ($err !== null) {
                    return $err;
                }
            }
        }

        $valOp = $types[6];
        if ($valOp !== self::OP_NONE) {
            $valExtra = $types[7];
            $valOk = match ($valOp) {
                self::OP_STRING => \is_string($value),
                self::OP_INT => \is_int($value),
                self::OP_BOOL => \is_bool($value),
                self::OP_FLOAT => \is_float($value) || \is_int($value),
                self::OP_POSITIVE_INT => \is_int($value) && $value > 0,
                self::OP_NON_EMPTY_STRING => \is_string($value) && $value !== '',
                self::OP_ARRAY_KEY => \is_int($value) || \is_string($value),
                self::OP_ARRAY => \is_array($value),
                self::OP_OBJECT => \is_object($value),
                self::OP_CLASS => \is_object($value) && \is_string($valExtra) && ($value instanceof $valExtra),
                self::OP_MASK => \is_int($valExtra) && (
                    (\is_string($value) && ($valExtra & self::BIT_STRING) !== 0)
                    || (\is_int($value) && ($valExtra & (self::BIT_INT | self::BIT_FLOAT)) !== 0)
                    || (\is_object($value) && ($valExtra & self::BIT_OBJECT) !== 0)
                    || (\is_bool($value) && ($valExtra & self::BIT_BOOL) !== 0)
                    || (\is_float($value) && ($valExtra & self::BIT_FLOAT) !== 0)
                    || ($value === null && ($valExtra & self::BIT_NULL) !== 0)
                    || (\is_array($value) && ($valExtra & self::BIT_ARRAY) !== 0)
                ),
                default => false,
            };

            if (! $valOk && $types[1] !== null) {
                $err = $registry->validate($value, $types[1], "$targetFunction(): Return iterator value");
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return $value;
    }

    /**
     * Validates a value returned from a generator via `return $value;` against TReturn.
     */
    public static function checkReturn(
        string $function,
        mixed $value,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null,
        string $effectiveFunction = ''
    ): mixed {
        $targetFunction = $effectiveFunction !== '' ? $effectiveFunction : $function;
        $types = ($scopeCleaner !== null && $scopeCleaner->resolvedYieldTypes !== null)
            ? $scopeCleaner->resolvedYieldTypes
            : self::resolveYieldAndSendTypes($targetFunction, $thisOrClass, $registry, $scopeCleaner);
        if ($types === null) {
            return $value;
        }

        $returnTypeNode = $types[3];
        if ($returnTypeNode === null) {
            return $value;
        }

        if ($returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === 'void') {
            if ($value !== null) {
                return ErrorFactory::createError("$targetFunction(): Generator return value must be of type void, " . TypeFormatter::formatGivenValue($value) . ' returned');
            }

            return null;
        }

        $err = $registry->validate($value, $returnTypeNode, "$targetFunction(): Generator return value (TReturn)");

        return $err ?? $value;
    }

    /**
     * Resolves and caches the generator's yielded key, value, sent, and return types in memory.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}|null
     */
    private static function resolveYieldAndSendTypes(
        string $function,
        object|string|null $thisOrClass,
        ?TypeValidatorRegistry $registry = null,
        ?ScopeCleaner $scopeCleaner = null
    ): ?array {
        if (isset(self::$noContractCache[$function])) {
            return null;
        }

        if ($scopeCleaner === null && isset(self::$staticYieldTypeCache[$function])) {
            return self::$staticYieldTypeCache[$function];
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

        if (! isset(self::$generatorPlanCache[$function])) {
            $contract = DocblockParser::parse($function);
            $returnTypeNode = $contract['return'] ?? null;

            if ($returnTypeNode === null) {
                self::$noContractCache[$function] = true;

                return null;
            }

            $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
            $aliases = $contract['aliases'] ?? [];
            $hasGenerics = $allTemplates !== [];
            $hasMethodTemplates = ($contract['templates'] ?? []) !== [];
            $hasAliases = $aliases !== [];
            $hasConditionals = $contract['returnHasConditional'] ?? ConditionalChecker::containsConditional($returnTypeNode);
            $isDynamic = $contract['returnIsDynamic'] ?? (str_contains((string) $returnTypeNode, 'static') || str_contains((string) $returnTypeNode, '$this'));

            if (! $hasGenerics && ! $hasAliases && ! $hasConditionals && ! $isDynamic) {
                $resolvedNode = SpecialTypeResolver::resolve($returnTypeNode, $function, null);
                $types = self::$staticYieldTypeCache[$function] = self::compileYieldTypes($resolvedNode);
                if ($scopeCleaner !== null) {
                    $scopeCleaner->resolvedYieldTypes = $types;
                }

                return $types;
            }

            self::$generatorPlanCache[$function] = [
                'returnType' => $returnTypeNode,
                'allTemplates' => $allTemplates,
                'aliases' => $aliases,
                'hasGenerics' => $hasGenerics,
                'hasMethodTemplates' => $hasMethodTemplates,
                'hasAliases' => $hasAliases,
                'hasConditionals' => $hasConditionals,
                'isDynamic' => $isDynamic,
            ];
        }

        $plan = self::$generatorPlanCache[$function];
        $returnTypeNode = $plan['returnType'];
        $allTemplates = $plan['allTemplates'];
        $aliases = $plan['aliases'];

        $canUseInstanceCache = $thisObj !== null
            && $scopeCleaner === null
            && ! $plan['hasMethodTemplates']
            && ! $plan['hasAliases']
            && ! $plan['hasConditionals']
            && ! $plan['isDynamic'];

        if ($canUseInstanceCache) {
            self::$instanceYieldCache ??= new WeakMap();
            $cachedEntry = self::$instanceYieldCache[$thisObj][$function] ?? null;
            $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                ? (TemplateManager::$instanceTemplateBindings[$thisObj] ?? null)
                : null;
            if ($cachedEntry !== null && $cachedEntry['rawBindings'] === $rawBindings) {
                return $cachedEntry['types'];
            }
        }

        $boundTemplates = [];
        if ($scopeCleaner !== null && $scopeCleaner->boundTemplates !== []) {
            $boundTemplates = $scopeCleaner->boundTemplates;
        } elseif ($plan['hasGenerics']) {
            $boundTemplates = ($thisObj !== null && ! $plan['hasMethodTemplates'])
                ? TemplateManager::getInstanceBindingsFast($thisObj)
                : TemplateManager::getBoundTemplates($function, $thisOrClass, $allTemplates);
        }

        $sig = null;
        if (! $plan['hasAliases'] && ! $plan['hasConditionals'] && ! $plan['isDynamic'] && $scopeCleaner === null) {
            if ($boundTemplates === []) {
                $sig = '';
            } elseif (\count($boundTemplates) === 1) {
                $firstKey = (string) array_key_first($boundTemplates);
                $first = $boundTemplates[$firstKey];
                $sig = $firstKey . '=' . ($first instanceof IdentifierTypeNode ? $first->name : (string) $first);
            } elseif (\count($boundTemplates) <= 3) {
                $sig = '';
                foreach ($boundTemplates as $k => $v) {
                    $sig .= $k . '=' . ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
                }
            }

            if ($sig !== null && isset(self::$genericYieldTypeCache[$function][$sig])) {
                $types = self::$genericYieldTypeCache[$function][$sig];
                if ($canUseInstanceCache) {
                    $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                        ? (TemplateManager::$instanceTemplateBindings[$thisObj] ?? null)
                        : null;
                    /** @var array<string, array{rawBindings: ?array<string, TypeNode>, types: array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}}> $mapEntry */
                    $mapEntry = self::$instanceYieldCache[$thisObj] ?? [];
                    $mapEntry[$function] = ['rawBindings' => $rawBindings, 'types' => $types];
                    self::$instanceYieldCache[$thisObj] = $mapEntry;
                }

                return $types;
            }
        }

        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        if ($plan['hasConditionals'] && $registry !== null) {
            $returnTypeNode = ConditionalChecker::resolve($returnTypeNode, [], $boundTemplates, $registry, $function);
        }

        if ($boundTemplates !== [] || $allTemplates !== []) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $allTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        } elseif ($plan['isDynamic']) {
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        }

        if (ConditionalChecker::containsConditional($returnTypeNode) && $registry !== null) {
            $returnTypeNode = ConditionalChecker::resolve($returnTypeNode, [], $boundTemplates, $registry, $function);
        }

        $types = self::compileYieldTypes($returnTypeNode);

        if ($sig !== null) {
            self::$genericYieldTypeCache[$function][$sig] = $types;
        }

        if ($canUseInstanceCache) {
            $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                ? (TemplateManager::$instanceTemplateBindings[$thisObj] ?? null)
                : null;
            /** @var array<string, array{rawBindings: ?array<string, TypeNode>, types: array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}}> $mapEntry */
            $mapEntry = self::$instanceYieldCache[$thisObj] ?? [];
            $mapEntry[$function] = ['rawBindings' => $rawBindings, 'types' => $types];
            self::$instanceYieldCache[$thisObj] = $mapEntry;
        }

        if ($scopeCleaner !== null) {
            $scopeCleaner->resolvedYieldTypes = $types;
        }

        return $types;
    }

    /**
     * Extracts and precompiles yielded key, item, sent (TSend), and return (TReturn) contracts.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode, 3: ?TypeNode, 4: int, 5: string|int|null, 6: int, 7: string|int|null}
     */
    private static function compileYieldTypes(TypeNode $returnTypeNode): array
    {
        $itemTypeNode = null;
        $keyTypeNode = null;
        $sendTypeNode = null;
        $generatorReturnTypeNode = null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $typesCount = \count($returnTypeNode->genericTypes);
            if ($typesCount === 1) {
                $itemTypeNode = $returnTypeNode->genericTypes[0];
            } elseif ($typesCount === 2) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
            } elseif ($typesCount === 3) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
                $sendTypeNode = $returnTypeNode->genericTypes[2];
            } elseif ($typesCount >= 4) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
                $sendTypeNode = $returnTypeNode->genericTypes[2];
                $generatorReturnTypeNode = $returnTypeNode->genericTypes[3];
            }
        } elseif ($returnTypeNode instanceof ArrayTypeNode) {
            $itemTypeNode = $returnTypeNode->type;
        }

        if ($keyTypeNode instanceof IdentifierTypeNode && strtolower($keyTypeNode->name) === 'mixed') {
            $keyTypeNode = null;
        }

        if ($itemTypeNode instanceof IdentifierTypeNode && strtolower($itemTypeNode->name) === 'mixed') {
            $itemTypeNode = null;
        }

        [$keyOp, $keyExtra] = self::compileCheckOp($keyTypeNode);
        [$valOp, $valExtra] = self::compileCheckOp($itemTypeNode);

        return [$keyTypeNode, $itemTypeNode, $sendTypeNode, $generatorReturnTypeNode, $keyOp, $keyExtra, $valOp, $valExtra];
    }

    /**
     * @return array{0: int, 1: string|int|null}
     */
    private static function compileCheckOp(?TypeNode $typeNode): array
    {
        if ($typeNode === null) {
            return [self::OP_NONE, null];
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            $name = $typeNode->name;
            $lower = strtolower($name);

            return match ($lower) {
                'mixed' => [self::OP_NONE, null],
                'int', 'integer' => [self::OP_INT, null],
                'string' => [self::OP_STRING, null],
                'bool', 'boolean' => [self::OP_BOOL, null],
                'float', 'double' => [self::OP_FLOAT, null],
                'positive-int' => [self::OP_POSITIVE_INT, null],
                'non-empty-string' => [self::OP_NON_EMPTY_STRING, null],
                'array-key' => [self::OP_ARRAY_KEY, null],
                'array' => [self::OP_ARRAY, null],
                'object' => [self::OP_OBJECT, null],
                default => (ClassNameValidator::isValid($name) && (class_exists($name, false) || interface_exists($name, false)))
                    ? [self::OP_CLASS, $name]
                    : [self::OP_FALLBACK, null],
            };
        }

        if ($typeNode instanceof UnionTypeNode) {
            $mask = 0;
            foreach ($typeNode->types as $member) {
                if (! ($member instanceof IdentifierTypeNode)) {
                    return [self::OP_FALLBACK, null];
                }

                $bit = match (strtolower($member->name)) {
                    'int', 'integer' => self::BIT_INT,
                    'string' => self::BIT_STRING,
                    'bool', 'boolean' => self::BIT_BOOL,
                    'float', 'double' => self::BIT_FLOAT,
                    'object' => self::BIT_OBJECT,
                    'null' => self::BIT_NULL,
                    'array' => self::BIT_ARRAY,
                    'array-key' => self::BIT_INT | self::BIT_STRING,
                    default => 0,
                };

                if ($bit === 0) {
                    return [self::OP_FALLBACK, null];
                }

                $mask |= $bit;
            }

            return $mask !== 0 ? [self::OP_MASK, $mask] : [self::OP_FALLBACK, null];
        }

        return [self::OP_FALLBACK, null];
    }
}
