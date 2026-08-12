<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Validates literal values, class constants, and wildcard constant patterns (Class::PREFIX_*) against ConstTypeNode ASTs.
 */
final class ConstValidator implements TypeValidatorInterface
{
    /**
     * @var array<string, array<int, mixed>>
     */
    private static array $wildcardConstantCache = [];

    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        /** @var ConstTypeNode $constTypeNode */
        $constTypeNode = $node;
        $constExpr = $constTypeNode->constExpr;

        if ($constExpr instanceof ConstExprStringNode) {
            $expected = $constExpr->value;
        } elseif ($constExpr instanceof ConstExprTrueNode) {
            $expected = true;
        } elseif ($constExpr instanceof ConstExprFalseNode) {
            $expected = false;
        } elseif ($constExpr instanceof ConstExprNullNode) {
            $expected = null;
        } elseif ($constExpr instanceof ConstExprIntegerNode) {
            $expected = (int) $constExpr->value;
        } elseif ($constExpr instanceof ConstExprFloatNode) {
            $expected = (float) $constExpr->value;
        } elseif ($constExpr instanceof ConstFetchNode) {
            $className = $constExpr->className;
            $pattern = $constExpr->name;

            if (str_contains($pattern, '*')) {
                $allowedValues = self::resolveWildcardConstantValues($className, $pattern);

                if (! \in_array($value, $allowedValues, true)) {
                    $fqcnPattern = $className !== '' ? "$className::$pattern" : $pattern;

                    return ErrorFactory::createError($context . " must be a valid constant matching $fqcnPattern, " . TypeFormatter::formatGivenValue($value) . ' given');
                }

                return null;
            }

            $fqcnConstant = $className !== ''
                ? $className . '::' . $pattern
                : $pattern;

            if (\defined($fqcnConstant)) {
                $expected = \constant($fqcnConstant);
            } else {
                $expected = (string) $constExpr;
            }
        } else {
            $expected = (string) $constExpr;
        }

        // Float Epsilon Comparison: Handles IEEE 754 precision artifacts and int-to-float coercion
        if (\is_float($expected)) {
            if ((! \is_float($value) && ! \is_int($value)) || abs((float) $value - $expected) > 1e-9) {
                return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
            }

            return null;
        }

        if ($value !== $expected) {
            return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }

    /**
     * Resolves and caches all values of class constants matching a wildcard pattern (e.g. PREFIX_*).
     *
     * @return array<int, mixed>
     */
    private static function resolveWildcardConstantValues(string $className, string $pattern): array
    {
        $cacheKey = $className . '::' . $pattern;
        if (isset(self::$wildcardConstantCache[$cacheKey])) {
            return self::$wildcardConstantCache[$cacheKey];
        }

        $values = [];

        if ($className !== '' && (class_exists($className) || interface_exists($className))) {
            try {
                $refClass = new \ReflectionClass($className);
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

                foreach ($refClass->getConstants() as $cName => $cValue) {
                    if (preg_match($regex, $cName) === 1) {
                        $values[] = $cValue;
                    }
                }
            } catch (\ReflectionException $e) {
                // Silently ignore reflection errors
            }
        }

        return self::$wildcardConstantCache[$cacheKey] = $values;
    }
}