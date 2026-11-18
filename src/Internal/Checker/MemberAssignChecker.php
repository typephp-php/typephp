<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionProperty;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StructureMutator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates array key/dimension and object shape property member assignments.
 */
final class MemberAssignChecker
{
    /**
     * In-memory 2D cache for ReflectionProperty instances:
     * [$className][$propName] => ?ReflectionProperty (null = non-declared/dynamic property)
     *
     * @var array<string, array<string, ?ReflectionProperty>>
     */
    private static array $refPropertyCache = [];

    /**
     * Per-property raw contract cache:
     * [$className][$propName] => ?array{rawType: TypeNode, classTemplates: array<string, \PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode>, usesTemplates: bool}
     *
     * @var array<string, array<string, ?array{
     *     rawType: TypeNode,
     *     classTemplates: array<string, \PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode>,
     *     usesTemplates: bool
     * }>>
     */
    private static array $propertyMutationPlanCache = [];

    /**
     * Substituted element contract cache keyed by template binding signature:
     * [$className][$propName][$sig] => ?array{isList: bool, keyType: ?TypeNode, valueType: ?TypeNode, keyIsMixed: bool, keyIsArrayKey: bool, valueIsMixed: bool}
     *
     * @var array<string, array<string, array<string, ?array{
     *     isList: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyIsMixed: bool,
     *     keyIsArrayKey: bool,
     *     valueIsMixed: bool
     * }>>>
     */
    private static array $substitutedElementContractCache = [];

    /**
     * Resets the cached reflection property and element contract registries.
     */
    public static function reset(): void
    {
        self::$refPropertyCache = [];
        self::$propertyMutationPlanCache = [];
        self::$substitutedElementContractCache = [];
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
        TypeValidatorRegistry $registry
    ): mixed {
        if (! \is_object($objectOrClass) && ! \is_string($objectOrClass)) {
            return $value;
        }

        $className = \is_string($objectOrClass) ? $objectOrClass : $objectOrClass::class;

        if (isset(InlineChecker::$nullPropertyCache[$className][$propName]) || ! RuntimeTypeChecker::$inlinePropertiesEnabled) {
            return $value;
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
            $directErr = self::validatePropertyElementMutation(
                $objectOrClass,
                $className,
                $propName,
                $chain,
                $value,
                $registry
            );

            if ($directErr instanceof ErrorMessage) {
                return $directErr;
            }

            return $value;
        }

        if ($isObjectDimAccess) {
            return $value;
        }

        $copy = StructureMutator::mutateCopy($currentVal, $chain, $value);

        $res = InlineChecker::checkProperty($copy, $objectOrClass, $propName, $file, $registry);

        if ($res instanceof ErrorMessage) {
            return $res;
        }

        return $value;
    }

