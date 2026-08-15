<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Validates values against generic AST structures (int ranges, class-string<T>, list<T>, array<K,V>, object generics, key-of, value-of, int-mask, int-mask-of).
 */
final class GenericValidator implements TypeValidatorInterface
{
    /**
     * @var array<string, mixed>
     */
    private static array $constantCache = [];

    /**
     * @var array<string, array<int, string>>
     */
    private static array $enumKeyCache = [];

    /**
     * @var array<string, array<int, string|int>>
     */
    private static array $enumValueCache = [];

    /**
     * Validates a value against a GenericTypeNode AST.
     */
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        /** @var GenericTypeNode $genericNode */
        $genericNode = $node;
        $baseType = strtolower($genericNode->type->name);

        return match ($baseType) {
            'int', 'integer' => $this->validateIntRange($value, $genericNode, $context),
            'class-string' => $this->validateClassString($value, $genericNode, $context),
            'list', 'non-empty-list', 'non-empty-array-list' => $this->validateList($value, $genericNode, $context, $registry),
            'array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator' => $this->validateArray($value, $genericNode, $context, $registry),
            'key-of' => $this->validateKeyOf($value, $genericNode, $context),
            'value-of' => $this->validateValueOf($value, $genericNode, $context),
            'int-mask' => $this->validateIntMask($value, $genericNode, $context),
            'int-mask-of' => $this->validateIntMaskOf($value, $genericNode, $context),
            default => $this->validateObjectGeneric($value, $genericNode, $context),
        };
    }

    /**
     * Helper to resolve and cache class or global constant values in static memory.
     */
    private function resolveConstantValue(string $fqcn, string $constName): mixed
    {
        $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

        if (! \array_key_exists($cacheKey, self::$constantCache)) {
            $constValue = false;
            if ($fqcn !== '') {
                if (class_exists($fqcn) || interface_exists($fqcn)) {
                    try {
                        $refClass = new \ReflectionClass($fqcn);
                        if ($refClass->hasConstant($constName)) {
                            $constValue = $refClass->getConstant($constName);
                        }
                    } catch (\ReflectionException $e) {
                        // Silently ignore reflection errors
                    }
                }
            } else {
                if (\defined($constName)) {
                    $constValue = \constant($constName);
                }
            }
            self::$constantCache[$cacheKey] = $constValue;
        }

        return self::$constantCache[$cacheKey];
    }

    /**
     * Validates key-of<T> generic structures with O(1) in-memory caching.
     *
     * Execution Flow:
     * 1. Array Constants: If T is a class constant (e.g., self::DRIVER_MAP), it safely reflects the
     *    target class to bypass visibility restrictions (private/protected), caches the array in memory,
     *    and verifies that the provided value exists as a key in that array.
     * 2. Enums: If T is an Enum identifier, it extracts and caches the enum case names, then verifies
     *    that the provided value matches a valid case name.
     * 3. Array Shapes: If T is an inline array shape (e.g., array{id: int, name: string}), it verifies
     *    that the provided value exists as one of the key names in the shape.
     * 4. Fallback: Returns null gracefully for unresolvable or unsupported structures.
     */
    private function validateKeyOf(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $constName = $constExpr->name;
            $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

            $constValue = $this->resolveConstantValue($fqcn, $constName);

            if (\is_array($constValue)) {
                if ((! \is_int($value) && ! \is_string($value)) || ! \array_key_exists($value, $constValue)) {
                    return ErrorFactory::createError($context . " must be a key of $cacheKey, " . TypeFormatter::formatGivenValue($value) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof IdentifierTypeNode) {
            $enumClass = $targetType->name;
            if (ClassNameValidator::isValid($enumClass) && enum_exists($enumClass)) {
                if (! isset(self::$enumKeyCache[$enumClass])) {
                    self::$enumKeyCache[$enumClass] = array_map(fn ($case) => $case->name, $enumClass::cases());
                }

                if (! \in_array($value, self::$enumKeyCache[$enumClass], true)) {
                    return ErrorFactory::createError($context . " must be a key of enum $enumClass, " . TypeFormatter::formatGivenValue($value) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof ArrayShapeNode) {
            $validKeys = [];
            foreach ($targetType->items as $item) {
                if ($item->keyName instanceof ConstExprStringNode) {
                    $validKeys[] = $item->keyName->value;
                } elseif ($item->keyName instanceof IdentifierTypeNode) {
                    $validKeys[] = $item->keyName->name;
                } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                    $validKeys[] = (int) $item->keyName->value;
                }
            }

            if (! \in_array($value, $validKeys, true)) {
                return ErrorFactory::createError($context . ' must be a key of the specified array shape, ' . TypeFormatter::formatGivenValue($value) . ' given');
            }

            return null;
        }

        return null;
    }

    /**
     * Validates value-of<T> generic structures with O(1) in-memory caching.
     *
     * Execution Flow:
     * 1. Array Constants: If T is a class constant (e.g., self::DRIVER_MAP), it safely reflects the
     *    target class to bypass visibility restrictions (private/protected), caches the array in memory,
     *    and verifies that the provided value exists as a value in that array.
     * 2. Backed Enums: If T is a Backed Enum identifier, it extracts and caches the enum case backing values,
     *    then verifies that the provided value matches a valid case value.
     * 3. Unit Enums: Pure non-backed UnitEnums have no backing values, so any value-of check fails.
     * 4. Fallback: Returns null gracefully for unresolvable or unsupported structures.
     */
    private function validateValueOf(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $constName = $constExpr->name;
            $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

            $constValue = $this->resolveConstantValue($fqcn, $constName);

            if (\is_array($constValue)) {
                if (! \in_array($value, $constValue, true)) {
                    return ErrorFactory::createError($context . " must be a value of $cacheKey, " . TypeFormatter::formatGivenValue($value) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof IdentifierTypeNode) {
            $enumClass = $targetType->name;
            if (ClassNameValidator::isValid($enumClass) && enum_exists($enumClass)) {
                if (is_subclass_of($enumClass, \BackedEnum::class)) {
                    if (! isset(self::$enumValueCache[$enumClass])) {
                        self::$enumValueCache[$enumClass] = array_map(fn ($case) => $case->value, $enumClass::cases());
                    }

                    if (! \in_array($value, self::$enumValueCache[$enumClass], true)) {
                        return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value) . ' given');
                    }

                    return null;
                }

                return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value) . ' given');
            }
        }

        return null;
    }

    /**
     * Validates int-mask<1, 2, 4> bitmask flags combinations.
     */
    private function validateIntMask(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $allowedMask = 0;

        foreach ($node->genericTypes as $typeNode) {
            if ($typeNode instanceof ConstTypeNode) {
                $expr = $typeNode->constExpr;
                if ($expr instanceof ConstExprIntegerNode) {
                    $allowedMask |= (int) $expr->value;
                } elseif ($expr instanceof ConstFetchNode) {
                    $constVal = $this->resolveConstantValue($expr->className, $expr->name);
                    if (\is_int($constVal)) {
                        $allowedMask |= $constVal;
                    }
                }
            }
        }

        if (($value & ~$allowedMask) !== 0) {
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }

    /**
     * Validates int-mask-of<self::FLAG_*> bitmask flags combinations from constant patterns.
     */
    private function validateIntMaskOf(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $targetType = $node->genericTypes[0] ?? null;
        $allowedMask = 0;
        $foundFlags = false;

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $pattern = $constExpr->name;

            if ($fqcn !== '' && (class_exists($fqcn) || interface_exists($fqcn))) {
                try {
                    $refClass = new \ReflectionClass($fqcn);

                    if (str_contains($pattern, '*')) {
                        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                        foreach ($refClass->getConstants() as $cName => $cValue) {
                            if (\is_int($cValue) && preg_match($regex, $cName) === 1) {
                                $allowedMask |= $cValue;
                                $foundFlags = true;
                            }
                        }
                    } else {
                        $cValue = $this->resolveConstantValue($fqcn, $pattern);
                        if (\is_int($cValue)) {
                            $allowedMask |= $cValue;
                            $foundFlags = true;
                        } elseif (\is_array($cValue)) {
                            foreach ($cValue as $item) {
                                if (\is_int($item)) {
                                    $allowedMask |= $item;
                                    $foundFlags = true;
                                }
                            }
                        }
                    }
                } catch (\ReflectionException $e) {
                    // Silently ignore reflection errors
                }
            }
        }

        if ($foundFlags && ($value & ~$allowedMask) !== 0) {
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return null;
    }

    /**
     * Validates integer ranges (e.g. int<1, 100> or int<min, max>).
     */
    private function validateIntRange(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $minNode = $node->genericTypes[0] ?? null;
        $maxNode = $node->genericTypes[1] ?? null;

        if ($minNode !== null) {
            $minStr = strtolower(trim((string) $minNode));
            if ($minStr !== 'min' && $minStr !== '*') {
                $minVal = (int) $minStr;
                if ($value < $minVal) {
                    return ErrorFactory::createError($context . " must be >= $minVal, $value given");
                }
            }
        }

        if ($maxNode !== null) {
            $maxStr = strtolower(trim((string) $maxNode));
            if ($maxStr !== 'max' && $maxStr !== '*') {
                $maxVal = (int) $maxStr;
                if ($value > $maxVal) {
                    return ErrorFactory::createError($context . " must be <= $maxVal, $value given");
                }
            }
        }

        return null;
    }

    /**
     * Validates class-string<T> parameters against declared class bounds.
     */
    private function validateClassString(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        if (! \is_string($value) || ! ClassNameValidator::isValid($value) || (! class_exists($value) && ! interface_exists($value) && ! trait_exists($value) && ! enum_exists($value))) {
            return ErrorFactory::createError($context . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        $targetClassNode = $node->genericTypes[0] ?? null;
        if ($targetClassNode instanceof IdentifierTypeNode) {
            $targetName = $targetClassNode->name;
            if (class_exists($targetName) || interface_exists($targetName) || trait_exists($targetName) || enum_exists($targetName)) {
                if (! is_a($value, $targetName, true)) {
                    return ErrorFactory::createError($context . ' must be a class-string of ' . $targetName . ", '$value' given");
                }
            }
        }

        return null;
    }

    /**
     * Validates sequential list structures (e.g. list<string> or non-empty-list<int>).
     */
    private function validateList(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        $baseType = strtolower($node->type->name);

        if (! \is_array($value) || (\count($value) > 0 && ! array_is_list($value))) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (str_contains($baseType, 'non-empty') && \count($value) === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty list, empty array given');
        }

        $valueTypeNode = $node->genericTypes[0] ?? null;
        if ($valueTypeNode !== null) {
            foreach ($value as $k => $v) {
                if ($valueTypeNode instanceof GenericTypeNode && ! \in_array(strtolower($valueTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valueTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valueTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validates key-value array structures (e.g. array<string, int>).
     */
    private function validateArray(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        $baseType = strtolower($node->type->name);

        if (! \is_array($value) && ! ($value instanceof \Traversable)) {
            return ErrorFactory::createError($context . ' must be of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (! \is_array($value)) {
            return null;
        }

        if (str_contains($baseType, 'non-empty') && \count($value) === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty array, empty array given');
        }

        $typesCount = \count($node->genericTypes);
        if ($typesCount === 1) {
            $valTypeNode = $node->genericTypes[0];
            foreach ($value as $k => $v) {
                if ($valTypeNode instanceof GenericTypeNode && ! \in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valTypeNode, $context . '[' . $k . ']');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        } elseif ($typesCount >= 2) {
            $keyTypeNode = $node->genericTypes[0];
            $valTypeNode = $node->genericTypes[1];
            foreach ($value as $k => $v) {
                $err = $registry->validate($k, $keyTypeNode, $context . ' key');
                if ($err !== null) {
                    return $err;
                }

                if ($valTypeNode instanceof GenericTypeNode && ! \in_array(strtolower($valTypeNode->type->name), ['class-string', 'list', 'array', 'iterable'], true)) {
                    $err = $this->validateObjectGeneric($v, $valTypeNode, $context . "['" . $k . "']");
                    if ($err !== null) {
                        return $err;
                    }
                } else {
                    $err = $registry->validate($v, $valTypeNode, $context . "['" . $k . "']");
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Validates object generic instances and binds template parameters.
     * Gracefully ignores generic annotations with invalid class syntax (e.g. custom-generic<T>).
     */
    private function validateObjectGeneric(mixed $value, GenericTypeNode $node, string $context): ?ErrorMessage
    {
        if (! ClassNameValidator::isValid($node->type->name)) {
            return null;
        }

        if (! \is_object($value)) {
            return ErrorFactory::createError($context . ' must be an object of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        if (! is_a($value, $node->type->name)) {
            return ErrorFactory::createError($context . ' must be an instance of ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        return RuntimeTypeChecker::bindInstanceFromNode($value, $node, $context);
    }
}