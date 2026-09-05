<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorMessage;

/**
 * Strategy interface for validating values against specific PHPDoc AST TypeNodes.
 */
interface TypeValidatorInterface
{
    /**
     * Validates a value against an AST TypeNode and returns an ErrorMessage on failure or null on success.
     */
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage;
}
