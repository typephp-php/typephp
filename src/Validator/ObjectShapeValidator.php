<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;

/**
 * @internal Validates stdClass dynamic properties and custom class instances against PHPDoc object shape structures.
 */
final class ObjectShapeValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! \is_object($value)) {
            return ErrorFactory::createError($context . ' must be of type object, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ObjectShapeNode $shapeNode */
        $shapeNode = $node;

        if ($value instanceof \stdClass) {
            foreach ($shapeNode->items as $item) {
                $propName = (string) $item->keyName;

                if (! property_exists($value, $propName) && ! isset($value->$propName)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " is missing required property '$propName'");
                    }

                    continue;
                }

                $propValue = $value->$propName;

                $err = $registry->validate($propValue, $item->valueType, '');
                if ($err !== null) {
                    return ErrorFactory::createError($context . "->{$propName}" . $err->getMessage());
                }
            }

            return null;
        }

        $refObject = new \ReflectionObject($value);

        foreach ($shapeNode->items as $item) {
            $propName = (string) $item->keyName;

            // @phpstan-ignore property.dynamicName
            if (! $refObject->hasProperty($propName) && ! isset($value->$propName)) {
                if (! $item->optional) {
                    return ErrorFactory::createError($context . " is missing required property '$propName'");
                }

                continue;
            }

            if ($refObject->hasProperty($propName)) {
                $refProp = $refObject->getProperty($propName);
                if (! $refProp->isInitialized($value)) {
                    if (! $item->optional) {
                        return ErrorFactory::createError($context . " property '$propName' is uninitialized");
                    }

                    continue;
                }

                $propValue = $refProp->getValue($value);
            } else {
                // @phpstan-ignore property.dynamicName
                $propValue = $value->$propName;
            }

            $err = $registry->validate($propValue, $item->valueType, '');
            if ($err !== null) {
                return ErrorFactory::createError($context . "->{$propName}" . $err->getMessage());
            }
        }

        return null;
    }
}
