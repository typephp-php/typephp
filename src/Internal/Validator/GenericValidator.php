<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;

/**
 * @internal Validates values against generic AST structures (int ranges, class-string<T>, list<T>, array<K,V>, object generics, key-of, value-of, int-mask, int-mask-of).
 */
final class GenericValidator implements TypeValidatorInterface
{
    private const BUILTIN_GENERICS = [
        'int' => true,
        'integer' => true,
        'class-string' => true,
        'list' => true,
        'non-empty-list' => true,
        'non-empty-array-list' => true,
        'array' => true,
        'non-empty-array' => true,
        'iterable' => true,
        'traversable' => true,
        'generator' => true,
        'iterator' => true,
        'key-of' => true,
        'value-of' => true,
        'int-mask' => true,
        'int-mask-of' => true,
    ];

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
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var GenericTypeNode $genericNode */
        $genericNode = $node;
        $baseType = strtolower($genericNode->type->name);

        return match ($baseType) {
            'int', 'integer' => $this->validateIntRange($value, $genericNode, $context, $isSensitive),
            'class-string' => $this->validateClassString($value, $genericNode, $context, $isSensitive),
            'list', 'non-empty-list', 'non-empty-array-list' => $this->validateList($value, $genericNode, $context, $registry, $isSensitive),
            'array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator' => $this->validateArray($value, $genericNode, $context, $registry, $isSensitive),
            'key-of' => $this->validateKeyOf($value, $genericNode, $context, $registry, $isSensitive),
            'value-of' => $this->validateValueOf($value, $genericNode, $context, $registry, $isSensitive),
            'int-mask' => $this->validateIntMask($value, $genericNode, $context, $isSensitive),
            'int-mask-of' => $this->validateIntMaskOf($value, $genericNode, $context, $isSensitive),
            default => $this->validateObjectGeneric($value, $genericNode, $context, $isSensitive),
        };
    }

    private function resolveConstantValue(string $fqcn, string $constName): mixed
    {
        $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

        if (! \array_key_exists($cacheKey, self::$constantCache)) {
            $constValue = false;
            if ($fqcn !== '') {
                if (class_exists($fqcn) || interface_exists($fqcn)) {
                    $refClass = new \ReflectionClass($fqcn);
                    if ($refClass->hasConstant($constName)) {
                        $constValue = $refClass->getConstant($constName);
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

    private function validateKeyOf(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;

        if ($targetType instanceof GenericTypeNode && strtolower($targetType->type->name) === 'value-of') {
            $innerTarget = $targetType->genericTypes[0] ?? null;
            if ($innerTarget instanceof ArrayShapeNode) {
                $validKeys = [];
                foreach ($innerTarget->items as $item) {
                    if ($item->valueType instanceof ArrayShapeNode) {
                        foreach ($item->valueType->items as $subItem) {
                            $subKey = self::extractKeyFromItem($subItem);
                            if ($subKey !== null) {
                                $validKeys[] = $subKey;
                            }
                        }
                    }
                }
                if (! \in_array($value, $validKeys, strict: true)) {
                    return ErrorFactory::createError($context . ' must be a key of the specified array shape, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                }

                return null;
            }
        }

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $constName = $constExpr->name;
            $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

            $constValue = $this->resolveConstantValue($fqcn, $constName);

            if (\is_array($constValue)) {
                if ((! \is_int($value) && ! \is_string($value)) || ! \array_key_exists($value, $constValue)) {
                    return ErrorFactory::createError($context . " must be a key of $cacheKey, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof IdentifierTypeNode) {
            $enumClass = $targetType->name;
            if (ClassNameValidator::isValid($enumClass) && enum_exists($enumClass)) {
                if (! isset(self::$enumKeyCache[$enumClass])) {
                    self::$enumKeyCache[$enumClass] = array_map(fn($case) => $case->name, $enumClass::cases());
                }

                if (! \in_array($value, self::$enumKeyCache[$enumClass], strict: true)) {
                    return ErrorFactory::createError($context . " must be a key of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof ArrayShapeNode) {
            $validKeys = [];
            $nextAutoIndex = 0;

            foreach ($targetType->items as $item) {
                if ($item->keyName instanceof ConstExprStringNode) {
                    $validKeys[] = $item->keyName->value;
                } elseif ($item->keyName instanceof IdentifierTypeNode) {
                    $validKeys[] = $item->keyName->name;
                } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                    $key = (int) $item->keyName->value;
                    $validKeys[] = $key;
                    $nextAutoIndex = max($nextAutoIndex, $key + 1);
                } elseif ($item->keyName !== null) {
                    $validKeys[] = (string) $item->keyName;
                } else {
                    $validKeys[] = $nextAutoIndex;
                    $nextAutoIndex++;
                }
            }

            if (! \in_array($value, $validKeys, strict: true)) {
                return ErrorFactory::createError($context . ' must be a key of the specified array shape, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            return null;
        }

        return null;
    }

    private static function extractKeyFromItem(ArrayShapeItemNode $item): string|int|null
    {
        $keyName = $item->keyName;

        if ($keyName instanceof ConstExprStringNode) {
            return $keyName->value;
        }
        if ($keyName instanceof ConstExprIntegerNode) {
            return (int) $keyName->value;
        }
        if ($keyName instanceof IdentifierTypeNode) {
            return $keyName->name;
        }
        if ($keyName instanceof ConstFetchNode) {
            return (string) $keyName;
        }

        return null;
    }

    private function validateValueOf(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $constName = $constExpr->name;
            $cacheKey = $fqcn !== '' ? "$fqcn::$constName" : $constName;

            $constValue = $this->resolveConstantValue($fqcn, $constName);

            if (\is_array($constValue)) {
                if (! \in_array($value, $constValue, strict: true)) {
                    return ErrorFactory::createError($context . " must be a value of $cacheKey, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                }

                return null;
            }
        } elseif ($targetType instanceof IdentifierTypeNode) {
            $enumClass = $targetType->name;
            if (ClassNameValidator::isValid($enumClass) && enum_exists($enumClass)) {
                if (is_subclass_of($enumClass, \BackedEnum::class)) {
                    if (! isset(self::$enumValueCache[$enumClass])) {
                        self::$enumValueCache[$enumClass] = array_map(fn($case) => $case->value, $enumClass::cases());
                    }

                    if (! \in_array($value, self::$enumValueCache[$enumClass], strict: true)) {
                        return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
                    }

                    return null;
                }

                return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }
        } elseif ($targetType instanceof ArrayShapeNode) {
            foreach ($targetType->items as $item) {
                if ($registry->validate($value, $item->valueType, '', $isSensitive) === null) {
                    return null;
                }
            }

            return ErrorFactory::createError($context . ' must be a value of the specified array shape, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateIntMask(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
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
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateIntMaskOf(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $targetType = $node->genericTypes[0] ?? null;
        $allowedMask = 0;
        $foundFlags = false;

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            $constExpr = $targetType->constExpr;
            $fqcn = $constExpr->className;
            $pattern = $constExpr->name;

            if ($fqcn !== '' && (class_exists($fqcn) || interface_exists($fqcn))) {
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
            }
        }

        if ($foundFlags && ($value & ~$allowedMask) !== 0) {
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateIntRange(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $minNode = $node->genericTypes[0] ?? null;
        $maxNode = $node->genericTypes[1] ?? null;

        if ($minNode !== null) {
            $minStr = strtolower(trim((string) $minNode));
            if ($minStr !== 'min' && $minStr !== '*') {
                $minVal = (int) $minStr;
                if ($value < $minVal) {
                    $valDisplay = $isSensitive ? 'int given' : "$value given";

                    return ErrorFactory::createError($context . " must be >= $minVal, $valDisplay");
                }
            }
        }

        if ($maxNode !== null) {
            $maxStr = strtolower(trim((string) $maxNode));
            if ($maxStr !== 'max' && $maxStr !== '*') {
                $maxVal = (int) $maxStr;
                if ($value > $maxVal) {
                    $valDisplay = $isSensitive ? 'int given' : "$value given";

                    return ErrorFactory::createError($context . " must be <= $maxVal, $valDisplay");
                }
            }
        }

        return null;
    }

    private function validateClassString(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_string($value) || ! ClassNameValidator::isValidClassString($value)) {
            return ErrorFactory::createError($context . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $targetClassNode = $node->genericTypes[0] ?? null;
        if ($targetClassNode === null) {
            return null;
        }

        return $this->validateClassStringBound($value, $targetClassNode, $context, $isSensitive);
    }

    private function validateClassStringBound(string $value, TypeNode $targetNode, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if ($targetNode instanceof IdentifierTypeNode) {
            $targetName = $targetNode->name;
            $lower = strtolower($targetName);
            if ($lower === 'object' || $lower === 'mixed') {
                return null;
            }

            if (class_exists($targetName) || interface_exists($targetName) || trait_exists($targetName) || enum_exists($targetName)) {
                if (! is_a($value, $targetName, allow_string: true)) {
                    $valDisplay = $isSensitive ? 'string given' : "'$value' given";

                    return ErrorFactory::createError($context . ' must be a class-string of ' . $targetName . ", $valDisplay");
                }
            }

            return null;
        }

        if ($targetNode instanceof UnionTypeNode) {
            foreach ($targetNode->types as $unionType) {
                if ($this->validateClassStringBound($value, $unionType, $context, $isSensitive) === null) {
                    return null;
                }
            }

            $valDisplay = $isSensitive ? 'string given' : "'$value' given";

            return ErrorFactory::createError($context . ' must be a class-string of ' . (string) $targetNode . ", $valDisplay");
        }

        if ($targetNode instanceof IntersectionTypeNode) {
            foreach ($targetNode->types as $intersectionType) {
                $err = $this->validateClassStringBound($value, $intersectionType, $context, $isSensitive);
                if ($err !== null) {
                    return $err;
                }
            }

            return null;
        }

        return null;
    }

    private function validateList(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $baseType = strtolower($node->type->name);

        if (! \is_array($value) || (\count($value) > 0 && ! array_is_list($value))) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $count = \count($value);

        if (str_contains($baseType, 'non-empty') && $count === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty list, empty array given');
        }

        $valueTypeNode = $node->genericTypes[0] ?? null;
        if ($valueTypeNode === null || $count === 0) {
            return null;
        }

        if ($valueTypeNode instanceof IdentifierTypeNode && \in_array(strtolower($valueTypeNode->name), ['mixed', 't', 'tvalue', 'v', 'value', 'telement'], true)) {
            return null;
        }

        $isComplexObjectGeneric = ($valueTypeNode instanceof GenericTypeNode && ! isset(self::BUILTIN_GENERICS[strtolower($valueTypeNode->type->name)]));

        if ($count > Config::HYBRID_SAMPLE_THRESHOLD && Config::isArrayValidationHybrid()) {
            $sampleIndices = [0, $count - 1];
            $samplesToTake = min(3, $count - 2);
            for ($i = 0; $i < $samplesToTake; $i++) {
                $sampleIndices[] = mt_rand(1, $count - 2);
            }

            foreach ($sampleIndices as $k) {
                $v = $value[$k];
                $err = $isComplexObjectGeneric
                    ? $this->validateObjectGeneric($v, $valueTypeNode, '', $isSensitive)
                    : $registry->validate($v, $valueTypeNode, '', $isSensitive);

                if ($err !== null) {
                    return ErrorFactory::createError($context . '[' . $k . ']' . $err->getMessage());
                }
            }

            return null;
        }

        foreach ($value as $k => $v) {
            $err = $isComplexObjectGeneric
                ? $this->validateObjectGeneric($v, $valueTypeNode, '', $isSensitive)
                : $registry->validate($v, $valueTypeNode, '', $isSensitive);

            if ($err !== null) {
                return ErrorFactory::createError($context . '[' . $k . ']' . $err->getMessage());
            }
        }

        return null;
    }

    private function validateArray(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $baseType = strtolower($node->type->name);

        if (! \is_array($value) && ! ($value instanceof \Traversable)) {
            return ErrorFactory::createError($context . ' must be of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (! \is_array($value)) {
            return null;
        }

        $count = \count($value);

        if (str_contains($baseType, 'non-empty') && $count === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty array, empty array given');
        }

        if ($count === 0) {
            return null;
        }

        $typesCount = \count($node->genericTypes);
        if ($typesCount === 1) {
            $valTypeNode = $node->genericTypes[0];

            if ($valTypeNode instanceof IdentifierTypeNode && \in_array(strtolower($valTypeNode->name), ['mixed', 't', 'tvalue', 'v', 'value', 'telement'], true)) {
                return null;
            }

            $isComplexObjectGeneric = ($valTypeNode instanceof GenericTypeNode && ! isset(self::BUILTIN_GENERICS[strtolower($valTypeNode->type->name)]));
            if ($count > Config::HYBRID_SAMPLE_THRESHOLD && Config::isArrayValidationHybrid()) {
                $keys = array_keys($value);
                $sampleKeys = [$keys[0], $keys[$count - 1]];
                $samplesToTake = min(3, $count - 2);
                for ($i = 0; $i < $samplesToTake; $i++) {
                    $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
                }

                foreach ($sampleKeys as $k) {
                    $v = $value[$k];
                    $err = $isComplexObjectGeneric
                        ? $this->validateObjectGeneric($v, $valTypeNode, '', $isSensitive)
                        : $registry->validate($v, $valTypeNode, '', $isSensitive);

                    if ($err !== null) {
                        return ErrorFactory::createError($context . '[' . $k . ']' . $err->getMessage());
                    }
                }

                return null;
            }

            foreach ($value as $k => $v) {
                $err = $isComplexObjectGeneric
                    ? $this->validateObjectGeneric($v, $valTypeNode, '', $isSensitive)
                    : $registry->validate($v, $valTypeNode, '', $isSensitive);

                if ($err !== null) {
                    return ErrorFactory::createError($context . '[' . $k . ']' . $err->getMessage());
                }
            }
        } elseif ($typesCount >= 2) {
            $keyTypeNode = $node->genericTypes[0];
            $valTypeNode = $node->genericTypes[1];

            $keyIsArrayKey = ($keyTypeNode instanceof IdentifierTypeNode) && \in_array(strtolower($keyTypeNode->name), ['array-key', 'mixed', 'tkey', 'key', 'k'], true);
            $valIsMixed = ($valTypeNode instanceof IdentifierTypeNode) && \in_array(strtolower($valTypeNode->name), ['mixed', 'tvalue', 'v', 'value', 't'], true);

            if ($keyIsArrayKey && $valIsMixed) {
                return null;
            }

            $isComplexObjectGeneric = ($valTypeNode instanceof GenericTypeNode && ! isset(self::BUILTIN_GENERICS[strtolower($valTypeNode->type->name)]));

            if ($count > Config::HYBRID_SAMPLE_THRESHOLD && Config::isArrayValidationHybrid()) {
                $keys = array_keys($value);
                $sampleKeys = [$keys[0], $keys[$count - 1]];
                $samplesToTake = min(3, $count - 2);
                for ($i = 0; $i < $samplesToTake; $i++) {
                    $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
                }

                foreach ($sampleKeys as $k) {
                    if (! $keyIsArrayKey) {
                        $err = $registry->validate($k, $keyTypeNode, '');
                        if ($err !== null) {
                            return ErrorFactory::createError($context . ' key' . $err->getMessage());
                        }
                    }

                    if (! $valIsMixed) {
                        $v = $value[$k];
                        $err = $isComplexObjectGeneric
                            ? $this->validateObjectGeneric($v, $valTypeNode, '', $isSensitive)
                            : $registry->validate($v, $valTypeNode, '', $isSensitive);

                        if ($err !== null) {
                            return ErrorFactory::createError($context . "['" . $k . "']" . $err->getMessage());
                        }
                    }
                }

                return null;
            }

            foreach ($value as $k => $v) {
                if (! $keyIsArrayKey) {
                    $err = $registry->validate($k, $keyTypeNode, '');
                    if ($err !== null) {
                        return ErrorFactory::createError($context . ' key' . $err->getMessage());
                    }
                }

                if (! $valIsMixed) {
                    $err = $isComplexObjectGeneric
                        ? $this->validateObjectGeneric($v, $valTypeNode, '', $isSensitive)
                        : $registry->validate($v, $valTypeNode, '', $isSensitive);

                    if ($err !== null) {
                        return ErrorFactory::createError($context . "['" . $k . "']" . $err->getMessage());
                    }
                }
            }
        }

        return null;
    }

    private function validateObjectGeneric(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! ClassNameValidator::isValid($node->type->name)) {
            return null;
        }

        if (! \is_object($value)) {
            return ErrorFactory::createError($context . ' must be an object of type ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (! is_a($value, $node->type->name)) {
            return ErrorFactory::createError($context . ' must be an instance of ' . $node->type->name . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return RuntimeTypeChecker::bindInstanceFromNode($value, $node, $context);
    }
}
