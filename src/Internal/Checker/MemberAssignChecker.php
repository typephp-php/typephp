<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use ReflectionProperty;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\StructureMutator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates array key/dimension and object shape property member assignments.
 */
final class MemberAssignChecker
{
    /**
     * In-memory 2D cache for ReflectionProperty instances: [$className][$propName] => ReflectionProperty.
     *
     * @var array<string, array<string, ReflectionProperty>>
     */
    private static array $refPropertyCache = [];

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
        if (Config::isArrayValidationHybrid() && ! str_contains($typeString, '{') && \count($chain) === 1 && $chain[0][0] === 'dim') {
            $directErr = InlineChecker::validateCollectionElementMutation(
                $value,
                $chain,
                $typeString,
                $varName,
                $file,
                $registry
            );

            if ($directErr instanceof ErrorMessage) {
                return $directErr;
            }

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
     * Evaluates dimension/member mutation assignment on an object or static class property.
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
        $copy = StructureMutator::mutateCopy($currentVal, $chain, $value);

        $res = InlineChecker::checkProperty($copy, $objectOrClass, $propName, $file, $registry);

        if ($res instanceof ErrorMessage) {
            return $res;
        }

        return $value;
    }

    /**
     * Safely reads the current value of an instance or static property across all visibilities.
     */
    private static function readPropertyValue(mixed $objectOrClass, string $className, string $propName): mixed
    {
        try {
            if (property_exists($className, $propName)) {
                /** @var class-string<object> $className */
                $refProp = self::$refPropertyCache[$className][$propName] ??= new ReflectionProperty($className, $propName);

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
}
