<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\ClassNameValidator;

/**
 * @internal Validates literal values, class constants, and wildcard constant patterns (Class::PREFIX_*) against ConstTypeNode ASTs.
 */
final class ConstValidator implements TypeValidatorInterface
{
    /**
     * 2D Cache for wildcard constant values: [$className][$pattern] => array{list: list<mixed>, map: array<string|int, true>}.
     *
     * @var array<string, array<string, array{list: list<mixed>, map: array<string|int, true>}>>
     */
    private static array $wildcardConstantCache = [];

    /**
     * 2D Cache for single resolved class constants: [$className][$constName] => array{0: bool, 1: mixed}.
     *
     * @var array<string, array<string, array{0: bool, 1: mixed}>>
     */
    private static array $classConstantCache = [];

    public static function reset(): void
    {
        self::$wildcardConstantCache = [];
        self::$classConstantCache = [];
    }

    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
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
                $allowed = self::resolveWildcardConstantValues($className, $pattern);

                $isValid = (\is_int($value) || \is_string($value))
                    ? isset($allowed['map'][$value])
                    : \in_array($value, $allowed['list'], strict: true);

                if (! $isValid) {
                    $fqcnPattern = $className !== '' ? "$className::$pattern" : $pattern;

                    return ErrorFactory::createError($context . " must be a valid constant matching $fqcnPattern, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                }

                return null;
            }

            [$resolved, $constValue] = self::resolveSingleConstantValue($className, $pattern);
            $expected = $resolved ? $constValue : (string) $constExpr;
        } else {
            $expected = (string) $constExpr;
        }

        if (\is_float($expected)) {
            if ((! \is_float($value) && ! \is_int($value)) || abs((float) $value - $expected) > 1e-9) {
                return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            return null;
        }

        if ($value !== $expected) {
            return ErrorFactory::createError($context . ' must be literal ' . (string) $constExpr . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    /**
     * Resolves a single class or global constant value via ReflectionClass (supporting protected/private constants),
     * guarding against unresolved relative class keywords (self, static, parent, $this).
     *
     * @return array{0: bool, 1: mixed}
     */
    private static function resolveSingleConstantValue(string $className, string $constName): array
    {
        if ($className !== '') {
            $lowerClass = strtolower($className);
            if ($lowerClass === 'self' || $lowerClass === 'static' || $lowerClass === 'parent' || $lowerClass === '$this') {
                return [false, null];
            }

            if (isset(self::$classConstantCache[$className]) && \array_key_exists($constName, self::$classConstantCache[$className])) {
                return self::$classConstantCache[$className][$constName];
            }

            if (
                ClassNameValidator::isValid($className)
                && (
                    class_exists($className, false)
                    || class_exists($className)
                    || interface_exists($className, false)
                    || interface_exists($className)
                    || enum_exists($className, false)
                    || enum_exists($className)
                )
            ) {
                /** @var class-string<object> $className */
                $refClass = new ReflectionClass($className);
                if ($refClass->hasConstant($constName)) {
                    return self::$classConstantCache[$className][$constName] = [true, $refClass->getConstant($constName)];
                }
            }

            return self::$classConstantCache[$className][$constName] = [false, null];
        }

        if (\defined($constName)) {
            return [true, \constant($constName)];
        }

        return [false, null];
    }

    /**
     * Resolves and caches all values of class constants matching a wildcard pattern (e.g. PREFIX_*).
     *
     * @return array{list: list<mixed>, map: array<string|int, true>}
     */
    private static function resolveWildcardConstantValues(string $className, string $pattern): array
    {
        if (isset(self::$wildcardConstantCache[$className][$pattern])) {
            return self::$wildcardConstantCache[$className][$pattern];
        }

        $list = [];
        $map = [];
        $lowerClass = strtolower($className);

        if (
            $className !== ''
            && $lowerClass !== 'self'
            && $lowerClass !== 'static'
            && $lowerClass !== 'parent'
            && $lowerClass !== '$this'
            && ClassNameValidator::isValid($className)
            && (
                class_exists($className, false)
                || class_exists($className)
                || interface_exists($className, false)
                || interface_exists($className)
                || enum_exists($className, false)
                || enum_exists($className)
            )
        ) {
            /** @var class-string<object> $className */
            $refClass = new ReflectionClass($className);
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';

            foreach ($refClass->getConstants() as $cName => $cValue) {
                if (preg_match($regex, $cName) === 1) {
                    $list[] = $cValue;
                    if (\is_int($cValue) || \is_string($cValue)) {
                        $map[$cValue] = true;
                    }
                }
            }
        }

        return self::$wildcardConstantCache[$className][$pattern] = [
            'list' => $list,
            'map' => $map,
        ];
    }
}
