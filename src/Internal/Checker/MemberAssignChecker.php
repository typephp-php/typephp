<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionNamedType;
use ReflectionProperty;
use TypePHP\Internal\Ast\Elimination\StaticTypeEvaluator;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StructureMutator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use WeakMap;

/**
 * @internal Evaluates array key/dimension and object shape property member assignments.
 */
final class MemberAssignChecker
{
    public const OP_NONE = 0;
    public const OP_INT = 1;
    public const OP_STRING = 2;
    public const OP_BOOL = 3;
    public const OP_FLOAT = 4;
    public const OP_POSITIVE_INT = 5;
    public const OP_NON_EMPTY_STRING = 6;
    public const OP_ARRAY_KEY = 7;
    public const OP_ARRAY = 8;
    public const OP_OBJECT = 9;
    public const OP_CLASS = 10;
    public const OP_MASK = 11;
    public const OP_FALLBACK = 12;

    public const BIT_INT = 1;
    public const BIT_STRING = 2;
    public const BIT_BOOL = 4;
    public const BIT_FLOAT = 8;
    public const BIT_OBJECT = 16;
    public const BIT_NULL = 32;
    public const BIT_ARRAY = 64;

    /**
     * In-memory 2D cache for ReflectionProperty instances:
     * [$className][$propName] => ?ReflectionProperty (null = non-declared/dynamic property)
     *
     * @var array<string, array<string, ?ReflectionProperty>>
     */
    private static array $refPropertyCache = [];

    /**
     * Per-property raw contract cache:
     * [$className][$propName] => ?array{
     *     rawType: TypeNode,
     *     classTemplates: array<string, TemplateTagValueNode>,
     *     usesTemplates: bool,
     *     isNativeNonNullArray: bool,
     *     isMagicOrMissing: bool
     * }
     *
     * @var array<string, array<string, ?array{
     *     rawType: TypeNode,
     *     classTemplates: array<string, TemplateTagValueNode>,
     *     usesTemplates: bool,
     *     isNativeNonNullArray: bool,
     *     isMagicOrMissing: bool
     * }>>
     */
    private static array $propertyMutationPlanCache = [];

    /**
     * Substituted element contract cache keyed by template binding signature:
     * [$className][$propName][$sig] => ?array{...}
     *
     * @var array<string, array<string, array<string, ?array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * }>>>
     */
    private static array $substitutedElementContractCache = [];

    /**
     * Per-instance contract cache keyed by object identity and raw instance bindings.
     *
     * @var WeakMap<object, array{
     *     rawBindings: ?array<string, TypeNode>,
     *     contracts: array<string, ?array{
     *         isList: bool,
     *         quoteKey: bool,
     *         keyType: ?TypeNode,
     *         valueType: ?TypeNode,
     *         keyOp: int,
     *         keyExtra: string|int|null,
     *         valOp: int,
     *         valExtra: string|int|null
     *     }>
     * }>|null
     */
    private static ?WeakMap $instanceContractCache = null;

    /**
     * Fast packed per-instance contract cache for direct property-dim mutation fast-path in RuntimeTypeChecker:
     * [$obj] => array{0: ?array<string, TypeNode>, 1: array<string, array{0: bool, 1: int, 2: string|int|null, 3: int, 4: string|int|null}>}
     *
     * @var WeakMap<object, array{0: ?array<string, TypeNode>, 1: array<string, array{0: bool, 1: int, 2: string|int|null, 3: int, 4: string|int|null}>}>|null
     */
    public static ?WeakMap $fastObjectContractCache = null;

    /**
     * @var WeakMap<TypeNode, array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * }|false>|null
     */
    private static ?WeakMap $innerElementContractCache = null;

    /**
     * Resets the cached reflection property and element contract registries.
     */
    public static function reset(): void
    {
        self::$innerElementContractCache = null;
        self::$refPropertyCache = [];
        self::$propertyMutationPlanCache = [];
        self::$substitutedElementContractCache = [];
        self::$instanceContractCache = null;
        self::$fastObjectContractCache = null;
    }

