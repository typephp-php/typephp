<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Stringable;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\ClassNameValidator;

/**
 * @internal Class for validating union types like int | string.
 */
final class UnionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var UnionTypeNode $unionNode */
        $unionNode = $node;

        foreach ($unionNode->types as $type) {
            if ($type instanceof IdentifierTypeNode) {
                $name = strtolower($type->name);
                if ($name === 'object' && \is_object($value)) {
                    return null;
                }
                if (($name === 'int' || $name === 'integer') && \is_int($value)) {
                    return null;
                }
                if ($name === 'string' && \is_string($value)) {
                    return null;
                }
                if (($name === 'bool' || $name === 'boolean') && \is_bool($value)) {
                    return null;
                }
                if ($name === 'null' && $value === null) {
                    return null;
                }
                if ($name === 'class-string' && \is_string($value) && ClassNameValidator::isValidClassString($value)) {
                    return null;
                }
            }
        }

        $deepErrors = [];
        $discriminatorMatchedErrors = [];
        $hasAnyDiscriminator = false;

        foreach ($unionNode->types as $type) {
            $err = $registry->validate($value, $type, $context, $isSensitive);
            if ($err === null) {
                return null;
            }

            $msg = $err->getMessage();

            $isDeep = (
                str_starts_with($msg, $context . '[') ||
                str_starts_with($msg, $context . '->') ||
                str_starts_with($msg, $context . ' is missing required') ||
                str_starts_with($msg, $context . ' contains unsealed') ||
                str_starts_with($msg, $context . ' property') ||
                str_starts_with($msg, $context . ' key') ||
                str_starts_with($msg, $context . ' value') ||
                str_starts_with($msg, $context . ' extra key')
            );

            if ($isDeep) {
                $deepErrors[] = $err;

                $discStatus = self::checkDiscriminatorStatus($value, $type);
                if ($discStatus['hasDiscriminator']) {
                    $hasAnyDiscriminator = true;
                    if ($discStatus['isMatched']) {
                        $discriminatorMatchedErrors[] = $err;
                    }
                }
            }
        }

        if (\count($discriminatorMatchedErrors) > 0) {
            return $discriminatorMatchedErrors[0];
        }

        if ($hasAnyDiscriminator) {
            return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (\count($deepErrors) > 0) {
            return $deepErrors[0];
        }

        return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
    }

    /**
     * Inspects a type branch to see if it defines a discriminator field and whether the input value matches it.
     *
     * @return array{hasDiscriminator: bool, isMatched: bool}
     */
    private static function checkDiscriminatorStatus(mixed $value, TypeNode $type): array
    {
        if (\is_array($value) && $type instanceof ArrayShapeNode) {
            foreach ($type->items as $item) {
                if ($item->valueType instanceof ConstTypeNode) {
                    $key = null;
                    if ($item->keyName instanceof ConstExprStringNode) {
                        $key = $item->keyName->value;
                    } elseif ($item->keyName instanceof IdentifierTypeNode) {
                        $key = $item->keyName->name;
                    } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                        $key = (int) $item->keyName->value;
                    }

                    if ($key !== null) {
                        $expectedVal = (string) $item->valueType->constExpr;
                        $expectedVal = trim($expectedVal, '\'"');

                        if (isset($value[$key])) {
                            $actualVal = $value[$key];
                            $actualStr = \is_scalar($actualVal) || $actualVal instanceof Stringable ? (string) $actualVal : null;

                            if ($actualStr === $expectedVal) {
                                return ['hasDiscriminator' => true, 'isMatched' => true];
                            }

                            return ['hasDiscriminator' => true, 'isMatched' => false];
                        }
                    }
                }
            }
        }

        if (\is_object($value) && $type instanceof ObjectShapeNode) {
            foreach ($type->items as $item) {
                if ($item->valueType instanceof ConstTypeNode) {
                    $prop = (string) $item->keyName;
                    $expectedVal = (string) $item->valueType->constExpr;
                    $expectedVal = trim($expectedVal, '\'"');

                    // @phpstan-ignore property.dynamicName
                    if (isset($value->$prop)) {
                        // @phpstan-ignore property.dynamicName
                        $actualVal = $value->$prop;
                        $actualStr = \is_scalar($actualVal) || $actualVal instanceof Stringable ? (string) $actualVal : null;

                        if ($actualStr === $expectedVal) {
                            return ['hasDiscriminator' => true, 'isMatched' => true];
                        }

                        return ['hasDiscriminator' => true, 'isMatched' => false];
                    }
                }
            }
        }

        return ['hasDiscriminator' => false, 'isMatched' => false];
    }
}
