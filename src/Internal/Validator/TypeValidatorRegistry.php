<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;

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

    /**
     * Static map for fast validator resolution.
     *
     * @var array<string, TypeValidatorInterface>
     */
    private array $validatorMap;

    public static function reset(): void
    {
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

        $this->validatorMap = [
            IdentifierTypeNode::class => $this->identifierValidator,
            GenericTypeNode::class => $this->genericValidator,
            UnionTypeNode::class => $this->unionValidator,
            NullableTypeNode::class => $this->nullableValidator,
            ArrayTypeNode::class => $this->arrayValidator,
            ArrayShapeNode::class => $this->arrayShapeValidator,
            ObjectShapeNode::class => $this->objectShapeValidator,
            IntersectionTypeNode::class => $this->intersectionValidator,
            ConstTypeNode::class => $this->constValidator,
        ];
    }

    /**
     * Validates a value against an AST TypeNode and returns an ErrorMessage on failure or null on success.
     */
    public function validate(mixed $value, TypeNode $node, string $context = ''): ?ErrorMessage
    {
        $validator = $this->validatorMap[$node::class] ?? null;
        if ($validator === null) {
            return null;
        }

        return $validator->validate($value, $node, $context, $this);
    }
}