    /**
     * Evaluates member mutation assignment against the declared type contract.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function check(
        mixed $root,
        array $chain,
        mixed $value,
        string $typeString,
        string $varName,
        string $file,
        TypeValidatorRegistry $registry,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): mixed {
        $isObjectDimAccess = \is_object($root) && ($chain[0][0] ?? null) === 'dim';

        if (
            (RuntimeTypeChecker::$arrayValidationHybrid || $isObjectDimAccess)
            && ! str_contains($typeString, '{')
            && \count($chain) === 1
            && $chain[0][0] === 'dim'
        ) {
            $directErr = InlineChecker::validateCollectionElementMutation(
                $value,
                $chain,
                $typeString,
                $varName,
                $file,
                $registry,
                $caller,
                $thisOrClass
            );

            if ($directErr instanceof ErrorMessage) {
                return $directErr;
            }

            return $value;
        }

        if ($isObjectDimAccess) {
            return $value;
        }

        $copy = StructureMutator::mutateCopy($root, $chain, $value);

        $res = InlineChecker::checkVariable(
            $copy,
            $typeString,
            $varName,
            $file,
            $registry,
            $caller,
            $thisOrClass
        );

        if ($res instanceof ErrorMessage) {
            return $res;
        }

        return $value;
    }

    /**
     * Evaluates member unset mutations against the root variable's type contract before unsetting.
     *
     * @param list<list<array{0: 'dim'|'prop', 1: mixed}>> $chains
     */
    public static function checkUnset(
        mixed $root,
        array $chains,
        string $typeString,
        string $varName,
        string $file,
        TypeValidatorRegistry $registry,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): ?ErrorMessage {
        if ($chains === []) {
            return null;
        }

        if (\is_object($root) && ($chains[0][0][0] ?? null) === 'dim') {
            return null;
        }

        $copy = StructureMutator::unsetCopy($root, $chains);

        $res = InlineChecker::checkVariable(
            $copy,
            $typeString,
            $varName,
            $file,
            $registry,
            $caller,
            $thisOrClass,
            allowEmptyShape: false
        );

        return $res instanceof ErrorMessage ? $res : null;
    }

    /**
     * Evaluates member unset mutations on an object or static property.
     *
     * @param list<list<array{0: 'dim'|'prop', 1: mixed}>> $chains
     */
    public static function checkPropertyUnset(
        mixed $objectOrClass,
        string $propName,
        array $chains,
        string $file,
        ?TypeValidatorRegistry $registry = null
    ): ?ErrorMessage {
        if ($chains === [] || (! \is_object($objectOrClass) && ! \is_string($objectOrClass))) {
            return null;
        }

        $className = \is_string($objectOrClass) ? $objectOrClass : $objectOrClass::class;

        if (isset(InlineChecker::$nullPropertyCache[$className][$propName]) || ! RuntimeTypeChecker::$inlinePropertiesEnabled) {
            return null;
        }

        if (Config::isEliminateStaticallyProvenChecksEnabled()) {
            $propPlan = (isset(self::$propertyMutationPlanCache[$className]) && \array_key_exists($propName, self::$propertyMutationPlanCache[$className]))
                ? self::$propertyMutationPlanCache[$className][$propName]
                : self::buildPropertyMutationPlan($className, $propName);

            if ($propPlan === null) {
                return null;
            }

            $allSafe = true;
            foreach ($chains as $chain) {
                if (! StaticTypeEvaluator::isUnsetSafe($propPlan['rawType'], $chain)) {
                    $allSafe = false;

                    break;
                }
            }

            if ($allSafe) {
                return null;
            }
        }

        $currentVal = self::readPropertyValue($objectOrClass, $className, $propName);

        if (\is_object($currentVal) && ($chains[0][0][0] ?? null) === 'dim') {
            return null;
        }

        $copy = StructureMutator::unsetCopy($currentVal, $chains);

        $res = InlineChecker::checkProperty(
            $copy,
            $objectOrClass,
            $propName,
            $file,
            $registry ?? RuntimeTypeChecker::getRegistry()
        );

        return $res instanceof ErrorMessage ? $res : null;
    }

