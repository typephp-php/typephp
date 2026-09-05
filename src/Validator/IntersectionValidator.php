<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;

/**
 * @internal Class for validating intersection types like int & string.
 */
final class IntersectionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        /** @var IntersectionTypeNode $intersectionNode */
        $intersectionNode = $node;

        foreach ($intersectionNode->types as $type) {
            $err = $registry->validate($value, $type, $context);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }
}