    /**
     * Directly validates a single element appended/assigned to a large property array or generic ArrayAccess object in O(1).
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    private static function validatePropertyElementMutation(
        mixed $objectOrClass,
        string $className,
        string $propName,
        array $chain,
        mixed $value,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        try {
            $contract = self::resolveElementContract($objectOrClass, $className, $propName);
            if ($contract === null) {
                return null;
            }

            $key = $chain[0][1];

            if ($contract['isList']) {
                if ($key !== null && ! \is_int($key)) {
                    $keyStr = \is_string($key) ? $key : '';

                    return ErrorFactory::createError("Property {$className}::\${$propName} must be a list, non-integer key '{$keyStr}' given");
                }

                $valueType = $contract['valueType'];
                if ($valueType !== null && ! $contract['valueIsMixed']) {
                    $valOk = $valueType instanceof IdentifierTypeNode && match ($valueType->name) {
                        'int', 'integer' => \is_int($value),
                        'string' => \is_string($value),
                        'bool', 'boolean' => \is_bool($value),
                        'float', 'double' => \is_float($value) || \is_int($value),
                        'positive-int' => \is_int($value) && $value > 0,
                        'non-empty-string' => \is_string($value) && $value !== '',
                        'array-key' => \is_int($value) || \is_string($value),
                        'array' => \is_array($value),
                        'object' => \is_object($value),
                        default => \is_object($value) && ($value instanceof $valueType->name),
                    };

                    if (! $valOk) {
                        $keyStr = \is_string($key) ? $key : (\is_int($key) ? (string) $key : '');
                        $formattedKey = $key !== null ? ($contract['quoteKey'] ? "['{$keyStr}']" : "[{$keyStr}]") : '[]';
                        $context = "Property {$className}::\${$propName}" . $formattedKey;

                        return $registry->validate($value, $valueType, $context);
                    }
                }

                return null;
            }

            $keyType = $contract['keyType'];
            if ($key !== null && $keyType !== null && ! $contract['keyIsMixed']) {
                $keyOk = $contract['keyIsArrayKey']
                    ? (\is_int($key) || \is_string($key))
                    : ($keyType instanceof IdentifierTypeNode && match ($keyType->name) {
                        'int', 'integer' => \is_int($key),
                        'string' => \is_string($key),
                        'non-empty-string' => \is_string($key) && $key !== '',
                        'positive-int' => \is_int($key) && $key > 0,
                        default => false,
                    });

                if (! $keyOk) {
                    $keyErr = $registry->validate($key, $keyType, "Property {$className}::\${$propName} key");
                    if ($keyErr !== null) {
                        return $keyErr;
                    }
                }
            }

            $valueType = $contract['valueType'];
            if ($valueType !== null && ! $contract['valueIsMixed']) {
                $valOk = $valueType instanceof IdentifierTypeNode && match ($valueType->name) {
                    'int', 'integer' => \is_int($value),
                    'string' => \is_string($value),
                    'bool', 'boolean' => \is_bool($value),
                    'float', 'double' => \is_float($value) || \is_int($value),
                    'positive-int' => \is_int($value) && $value > 0,
                    'non-empty-string' => \is_string($value) && $value !== '',
                    'array-key' => \is_int($value) || \is_string($value),
                    'array' => \is_array($value),
                    'object' => \is_object($value),
                    default => \is_object($value) && ($value instanceof $valueType->name),
                };

                if (! $valOk) {
                    $keyStr = \is_string($key) ? $key : (\is_int($key) ? (string) $key : '');
                    $formattedKey = $key !== null ? ($contract['quoteKey'] ? "['{$keyStr}']" : "[{$keyStr}]") : '[]';
                    $context = "Property {$className}::\${$propName}" . $formattedKey;

                    return $registry->validate($value, $valueType, $context);
                }
            }
        } catch (\Throwable) {
        }

        return null;
    }

    /**
     * Resolves and memoizes the substituted key/value element contract for a property mutation.
     *
     * @return ?array{
     *     isList: bool,
     *     quoteKey: bool,
     *     keyType: ?TypeNode,
     *     valueType: ?TypeNode,
     *     keyIsMixed: bool,
     *     keyIsArrayKey: bool,
     *     valueIsMixed: bool
     * }
     */
    private static function resolveElementContract(mixed $objectOrClass, string $className, string $propName): ?array
    {
        if (! isset(self::$propertyMutationPlanCache[$className]) || ! \array_key_exists($propName, self::$propertyMutationPlanCache[$className])) {
            $rawTypeNode = DocblockParser::parseProperty($className, $propName);
            if ($rawTypeNode === null) {
                InlineChecker::$nullPropertyCache[$className][$propName] = true;
                self::$propertyMutationPlanCache[$className][$propName] = null;

                return null;
            }

            $classTemplates = DocblockParser::parseClassTemplates($className);
            $usesTemplates = $classTemplates !== [] && DocblockParser::typeReferencesTemplate($rawTypeNode, $classTemplates);

            self::$propertyMutationPlanCache[$className][$propName] = [
                'rawType' => $rawTypeNode,
                'classTemplates' => $classTemplates,
                'usesTemplates' => $usesTemplates,
            ];
        }

        $propPlan = self::$propertyMutationPlanCache[$className][$propName];
        if ($propPlan === null) {
            return null;
        }

        $sig = '';
        $boundTemplates = [];
        if ($propPlan['usesTemplates'] && \is_object($objectOrClass)) {
            $boundTemplates = TemplateManager::getBoundTemplatesForInstance($objectOrClass);
            if ($boundTemplates !== []) {
                foreach ($boundTemplates as $k => $v) {
                    $sig .= $k . '=' . ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
                }
            }
        }

        if (isset(self::$substitutedElementContractCache[$className][$propName]) && \array_key_exists($sig, self::$substitutedElementContractCache[$className][$propName])) {
            return self::$substitutedElementContractCache[$className][$propName][$sig];
        }

        $typeNode = $propPlan['rawType'];
        if ($sig !== '') {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $propPlan['classTemplates']);
        }

        $contract = null;

        if ($typeNode instanceof GenericTypeNode) {
            $base = strtolower((string) $typeNode->type);

            if ($base === 'list' || $base === 'non-empty-list') {
                $valueType = $typeNode->genericTypes[0] ?? null;
                $contract = [
                    'isList' => true,
                    'quoteKey' => false,
                    'keyType' => null,
                    'valueType' => $valueType,
                    'keyIsMixed' => false,
                    'keyIsArrayKey' => false,
                    'valueIsMixed' => $valueType instanceof IdentifierTypeNode && strtolower($valueType->name) === 'mixed',
                ];
            } else {
                $genericCount = \count($typeNode->genericTypes);
                $keyType = $genericCount >= 2 ? $typeNode->genericTypes[0] : null;
                $valueType = $genericCount >= 2
                    ? $typeNode->genericTypes[1]
                    : ($typeNode->genericTypes[0] ?? null);

                $contract = [
                    'isList' => false,
                    'quoteKey' => true,
                    'keyType' => $keyType,
                    'valueType' => $valueType,
                    'keyIsMixed' => $keyType instanceof IdentifierTypeNode && strtolower($keyType->name) === 'mixed',
                    'keyIsArrayKey' => self::isArrayKeyType($keyType),
                    'valueIsMixed' => $valueType instanceof IdentifierTypeNode && strtolower($valueType->name) === 'mixed',
                ];
            }
        } elseif ($typeNode instanceof ArrayTypeNode) {
            $valueType = $typeNode->type;
            $contract = [
                'isList' => false,
                'quoteKey' => false,
                'keyType' => null,
                'valueType' => $valueType,
                'keyIsMixed' => false,
                'keyIsArrayKey' => false,
                'valueIsMixed' => $valueType instanceof IdentifierTypeNode && strtolower($valueType->name) === 'mixed',
            ];
        }

        return self::$substitutedElementContractCache[$className][$propName][$sig] = $contract;
    }

    private static function isArrayKeyType(?TypeNode $node): bool
    {
        if ($node instanceof IdentifierTypeNode) {
            return strtolower($node->name) === 'array-key';
        }

        if ($node instanceof UnionTypeNode && \count($node->types) === 2) {
            $t0 = $node->types[0];
            $t1 = $node->types[1];
            if ($t0 instanceof IdentifierTypeNode && $t1 instanceof IdentifierTypeNode) {
                $n0 = strtolower($t0->name);
                $n1 = strtolower($t1->name);

                return ($n0 === 'int' && $n1 === 'string') || ($n0 === 'string' && $n1 === 'int');
            }
        }

        return false;
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