    /**
     * Evaluates dimension/member mutation assignment on an object or static property.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function checkProperty(
        mixed $objectOrClass,
        string $propName,
        array $chain,
        mixed $value,
        string $file,
        ?TypeValidatorRegistry $registry = null
    ): mixed {
        if (! \is_object($objectOrClass) && ! \is_string($objectOrClass)) {
            return $value;
        }

        $className = \is_string($objectOrClass) ? $objectOrClass : $objectOrClass::class;

        if (isset(InlineChecker::$nullPropertyCache[$className][$propName]) || ! RuntimeTypeChecker::$inlinePropertiesEnabled) {
            return $value;
        }

        if (
            \count($chain) === 2
            && $chain[0][0] === 'dim'
            && $chain[1][0] === 'dim'
            && RuntimeTypeChecker::$arrayValidationHybrid
            && \is_object($objectOrClass)
        ) {
            $propPlan = (isset(self::$propertyMutationPlanCache[$className]) && \array_key_exists($propName, self::$propertyMutationPlanCache[$className]))
                ? self::$propertyMutationPlanCache[$className][$propName]
                : self::buildPropertyMutationPlan($className, $propName);

            if ($propPlan === null) {
                return $value;
            }

            if (! $propPlan['isMagicOrMissing'] && $propPlan['isNativeNonNullArray']) {
                $outerContract = self::resolveElementContractFromPlan($objectOrClass, $className, $propName, $propPlan);
                if ($outerContract !== null && $outerContract['valueType'] !== null && (! $outerContract['isList'] || $chain[0][1] === null)) {
                    $outerValType = $outerContract['valueType'];
                    self::$innerElementContractCache ??= new WeakMap();
                    $innerContract = self::$innerElementContractCache[$outerValType]
                        ??= (self::buildElementContract($outerValType) ?? false);

                    if ($innerContract !== false && (! $innerContract['isList'] || $chain[1][1] === null)) {
                        $outerKey = $chain[0][1];
                        $outerKeyOk = true;
                        if (! $outerContract['isList'] && $outerKey !== null && $outerContract['keyOp'] !== self::OP_NONE) {
                            $outerKeyOk = match ($outerContract['keyOp']) {
                                self::OP_STRING => \is_string($outerKey),
                                self::OP_INT => \is_int($outerKey),
                                self::OP_ARRAY_KEY => \is_int($outerKey) || \is_string($outerKey),
                                self::OP_NON_EMPTY_STRING => \is_string($outerKey) && $outerKey !== '',
                                self::OP_POSITIVE_INT => \is_int($outerKey) && $outerKey > 0,
                                default => $outerContract['keyType'] === null
                                    || ($registry ??= RuntimeTypeChecker::getRegistry())->validate($outerKey, $outerContract['keyType'], "Property {$className}::\${$propName} key") === null,
                            };
                        }

                        if ($outerKeyOk) {
                            $innerKey = $chain[1][1];
                            $innerKeyOk = true;
                            if (! $innerContract['isList'] && $innerKey !== null && $innerContract['keyOp'] !== self::OP_NONE) {
                                $innerKeyOk = match ($innerContract['keyOp']) {
                                    self::OP_STRING => \is_string($innerKey),
                                    self::OP_INT => \is_int($innerKey),
                                    self::OP_ARRAY_KEY => \is_int($innerKey) || \is_string($innerKey),
                                    default => false,
                                };
                            }

                            $innerValOk = true;
                            if ($innerKeyOk && $innerContract['valOp'] !== self::OP_NONE) {
                                $ivExtra = $innerContract['valExtra'];
                                $innerValOk = match ($innerContract['valOp']) {
                                    self::OP_STRING => \is_string($value),
                                    self::OP_INT => \is_int($value),
                                    self::OP_BOOL => \is_bool($value),
                                    self::OP_FLOAT => \is_float($value) || \is_int($value),
                                    self::OP_POSITIVE_INT => \is_int($value) && $value > 0,
                                    self::OP_NON_EMPTY_STRING => \is_string($value) && $value !== '',
                                    self::OP_ARRAY => \is_array($value),
                                    self::OP_OBJECT => \is_object($value),
                                    self::OP_CLASS => \is_object($value) && \is_string($ivExtra) && ($value instanceof $ivExtra),
                                    default => false,
                                };
                            }

                            if ($innerKeyOk && $innerValOk) {
                                return $value;
                            }

                            $innerErr = self::validatePropertyElementMutationWithContract(
                                $className,
                                $propName,
                                $innerKey,
                                $value,
                                $innerContract,
                                $registry
                            );

                            if ($innerErr === null) {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        if (\count($chain) === 1 && $chain[0][0] === 'dim') {
            $propPlan = (isset(self::$propertyMutationPlanCache[$className]) && \array_key_exists($propName, self::$propertyMutationPlanCache[$className]))
                ? self::$propertyMutationPlanCache[$className][$propName]
                : self::buildPropertyMutationPlan($className, $propName);

            if ($propPlan === null) {
                return $value;
            }

            if (
                RuntimeTypeChecker::$arrayValidationHybrid
                && ! $propPlan['isMagicOrMissing']
                && $propPlan['isNativeNonNullArray']
                && \is_object($objectOrClass)
            ) {
                $contract = null;
                $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                    ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                    : null;

                if ($propPlan['usesTemplates']) {
                    self::$instanceContractCache ??= new WeakMap();
                    $entry = self::$instanceContractCache[$objectOrClass] ?? null;

                    if (
                        $entry !== null
                        && $entry['rawBindings'] === $rawBindings
                        && \array_key_exists($propName, $entry['contracts'])
                    ) {
                        $contract = $entry['contracts'][$propName];
                    } else {
                        $contract = self::resolveElementContractFromPlan($objectOrClass, $className, $propName, $propPlan);
                        $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                            ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                            : null;
                    }
                } elseif (isset(self::$substitutedElementContractCache[$className][$propName]) && \array_key_exists('', self::$substitutedElementContractCache[$className][$propName])) {
                    $contract = self::$substitutedElementContractCache[$className][$propName][''];
                } else {
                    $contract = self::resolveElementContractFromPlan($objectOrClass, $className, $propName, $propPlan);
                }

                if ($contract !== null) {
                    self::$fastObjectContractCache ??= new WeakMap();
                    $fastEntry = self::$fastObjectContractCache[$objectOrClass] ?? null;
                    $packed = [$contract['isList'], $contract['keyOp'], $contract['keyExtra'], $contract['valOp'], $contract['valExtra']];
                    if ($fastEntry === null || $fastEntry[0] !== $rawBindings) {
                        self::$fastObjectContractCache[$objectOrClass] = [$rawBindings, [$propName => $packed]];
                    } else {
                        $fastEntry[1][$propName] = $packed;
                        self::$fastObjectContractCache[$objectOrClass] = $fastEntry;
                    }
                }

                $key = $chain[0][1];
                if ($contract !== null && (! $contract['isList'] || $key === null)) {
                    $keyOk = true;
                    if (! $contract['isList'] && $key !== null && $contract['keyOp'] !== self::OP_NONE) {
                        $keyExtra = $contract['keyExtra'];
                        $keyOk = match ($contract['keyOp']) {
                            self::OP_INT => \is_int($key),
                            self::OP_STRING => \is_string($key),
                            self::OP_ARRAY_KEY => \is_int($key) || \is_string($key),
                            self::OP_NON_EMPTY_STRING => \is_string($key) && $key !== '',
                            self::OP_POSITIVE_INT => \is_int($key) && $key > 0,
                            self::OP_MASK => \is_int($keyExtra) && (
                                (\is_string($key) && ($keyExtra & self::BIT_STRING) !== 0)
                                || (\is_int($key) && ($keyExtra & (self::BIT_INT | self::BIT_FLOAT)) !== 0)
                                || (\is_object($key) && ($keyExtra & self::BIT_OBJECT) !== 0)
                                || (\is_bool($key) && ($keyExtra & self::BIT_BOOL) !== 0)
                                || (\is_float($key) && ($keyExtra & self::BIT_FLOAT) !== 0)
                                || (\is_array($key) && ($keyExtra & self::BIT_ARRAY) !== 0)
                            ),
                            self::OP_OBJECT => \is_object($key),
                            self::OP_CLASS => \is_object($key) && \is_string($keyExtra) && ($key instanceof $keyExtra),
                            default => false,
                        };
                    }

                    $valOk = true;
                    if ($keyOk && $contract['valOp'] !== self::OP_NONE) {
                        $valExtra = $contract['valExtra'];
                        $valOk = match ($contract['valOp']) {
                            self::OP_INT => \is_int($value),
                            self::OP_STRING => \is_string($value),
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
                    }

                    if ($keyOk && $valOk) {
                        return $value;
                    }

                    $directErr = self::validatePropertyElementMutationWithContract(
                        $className,
                        $propName,
                        $key,
                        $value,
                        $contract,
                        $registry
                    );

                    if ($directErr === null) {
                        return $value;
                    }
                }
            }
        }

        $currentVal = self::readPropertyValue($objectOrClass, $className, $propName);
        $isObjectDimAccess = \is_object($currentVal) && ($chain[0][0] ?? null) === 'dim';

        if (
            (
                (RuntimeTypeChecker::$arrayValidationHybrid && \is_array($currentVal) && \count($currentVal) > Config::HYBRID_SAMPLE_THRESHOLD)
                || $isObjectDimAccess
            )
            && \count($chain) === 1
            && $chain[0][0] === 'dim'
        ) {
            $contract = self::resolveElementContract($objectOrClass, $className, $propName);
            if ($contract !== null) {
                $directErr = self::validatePropertyElementMutationWithContract(
                    $className,
                    $propName,
                    $chain[0][1],
                    $value,
                    $contract,
                    $registry
                );

                if ($directErr instanceof ErrorMessage) {
                    return $directErr;
                }
            }

            return $value;
        }

        if ($isObjectDimAccess) {
            return $value;
        }

        $copy = StructureMutator::mutateCopy($currentVal, $chain, $value);

        $res = InlineChecker::checkProperty(
            $copy,
            $objectOrClass,
            $propName,
            $file,
            $registry ?? RuntimeTypeChecker::getRegistry()
        );

        if ($res instanceof ErrorMessage) {
            return $res;
        }

        return $value;
    }

    /**
     * Directly validates a single element appended/assigned to a property array or generic ArrayAccess object in O(1).
     *
     * @param array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * } $contract
     */
    private static function validatePropertyElementMutationWithContract(
        string $className,
        string $propName,
        mixed $key,
        mixed $value,
        array $contract,
        ?TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        try {
            if ($contract['isList']) {
                if ($key !== null && ! \is_int($key)) {
                    $keyStr = \is_string($key) ? $key : '';

                    return ErrorFactory::createError("Property {$className}::\${$propName} must be a list, non-integer key '{$keyStr}' given");
                }
            } elseif ($key !== null && $contract['keyOp'] !== self::OP_NONE) {
                $keyOp = $contract['keyOp'];
                $keyExtra = $contract['keyExtra'];
                $keyOk = match ($keyOp) {
                    self::OP_INT => \is_int($key),
                    self::OP_STRING => \is_string($key),
                    self::OP_ARRAY_KEY => \is_int($key) || \is_string($key),
                    self::OP_NON_EMPTY_STRING => \is_string($key) && $key !== '',
                    self::OP_POSITIVE_INT => \is_int($key) && $key > 0,
                    self::OP_MASK => \is_int($keyExtra) && (
                        (\is_string($key) && ($keyExtra & self::BIT_STRING) !== 0)
                        || (\is_int($key) && ($keyExtra & (self::BIT_INT | self::BIT_FLOAT)) !== 0)
                        || (\is_object($key) && ($keyExtra & self::BIT_OBJECT) !== 0)
                        || (\is_bool($key) && ($keyExtra & self::BIT_BOOL) !== 0)
                        || (\is_float($key) && ($keyExtra & self::BIT_FLOAT) !== 0)
                        || (\is_array($key) && ($keyExtra & self::BIT_ARRAY) !== 0)
                    ),
                    self::OP_OBJECT => \is_object($key),
                    self::OP_CLASS => \is_object($key) && \is_string($keyExtra) && ($key instanceof $keyExtra),
                    default => false,
                };

                if (! $keyOk && $contract['keyType'] !== null) {
                    $registry ??= RuntimeTypeChecker::getRegistry();
                    $keyErr = $registry->validate($key, $contract['keyType'], "Property {$className}::\${$propName} key");
                    if ($keyErr !== null) {
                        return $keyErr;
                    }
                }
            }

            $valOp = $contract['valOp'];
            if ($valOp !== self::OP_NONE) {
                $valExtra = $contract['valExtra'];
                $valOk = match ($valOp) {
                    self::OP_INT => \is_int($value),
                    self::OP_STRING => \is_string($value),
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

                if (! $valOk && $contract['valueType'] !== null) {
                    $keyStr = \is_string($key) ? $key : (\is_int($key) ? (string) $key : '');
                    $formattedKey = $key !== null ? ($contract['quoteKey'] ? "['{$keyStr}']" : "[{$keyStr}]") : '[]';
                    $context = "Property {$className}::\${$propName}" . $formattedKey;
                    $registry ??= RuntimeTypeChecker::getRegistry();

                    return $registry->validate($value, $contract['valueType'], $context);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * @return ?array{
     *     rawType: TypeNode,
     *     classTemplates: array<string, TemplateTagValueNode>,
     *     usesTemplates: bool,
     *     isNativeNonNullArray: bool,
     *     isMagicOrMissing: bool
     * }
     */
    private static function buildPropertyMutationPlan(string $className, string $propName): ?array
    {
        $rawTypeNode = DocblockParser::parseProperty($className, $propName);
        if ($rawTypeNode === null) {
            InlineChecker::$nullPropertyCache[$className][$propName] = true;

            return self::$propertyMutationPlanCache[$className][$propName] = null;
        }

        $classTemplates = DocblockParser::parseClassTemplates($className);
        $usesTemplates = $classTemplates !== [] && DocblockParser::typeReferencesTemplate($rawTypeNode, $classTemplates);

        $isMagicOrMissing = ! property_exists($className, $propName);
        $isNativeNonNullArray = false;
        if (! $isMagicOrMissing) {
            $refProp = self::getReflectionProperty($className, $propName);
            if ($refProp !== null && ! $refProp->isStatic()) {
                $refType = $refProp->getType();
                $isNativeNonNullArray = $refType instanceof ReflectionNamedType
                    && $refType->getName() === 'array'
                    && ! $refType->allowsNull();
            }
        }

        return self::$propertyMutationPlanCache[$className][$propName] = [
            'rawType' => $rawTypeNode,
            'classTemplates' => $classTemplates,
            'usesTemplates' => $usesTemplates,
            'isNativeNonNullArray' => $isNativeNonNullArray,
            'isMagicOrMissing' => $isMagicOrMissing,
        ];
    }

    /**
     * Resolves and memoizes the substituted key/value element contract for a property mutation.
     *
     * @return ?array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * }
     */
    private static function resolveElementContract(mixed $objectOrClass, string $className, string $propName): ?array
    {
        $propPlan = (isset(self::$propertyMutationPlanCache[$className]) && \array_key_exists($propName, self::$propertyMutationPlanCache[$className]))
            ? self::$propertyMutationPlanCache[$className][$propName]
            : self::buildPropertyMutationPlan($className, $propName);

        if ($propPlan === null) {
            return null;
        }

        return self::resolveElementContractFromPlan($objectOrClass, $className, $propName, $propPlan);
    }

    /**
     * @param array{
     *     rawType: TypeNode,
     *     classTemplates: array<string, TemplateTagValueNode>,
     *     usesTemplates: bool,
     *     isNativeNonNullArray: bool,
     *     isMagicOrMissing: bool
     * } $propPlan
     * @param array<string, TypeNode>|null $preFetchedBindings
     *
     * @return ?array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * }
     */
    private static function resolveElementContractFromPlan(
        mixed $objectOrClass,
        string $className,
        string $propName,
        array $propPlan,
        ?array $preFetchedBindings = null
    ): ?array {
        $boundTemplates = [];
        $useInstanceCache = $propPlan['usesTemplates'] && \is_object($objectOrClass);
        $rawBindings = null;

        if ($useInstanceCache) {
            self::$instanceContractCache ??= new WeakMap();
            $entry = self::$instanceContractCache[$objectOrClass] ?? null;
            $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                : null;

            if (
                $entry !== null
                && $entry['rawBindings'] === $rawBindings
                && \array_key_exists($propName, $entry['contracts'])
            ) {
                return $entry['contracts'][$propName];
            }

            $boundTemplates = $preFetchedBindings ?? TemplateManager::getInstanceBindingsFast($objectOrClass);
            $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                : null;
        }

        $sig = '';
        if ($boundTemplates !== []) {
            foreach ($boundTemplates as $k => $v) {
                $sig .= $k . '=' . ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
            }
        }

        if (isset(self::$substitutedElementContractCache[$className][$propName]) && \array_key_exists($sig, self::$substitutedElementContractCache[$className][$propName])) {
            $contract = self::$substitutedElementContractCache[$className][$propName][$sig];
        } else {
            $typeNode = $propPlan['rawType'];
            if ($propPlan['usesTemplates']) {
                $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $propPlan['classTemplates']);
            }

            $contract = self::buildElementContract($typeNode);
            self::$substitutedElementContractCache[$className][$propName][$sig] = $contract;
        }

        if ($useInstanceCache) {
            $entry = self::$instanceContractCache[$objectOrClass] ?? null;
            if ($entry === null || $entry['rawBindings'] !== $rawBindings) {
                self::$instanceContractCache[$objectOrClass] = [
                    'rawBindings' => $rawBindings,
                    'contracts' => [$propName => $contract],
                ];
            } else {
                $entry['contracts'][$propName] = $contract;
                self::$instanceContractCache[$objectOrClass] = $entry;
            }
        }

        return $contract;
    }

    /**
     * @return ?array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyOp: int,
     *     keyExtra: string|int|null,
     *     valOp: int,
     *     valExtra: string|int|null
     * }
     */
    private static function buildElementContract(TypeNode $typeNode): ?array
    {
        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower((string) $typeNode->type);

            if ($base === 'list' || $base === 'non-empty-list') {
                $valueType = $typeNode->genericTypes[0] ?? null;
                [$valOp, $valExtra] = self::compileCheckOp($valueType);

                return [
                    'isList' => true,
                    'quoteKey' => false,
                    'keyType' => null,
                    'valueType' => $valueType,
                    'keyOp' => self::OP_NONE,
                    'keyExtra' => null,
                    'valOp' => $valOp,
                    'valExtra' => $valExtra,
                ];
            }

            $genericCount = \count($typeNode->genericTypes);
            $keyType = $genericCount >= 2 ? $typeNode->genericTypes[0] : null;
            $valueType = $genericCount >= 2
                ? $typeNode->genericTypes[1]
                : ($typeNode->genericTypes[0] ?? null);

            [$keyOp, $keyExtra] = self::compileCheckOp($keyType);
            [$valOp, $valExtra] = self::compileCheckOp($valueType);

            return [
                'isList' => false,
                'quoteKey' => true,
                'keyType' => $keyType,
                'valueType' => $valueType,
                'keyOp' => $keyOp,
                'keyExtra' => $keyExtra,
                'valOp' => $valOp,
                'valExtra' => $valExtra,
            ];
        }

        if ($typeNode instanceof ArrayTypeNode) {
            $valueType = $typeNode->type;
            [$valOp, $valExtra] = self::compileCheckOp($valueType);

            return [
                'isList' => false,
                'quoteKey' => false,
                'keyType' => null,
                'valueType' => $valueType,
                'keyOp' => self::OP_NONE,
                'keyExtra' => null,
                'valOp' => $valOp,
                'valExtra' => $valExtra,
            ];
        }

        return null;
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

    /**
     * Safely reads the current value of an instance or static property across all visibilities.
     */
    private static function readPropertyValue(mixed $objectOrClass, string $className, string $propName): mixed
    {
        try {
            $refProp = self::getReflectionProperty($className, $propName);

            if ($refProp !== null) {
                if ($refProp->isStatic()) {
                    return $refProp->isInitialized() ? $refProp->getValue() : [];
                }

                if (\is_object($objectOrClass)) {
                    return $refProp->isInitialized($objectOrClass) ? $refProp->getValue($objectOrClass) : [];
                }
            } elseif (\is_object($objectOrClass)) {
                // @phpstan-ignore property.dynamicName
                return $objectOrClass->$propName ?? [];
            }
        } catch (\Throwable) {
        }

        return [];
    }

    /**
     * Resolves and memoizes ReflectionProperty descriptors, caching both declared properties and dynamic misses.
     */
    private static function getReflectionProperty(string $className, string $propName): ?ReflectionProperty
    {
        if (isset(self::$refPropertyCache[$className]) && \array_key_exists($propName, self::$refPropertyCache[$className])) {
            return self::$refPropertyCache[$className][$propName];
        }

        if (property_exists($className, $propName)) {
            /** @var class-string<object> $className */
            return self::$refPropertyCache[$className][$propName] = new ReflectionProperty($className, $propName);
        }

        return self::$refPropertyCache[$className][$propName] = null;
    }
}
