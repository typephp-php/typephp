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
     * WeakMap memoizing previously validated object instances against TypeNode signatures.
     *
     * @var \WeakMap<object, array<string, bool>>|null
     */
    private static ?\WeakMap $validatedObjectCache = null;

    /**
     * Resets the validated object cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$validatedObjectCache = null;
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
    }

    /**
     * Validates a value against an AST TypeNode and returns an ErrorMessage on failure or null on success.
     */
    public function validate(mixed $value, TypeNode $node, string $context = ''): ?ErrorMessage
    {
        $isObj = \is_object($value);
        $nodeKey = null;

        // Object Validation Memoization Optimization (O(1) lookup for repeated object checks)
        if ($isObj) {
            self::$validatedObjectCache ??= new \WeakMap();
            $nodeKey = ($node instanceof IdentifierTypeNode) ? $node->name : (string) $node;

            if (isset(self::$validatedObjectCache[$value][$nodeKey])) {
                return null;
            }
        }

        $err = match ($node::class) {
            IdentifierTypeNode::class => $this->identifierValidator->validate($value, $node, $context, $this),
            GenericTypeNode::class => $this->genericValidator->validate($value, $node, $context, $this),
            UnionTypeNode::class => $this->unionValidator->validate($value, $node, $context, $this),
            NullableTypeNode::class => $this->nullableValidator->validate($value, $node, $context, $this),
            ArrayTypeNode::class => $this->arrayValidator->validate($value, $node, $context, $this),
            ArrayShapeNode::class => $this->arrayShapeValidator->validate($value, $node, $context, $this),
            ObjectShapeNode::class => $this->objectShapeValidator->validate($value, $node, $context, $this),
            IntersectionTypeNode::class => $this->intersectionValidator->validate($value, $node, $context, $this),
            ConstTypeNode::class => $this->constValidator->validate($value, $node, $context, $this),
            default => null,
        };

        if ($err === null && $isObj && $nodeKey !== null) {
            $cache = self::$validatedObjectCache[$value] ?? [];
            $cache[$nodeKey] = true;
            self::$validatedObjectCache[$value] = $cache;
        }

        return $err;
    }
}
