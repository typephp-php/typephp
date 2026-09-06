<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\ClassNameValidator;

/**
 * @internal Class for validating union types like int | string.
 */
final class UnionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
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

        foreach ($unionNode->types as $type) {
            $err = $registry->validate($value, $type, $context);
            if ($err === null) {
                return null;
            }

            $msg = $err->getMessage();

            if (
                str_starts_with($msg, $context . '[') ||
                str_starts_with($msg, $context . '->') ||
                str_starts_with($msg, $context . ' is missing required') ||
                str_starts_with($msg, $context . ' contains unsealed') ||
                str_starts_with($msg, $context . ' property') ||
                str_starts_with($msg, $context . ' key') ||
                str_starts_with($msg, $context . ' value') ||
                str_starts_with($msg, $context . ' extra key')
            ) {
                $deepErrors[] = $err;
            }
        }

        if (\count($deepErrors) > 0) {
            return $deepErrors[0];
        }

        return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
    }
}