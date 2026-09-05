<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;

/**
 * @internal Class for validating nullable types like ?int.
 */
final class NullableValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if ($value === null) {
            return null;
        }

        /** @var NullableTypeNode $node */
        return $registry->validate($value, $node->type, $context);
    }
}
