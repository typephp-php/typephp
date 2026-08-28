<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Class for validating basic scalar identifier types like int, string, bool, array, list, object, callable, resource, null, true, false, mixed, scalar, void.
 */
final class IdentifierValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        /** @var IdentifierTypeNode $identifierNode */
        $identifierNode = $node;
        $name = $identifierNode->name;
        $lower = strtolower($name);

        $ok = match ($lower) {
            'int', 'integer' => \is_int($value),
            'string' => \is_string($value),
            'float', 'double' => \is_float($value) || \is_int($value),
            'bool', 'boolean' => \is_bool($value),
            'array' => \is_array($value),
            'list' => \is_array($value) && (\count($value) === 0 || array_is_list($value)),
            'object', 'self', 'static', 'parent', '$this' => \is_object($value),
            'callable', 'pure-callable' => \is_callable($value),
            'iterable' => is_iterable($value),
            'resource' => \is_resource($value),
            'null' => $value === null,
            'true' => $value === true,
            'false' => $value === false,
            'mixed' => true,
            'scalar' => \is_scalar($value),
            'void' => $value === null,
            'never', 'never-return', 'never-returns', 'no-return' => false,
            'positive-int' => \is_int($value) && $value > 0,
            'negative-int' => \is_int($value) && $value < 0,
            'non-positive-int' => \is_int($value) && $value <= 0,
            'non-negative-int' => \is_int($value) && $value >= 0,
            'non-zero-int' => \is_int($value) && $value !== 0,
            'unsigned-int' => \is_int($value) && $value >= 0,
            'positive-float' => (\is_float($value) || \is_int($value)) && $value > 0,
            'negative-float' => (\is_float($value) || \is_int($value)) && $value < 0,
            'non-positive-float' => (\is_float($value) || \is_int($value)) && $value <= 0,
            'non-negative-float' => (\is_float($value) || \is_int($value)) && $value >= 0,
            'non-zero-float' => (\is_float($value) || \is_int($value)) && $value !== 0 && $value !== 0.0,
            'class-string' => \is_string($value)
                && ClassNameValidator::isValid($value)
                && (class_exists($value) || interface_exists($value) || trait_exists($value) || enum_exists($value)),
            'interface-string' => \is_string($value) && interface_exists($value),
            'trait-string' => \is_string($value) && trait_exists($value),
            'enum-string' => \is_string($value) && enum_exists($value),
            'callable-string' => \is_string($value) && \is_callable($value),
            'numeric-string' => \is_string($value) && is_numeric($value),
            'non-empty-string' => \is_string($value) && $value !== '',
            'lowercase-string' => \is_string($value) && strtolower($value) === $value,
            'non-empty-lowercase-string' => \is_string($value) && $value !== '' && strtolower($value) === $value,
            'uppercase-string' => \is_string($value) && strtoupper($value) === $value,
            'non-empty-uppercase-string' => \is_string($value) && $value !== '' && strtoupper($value) === $value,
            'array-key' => \is_int($value) || \is_string($value),
            'literal-string' => \is_string($value),
            'truthy-string', 'non-falsy-string' => \is_string($value) && (bool) $value === true,
            'non-empty-array' => \is_array($value) && \count($value) > 0,
            'non-empty-list' => \is_array($value) && \count($value) > 0 && array_is_list($value),
            'number', 'numeric' => \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)),
            'truthy' => (bool) $value === true,
            'falsy', 'falsey' => (bool) $value === false,
            'open-resource' => \is_resource($value),
            'closed-resource' => ! \is_resource($value) && get_debug_type($value) === 'resource (closed)',

            default => $this->validateClassOrIgnore($value, $name),
        };

        if (! $ok) {
            return ErrorFactory::createError($context . ' must be of type ' . $identifierNode->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }

    private function validateClassOrIgnore(mixed $value, string $name): bool
    {
        if (! ClassNameValidator::isValid($name)) {
            return true;
        }

        return \is_object($value) && is_a($value, $name);
    }
}
