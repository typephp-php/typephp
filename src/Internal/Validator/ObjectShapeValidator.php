<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionProperty;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;

/**
 * @internal Validates stdClass dynamic properties and custom class instances against PHPDoc object shape structures.
 */
final class ObjectShapeValidator implements TypeValidatorInterface
{
    /**
     * Cache for ReflectionClass instances per class name.
     *
     * @var array<class-string<object>, ReflectionClass<object>>
     */
    private static array $refClassCache = [];

    /**
     * Cache for ReflectionProperty instances per class name and property name.
     * Stored as false if the property is not declared on the class (dynamic or magic).
     *
     * @var array<class-string<object>, array<string, ReflectionProperty|false>>
     */
    private static array $propertyCache = [];

    public function validate(
        mixed $value,
        TypeNode $node,
        string $context,
        TypeValidatorRegistry $registry,
        bool $isSensitive = false
    ): ?ErrorMessage {
        if (! \is_object($value)) {
            return ErrorFactory::createError($context . ' must be of type object, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        /** @var ObjectShapeNode $shapeNode */
        $shapeNode = $node;

        if ($value instanceof \stdClass) {
            foreach ($shapeNode->items as $item) {
                $propName = $item->keyName instanceof IdentifierTypeNode
                    ? $item->keyName->name
                    : (string) $item->keyName;

                if (! isset($value->$propName) && ! property_exists($value, $propName)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " is missing required property '$propName'");
                    }

                    continue;
                }

                $propValue = $value->$propName;

                $err = $registry->validate($propValue, $item->valueType, '', $isSensitive);
                if ($err !== null) {
                    return ErrorFactory::createError($context . "->{$propName}" . $err->getMessage());
                }
            }

            return null;
        }

        /** @var class-string<object> $className */
        $className = $value::class;

        foreach ($shapeNode->items as $item) {
            $propName = $item->keyName instanceof IdentifierTypeNode
                ? $item->keyName->name
                : (string) $item->keyName;

            if (! isset(self::$propertyCache[$className][$propName]) && ! \array_key_exists($propName, self::$propertyCache[$className] ?? [])) {
                $refClass = self::$refClassCache[$className] ??= new ReflectionClass($className);
                self::$propertyCache[$className][$propName] = $refClass->hasProperty($propName)
                    ? $refClass->getProperty($propName)
                    : false;
            }

            $refProp = self::$propertyCache[$className][$propName];

            if ($refProp instanceof ReflectionProperty) {
                if (! $refProp->isInitialized($value)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " property '$propName' is uninitialized");
                    }

                    continue;
                }

                $propValue = $refProp->getValue($value);
            } else {
                if (! isset($value->$propName) && ! property_exists($value, $propName)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " is missing required property '$propName'");
                    }

                    continue;
                }

                // @phpstan-ignore property.dynamicName
                $propValue = $value->$propName;
            }

            $err = $registry->validate($propValue, $item->valueType, '', $isSensitive);
            if ($err !== null) {
                return ErrorFactory::createError($context . "->{$propName}" . $err->getMessage());
            }
        }

        return null;
    }
}