<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use Generator;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Traversable;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * Validates array and Traversable collection instances against ArrayTypeNode ASTs (Type[]).
 *
 * @internal
 */
final class ArrayValidator implements TypeValidatorInterface
{
    /**
     * Validates an array or Traversable collection against an ArrayTypeNode (Type[]).
     * Accepts native arrays and Traversable objects (e.g. ArrayIterator, Symfony RewindableGenerator).
     * Bypasses eager iteration on Generator instances to prevent premature generator closure.
     */
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! \is_array($value) && ! ($value instanceof Traversable)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayTypeNode $arrayNode */
        $arrayNode = $node;

        if ($value instanceof Generator) {
            return null;
        }

        foreach ($value as $k => $v) {
            $err = $registry->validate($v, $arrayNode->type, $context . '[' . $k . ']');
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }
}