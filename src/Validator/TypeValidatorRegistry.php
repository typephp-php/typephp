<?php

declare(strict_types=1);

namespace TypePHP\Validator;

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
use TypePHP\Internal\ErrorMessage;

/**
 * Registry mapping AST TypeNodes to their corresponding validator strategy implementations.
 */
final class TypeValidatorRegistry
{
    private IdentifierValidator $identifierValidator;

    /**
     * @var array<string, TypeValidatorInterface>
     */
    private array $validators = [];

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
        $this->validators = [
            IdentifierTypeNode::class => $this->identifierValidator,
            GenericTypeNode::class => new GenericValidator(),
            UnionTypeNode::class => new UnionValidator(),
            IntersectionTypeNode::class => new IntersectionValidator(),
            NullableTypeNode::class => new NullableValidator(),
            ArrayTypeNode::class => new ArrayValidator(),
            ArrayShapeNode::class => new ArrayShapeValidator(),
            ObjectShapeNode::class => new ObjectShapeValidator(),
            ConstTypeNode::class => new ConstValidator(),
        ];
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

        if ($node instanceof IdentifierTypeNode) {
            $err = $this->identifierValidator->validate($value, $node, $context, $this);
        } else {
            $validator = $this->validators[\get_class($node)] ?? null;
            if ($validator === null) {
                return null;
            }
            $err = $validator->validate($value, $node, $context, $this);
        }

        if ($err === null && $isObj && $nodeKey !== null) {
            $cache = self::$validatedObjectCache[$value] ?? [];
            $cache[$nodeKey] = true;
            self::$validatedObjectCache[$value] = $cache;
        }

        return $err;
    }
}
