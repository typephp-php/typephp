<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Util\ClassNameValidator;

/**
 * Registry mapping AST TypeNodes to their corresponding validator strategy implementations.
 */
final class TypeValidatorRegistry
{
    private IdentifierValidator $identifierValidator;

    private GenericValidator $genericValidator;

    private UnionValidator $unionValidator;

    private IntersectionValidator $intersectionValidator;

    private NullableValidator $nullableValidator;

    private ArrayValidator $arrayValidator;

    private ArrayShapeValidator $arrayShapeValidator;

    private ObjectShapeValidator $objectShapeValidator;

    private ConstValidator $constValidator;

    private CallableValidator $callableValidator;

    public static function reset(): void
    {
        GenericValidator::reset();
        ArrayShapeValidator::reset();
        ObjectShapeValidator::reset();
        ConstValidator::reset();
    }

    public function __construct()
    {
        $this->identifierValidator = new IdentifierValidator();
        $this->genericValidator = new GenericValidator();
        $this->unionValidator = new UnionValidator();
        $this->intersectionValidator = new IntersectionValidator();
        $this->nullableValidator = new NullableValidator();
        $this->arrayValidator = new ArrayValidator();
        $this->arrayShapeValidator = new ArrayShapeValidator();
        $this->objectShapeValidator = new ObjectShapeValidator();
        $this->constValidator = new ConstValidator();
        $this->callableValidator = new CallableValidator();
    }

    /**
     * Validates a value against an AST TypeNode and returns an ErrorMessage on failure or null on success.
     */
    public function validate(mixed $value, TypeNode $node, string $context = '', bool $isSensitive = false): ?ErrorMessage
    {
        if ($node instanceof IdentifierTypeNode) {
            $name = $node->name;
            $ok = match ($name) {
                'int', 'integer' => \is_int($value),
                'string' => \is_string($value),
                'positive-int' => \is_int($value) && $value > 0,
                'non-empty-string' => \is_string($value) && $value !== '',
                'bool', 'boolean' => \is_bool($value),
                'float', 'double' => \is_float($value) || \is_int($value),
                'array-key' => \is_int($value) || \is_string($value),
                'array' => \is_array($value),
                'list' => \is_array($value) && ($value === [] || array_is_list($value)),
                'non-empty-array' => \is_array($value) && \count($value) > 0,
                'non-empty-list' => \is_array($value) && \count($value) > 0 && array_is_list($value),
                'non-negative-int', 'unsigned-int' => \is_int($value) && $value >= 0,
                'negative-int' => \is_int($value) && $value < 0,
                'non-positive-int' => \is_int($value) && $value <= 0,
                'non-zero-int' => \is_int($value) && $value !== 0,
                'mixed' => true,
                'null', 'void' => $value === null,
                'true' => $value === true,
                'false' => $value === false,
                'scalar' => \is_scalar($value),
                'object' => \is_object($value),
                'class-string' => \is_string($value) && ClassNameValidator::isValidClassString($value),
                'iterable' => is_iterable($value),
                'numeric', 'number' => \is_int($value) || \is_float($value) || (\is_string($value) && is_numeric($value)),
                default => \is_object($value) && ($value instanceof $name),
            };

            if ($ok) {
                return null;
            }

            return $this->identifierValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof GenericTypeNode) {
            return $this->genericValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof ArrayShapeNode) {
            return $this->arrayShapeValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof UnionTypeNode) {
            return $this->unionValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof NullableTypeNode) {
            return $this->nullableValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof ArrayTypeNode) {
            return $this->arrayValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof ConstTypeNode) {
            return $this->constValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof ObjectShapeNode) {
            return $this->objectShapeValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof IntersectionTypeNode) {
            return $this->intersectionValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        if ($node instanceof CallableTypeNode) {
            return $this->callableValidator->validate($value, $node, $context, $this, $isSensitive);
        }

        return null;
    }
}
