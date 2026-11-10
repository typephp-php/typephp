<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use ReflectionProperty;
use TypePHP\Internal\Diagnostic\DebugLog;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
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
     * Resets the cached reflection property registry.
     */
    public static function reset(): void
    {
        self::$refPropertyCache = [];
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
            (Config::isArrayValidationHybrid() || $isObjectDimAccess)
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

        if (isset(InlineChecker::$nullPropertyCache[$className][$propName]) || ! Config::isInlinePropertiesEnabled()) {
            return $value;
        }

        $currentVal = self::readPropertyValue($objectOrClass, $className, $propName);
        $isObjectDimAccess = \is_object($currentVal) && ($chain[0][0] ?? null) === 'dim';

        if (
            (
                (Config::isArrayValidationHybrid() && \is_array($currentVal) && \count($currentVal) > Config::HYBRID_SAMPLE_THRESHOLD)
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
                $file,
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
        string $file,
        TypeValidatorRegistry $registry
    ): ?ErrorMessage {
        try {
            $rawTypeNode = DocblockParser::parseProperty($className, $propName);
            if ($rawTypeNode === null) {
                return null;
            }

            $typeNode = $rawTypeNode;
            if (\is_object($objectOrClass)) {
                $allTemplates = DocblockParser::parseClassTemplates($className);
                if ($allTemplates !== []) {
                    $boundTemplates = TemplateManager::getBoundTemplates('none', $objectOrClass, $allTemplates);
                    if ($boundTemplates !== []) {
                        $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $allTemplates);
                    }
                }
            }

            $key = $chain[0][1];
            $keyStr = \is_string($key) ? $key : (\is_int($key) ? (string) $key : '');

            if ($typeNode instanceof GenericTypeNode) {
                $base = strtolower((string) $typeNode->type);

                if ($base === 'list' || $base === 'non-empty-list') {
                    if ($key !== null && ! \is_int($key)) {
                        return ErrorFactory::createError("Property {$className}::\${$propName} must be a list, non-integer key '{$keyStr}' given");
                    }

                    $valueType = $typeNode->genericTypes[0] ?? null;
                    if ($valueType !== null) {
                        $context = "Property {$className}::\${$propName}" . ($key !== null ? "[{$key}]" : '[]');

                        return $registry->validate($value, $valueType, $context);
                    }
                } else {
                    $genericCount = \count($typeNode->genericTypes);

                    if ($genericCount >= 2 && $key !== null) {
                        $keyType = $typeNode->genericTypes[0];
                        $keyErr = $registry->validate($key, $keyType, "Property {$className}::\${$propName} key");
                        if ($keyErr !== null) {
                            return $keyErr;
                        }
                    }

                    $valueType = $genericCount >= 2
                        ? $typeNode->genericTypes[1]
                        : ($typeNode->genericTypes[0] ?? null);

                    if ($valueType !== null) {
                        $context = "Property {$className}::\${$propName}" . ($key !== null ? "['{$keyStr}']" : '[]');

                        return $registry->validate($value, $valueType, $context);
                    }
                }
            } elseif ($typeNode instanceof ArrayTypeNode) {
                $context = "Property {$className}::\${$propName}" . ($key !== null ? "[{$keyStr}]" : '[]');

                return $registry->validate($value, $typeNode->type, $context);
            }
        } catch (\Throwable $e) {
            DebugLog::swallowed($e);
        }

        return null;
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
        } catch (\Throwable $e) {
            DebugLog::swallowed($e);
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
