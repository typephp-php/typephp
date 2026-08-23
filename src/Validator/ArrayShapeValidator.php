<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Class for validating array shapes and tuple shapes like array{0: string, 1: int} or array{string, int}.
 */
final class ArrayShapeValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! \is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayShapeNode $shapeNode */
        $shapeNode = $node;
        $knownKeys = [];
        $nextAutoIndex = 0;
        $matchedKeysCount = 0;

        foreach ($shapeNode->items as $item) {
            $key = null;

            if ($item->keyName instanceof ConstExprStringNode) {
                $key = $item->keyName->value;
            } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                $key = (int) $item->keyName->value;
                $nextAutoIndex = max($nextAutoIndex, $key + 1);
            } elseif ($item->keyName instanceof IdentifierTypeNode) {
                $key = $item->keyName->name;
            } elseif ($item->keyName !== null) {
                $key = (string) $item->keyName;
            } else {
                $key = $nextAutoIndex;
                $nextAutoIndex++;
            }

            $knownKeys[$key] = true;

            if (! \array_key_exists($key, $value)) {
                if (! $item->optional) {
                    return ErrorFactory::createError($context . " is missing required key '$key'");
                }

                continue;
            }

            $matchedKeysCount++;

            $err = $registry->validate($value[$key], $item->valueType, '');
            if ($err !== null) {
                return ErrorFactory::createError($context . "['" . $key . "']" . $err->getMessage());
            }
        }

        $valueCount = \count($value);

        if ($valueCount === $matchedKeysCount) {
            return null;
        }

        if ($shapeNode->sealed) {
            foreach ($value as $k => $_) {
                if (! isset($knownKeys[$k])) {
                    return ErrorFactory::createError($context . " contains unsealed unexpected key '{$k}'");
                }
            }

            return null;
        }

        if ($shapeNode->unsealedType !== null) {
            $unsealedKeyType = $shapeNode->unsealedType->keyType;
            $unsealedValueType = $shapeNode->unsealedType->valueType;

            foreach ($value as $k => $v) {
                if (isset($knownKeys[$k])) {
                    continue;
                }

                if ($unsealedKeyType !== null) {
                    $err = $registry->validate($k, $unsealedKeyType, '');
                    if ($err !== null) {
                        return ErrorFactory::createError($context . " extra key '{$k}'" . $err->getMessage());
                    }
                }

                $err = $registry->validate($v, $unsealedValueType, '');
                if ($err !== null) {
                    return ErrorFactory::createError($context . "['{$k}']" . $err->getMessage());
                }
            }
        }

        return null;
    }
}
