<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;

/**
 * @internal Class for validating nullable types like ?int.
 */
final class NullableValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        if ($value === null) {
            return null;
        }

        while ($node instanceof NullableTypeNode) {
            $node = $node->type;
        }

        return $registry->validate($value, $node, $context, $isSensitive);
    }
}
