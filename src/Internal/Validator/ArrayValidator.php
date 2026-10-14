<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use Generator;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Traversable;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;

/**
 * Validates array and Traversable collection instances against ArrayTypeNode ASTs (Type[]).
 * Supports both exhaustive O(n) verification and Beartype-style hybrid O(1) sampling.
 *
 * @internal
 */
final class ArrayValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_array($value) && ! ($value instanceof Traversable)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if ($value instanceof Generator) {
            return null;
        }

        /**
         * @var ArrayTypeNode
         */
        $arrayNode = $node;

        if (\is_array($value)) {
            return CollectionElementValidator::validateElements(
                $value,
                $arrayNode->type,
                $context,
                $registry,
                isList: false,
                isSensitive: $isSensitive,
            );
        }

        foreach ($value as $k => $v) {
            $err = $registry->validate($v, $arrayNode->type, '', $isSensitive);
            if ($err !== null) {
                $keyStr = \is_string($k)
                    ? "'" . $k . "'"
                    : ((\is_scalar($k) || $k === null) ? (string) $k : get_debug_type($k));

                return ErrorFactory::createError($context . '[' . $keyStr . ']' . $err->getMessage());
            }
        }

        return null;
    }
}
