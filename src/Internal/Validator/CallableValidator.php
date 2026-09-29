<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionFunction;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Wrapper\CallableWrapper;

/**
 * @internal Validates callable and Closure contracts at boundaries.
 */
final class CallableValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var CallableTypeNode $node */
        $identifierName = strtolower(ltrim($node->identifier->name, '\\'));

        if (str_contains($identifierName, 'closure')) {
            if (! ($value instanceof Closure)) {
                return ErrorFactory::createError($context . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            if (str_contains($identifierName, 'static')) {
                $refFunc = new ReflectionFunction($value);
                if ($refFunc->getClosureThis() !== null) {
                    return ErrorFactory::createError($context . ' must be a static Closure (not bound to $this)');
                }
            }

            return null;
        }

        if (! CallableWrapper::isCallable($value)) {
            return ErrorFactory::createError($context . ' must be of type callable, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }
}
