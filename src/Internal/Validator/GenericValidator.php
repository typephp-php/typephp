<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
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
     * @var array<string, array{0: int, 1: bool}>
     */
    private static array $maskOfCache = [];

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

    private function validateKeyOf(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;
        if ($targetType === null) {
            return null;
        }

        if ($targetType instanceof GenericTypeNode && strtolower($targetType->type->name) === 'value-of') {
            return $this->validateKeyOfNestedValueOf($value, $targetType, $context, $isSensitive);
        }

        if ($targetType instanceof IdentifierTypeNode && ClassNameValidator::isValid($targetType->name) && enum_exists($targetType->name)) {
            return $this->validateKeyOfEnum($value, $targetType->name, $context, $isSensitive);
        }

        if ($targetType instanceof ArrayShapeNode) {
            return $this->validateKeyOfArrayShape($value, $targetType, $context, $isSensitive);
        }

        $target = $this->extractConstantTarget($targetType);
        if ($target !== null) {
            return $this->validateKeyOfConstant($value, $target[0], $target[1], $context, $isSensitive);
        }

        return null;
    }

    private function validateKeyOfNestedValueOf(mixed $value, GenericTypeNode $targetType, string $context, bool $isSensitive): ?ErrorMessage
    {
        $innerTarget = $targetType->genericTypes[0] ?? null;
        if (! $innerTarget instanceof ArrayShapeNode) {
            return null;
        }

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

    /**
     * @param class-string<\UnitEnum>|string $enumClass
     */
    private function validateKeyOfEnum(mixed $value, string $enumClass, string $context, bool $isSensitive): ?ErrorMessage
    {
        if (! is_subclass_of($enumClass, \UnitEnum::class)) {
            return null;
        }

        if (! isset(self::$enumKeyCache[$enumClass])) {
            self::$enumKeyCache[$enumClass] = array_map(
                static fn (\UnitEnum $case): string => $case->name,
                $enumClass::cases()
            );
        }

        if (! \in_array($value, self::$enumKeyCache[$enumClass], strict: true)) {
            return ErrorFactory::createError($context . " must be a key of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateKeyOfArrayShape(mixed $value, ArrayShapeNode $targetType, string $context, bool $isSensitive): ?ErrorMessage
    {
        $validKeys = [];
        $nextAutoIndex = 0;

        foreach ($targetType->items as $item) {
            $key = self::extractKeyFromItem($item);
            if ($key === null) {
                $key = $nextAutoIndex;
                $nextAutoIndex++;
            } elseif (\is_int($key)) {
                $nextAutoIndex = max($nextAutoIndex, $key + 1);
            }

            $validKeys[] = $key;
        }

        if (! \in_array($value, $validKeys, strict: true)) {
            return ErrorFactory::createError($context . ' must be a key of the specified array shape, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateKeyOfConstant(mixed $value, string $className, string $constName, string $context, bool $isSensitive): ?ErrorMessage
    {
        if ($constName === '') {
            return null;
        }

        $cacheKey = $className !== '' ? "$className::$constName" : $constName;
        $constValue = $this->resolveConstantValue($className, $constName);

        if (\is_array($constValue)) {
            if ((! \is_int($value) && ! \is_string($value)) || ! \array_key_exists($value, $constValue)) {
                return ErrorFactory::createError($context . " must be a key of $cacheKey, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }
        }

        return null;
    }

    private function validateValueOf(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;
        if ($targetType === null) {
            return null;
        }

        if ($targetType instanceof IdentifierTypeNode && ClassNameValidator::isValid($targetType->name) && enum_exists($targetType->name)) {
            return $this->validateValueOfEnum($value, $targetType->name, $context, $isSensitive);
        }

        if ($targetType instanceof ArrayShapeNode) {
            return $this->validateValueOfArrayShape($value, $targetType, $context, $registry, $isSensitive);
        }

        $target = $this->extractConstantTarget($targetType);
        if ($target !== null) {
            return $this->validateValueOfConstant($value, $target[0], $target[1], $context, $isSensitive);
        }

        return null;
    }

    /**
     * @param class-string<\BackedEnum>|string $enumClass
     */
    private function validateValueOfEnum(mixed $value, string $enumClass, string $context, bool $isSensitive): ?ErrorMessage
    {
        if (! is_subclass_of($enumClass, \BackedEnum::class)) {
            return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (! isset(self::$enumValueCache[$enumClass])) {
            self::$enumValueCache[$enumClass] = array_map(
                static fn (\BackedEnum $case): string|int => $case->value,
                $enumClass::cases()
            );
        }

        if (! \in_array($value, self::$enumValueCache[$enumClass], strict: true)) {
            return ErrorFactory::createError($context . " must be a value of enum $enumClass, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateValueOfArrayShape(mixed $value, ArrayShapeNode $targetType, string $context, TypeValidatorRegistry $registry, bool $isSensitive): ?ErrorMessage
    {
        foreach ($targetType->items as $item) {
            if ($registry->validate($value, $item->valueType, '', $isSensitive) === null) {
                return null;
            }
        }

        return ErrorFactory::createError($context . ' must be a value of the specified array shape, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
    }

    private function validateValueOfConstant(mixed $value, string $className, string $constName, string $context, bool $isSensitive): ?ErrorMessage
    {
        if ($constName === '') {
            return null;
        }

        $cacheKey = $className !== '' ? "$className::$constName" : $constName;
        $constValue = $this->resolveConstantValue($className, $constName);

        if (\is_array($constValue) && ! \in_array($value, $constValue, strict: true)) {
            return ErrorFactory::createError($context . " must be a value of $cacheKey, " . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    private function validateIntMask(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $allowedMask = $this->computeAllowedMask($node->genericTypes);

        if (($value & ~$allowedMask) !== 0) {
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    /**
     * @param array<TypeNode> $genericTypes
     */
    private function computeAllowedMask(array $genericTypes): int
    {
        $allowedMask = 0;

        foreach ($genericTypes as $typeNode) {
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
            } elseif ($typeNode instanceof IdentifierTypeNode) {
                $target = $this->extractConstantTarget($typeNode);
                if ($target !== null) {
                    $constVal = $this->resolveConstantValue($target[0], $target[1]);
                    if (\is_int($constVal)) {
                        $allowedMask |= $constVal;
                    }
                }
            }
        }

        return $allowedMask;
    }

    private function validateIntMaskOf(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_int($value)) {
            return ErrorFactory::createError($context . ' must be of type int (bitmask), ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $targetType = $node->genericTypes[0] ?? null;
        if ($targetType === null) {
            return null;
        }

        $allowedMask = 0;
        $foundFlags = false;

        if ($targetType instanceof UnionTypeNode) {
            [$allowedMask, $foundFlags] = $this->computeMaskOfUnion($targetType);
        } else {
            $target = $this->extractConstantTarget($targetType);
            if ($target !== null && $target[1] !== '') {
                [$allowedMask, $foundFlags] = $this->resolveMaskOfPattern($target[0], $target[1]);
            }
        }

        if ($foundFlags && ($value & ~$allowedMask) !== 0) {
            return ErrorFactory::createError($context . ' must be a valid bitmask combination of the allowed flags, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return null;
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function computeMaskOfUnion(UnionTypeNode $targetType): array
    {
        $allowedMask = 0;
        $foundFlags = false;

        foreach ($targetType->types as $unionMember) {
            if ($unionMember instanceof ConstTypeNode) {
                $expr = $unionMember->constExpr;
                if ($expr instanceof ConstExprIntegerNode) {
                    $allowedMask |= (int) $expr->value;
                    $foundFlags = true;
                } elseif ($expr instanceof ConstFetchNode) {
                    $val = $this->resolveConstantValue($expr->className, $expr->name);
                    if (\is_int($val)) {
                        $allowedMask |= $val;
                        $foundFlags = true;
                    }
                }
            } elseif ($unionMember instanceof IdentifierTypeNode) {
                $target = $this->extractConstantTarget($unionMember);
                if ($target !== null) {
                    $val = $this->resolveConstantValue($target[0], $target[1]);
                    if (\is_int($val)) {
                        $allowedMask |= $val;
                        $foundFlags = true;
                    }
                }
            }
        }

        return [$allowedMask, $foundFlags];
    }

    /**
     * @return array{0: int, 1: bool}
     */
    private function resolveMaskOfPattern(string $className, string $pattern): array
    {
        $cacheKey = $className !== '' ? "$className::$pattern" : $pattern;

        if (isset(self::$maskOfCache[$cacheKey])) {
            return self::$maskOfCache[$cacheKey];
        }

        $allowedMask = 0;
        $foundFlags = false;

        if ($className !== '') {
            if (class_exists($className) || interface_exists($className)) {
                $refClass = new \ReflectionClass($className);

                if (str_contains($pattern, '*')) {
                    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                    foreach ($refClass->getConstants() as $cName => $cValue) {
                        if (\is_int($cValue) && preg_match($regex, $cName) === 1) {
                            $allowedMask |= $cValue;
                            $foundFlags = true;
                        }
                    }
                } else {
                    $cValue = $this->resolveConstantValue($className, $pattern);
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
        } else {
            if (str_contains($pattern, '*')) {
                $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                foreach (get_defined_constants() as $cName => $cValue) {
                    if (\is_int($cValue) && preg_match($regex, $cName) === 1) {
                        $allowedMask |= $cValue;
                        $foundFlags = true;
                    }
                }
            } else {
                $cValue = $this->resolveConstantValue('', $pattern);
                if (\is_int($cValue)) {
                    $allowedMask |= $cValue;
                    $foundFlags = true;
                }
            }
        }

        return self::$maskOfCache[$cacheKey] = [$allowedMask, $foundFlags];
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

        if ($valueTypeNode instanceof IdentifierTypeNode) {
            $elemName = $valueTypeNode->name;

            if ($elemName === 'int') {
                foreach ($value as $k => $v) {
                    if (! \is_int($v)) {
                        return ErrorFactory::createError($context . '[' . $k . '] must be of type int, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'string') {
                foreach ($value as $k => $v) {
                    if (! \is_string($v)) {
                        return ErrorFactory::createError($context . '[' . $k . '] must be of type string, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'bool') {
                foreach ($value as $k => $v) {
                    if (! \is_bool($v)) {
                        return ErrorFactory::createError($context . '[' . $k . '] must be of type bool, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'float') {
                foreach ($value as $k => $v) {
                    if (! \is_float($v) && ! \is_int($v)) {
                        return ErrorFactory::createError($context . '[' . $k . '] must be of type float, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }
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
            return $this->validateSingleTypeArray($value, $node->genericTypes[0], $context, $registry, $isSensitive, $count);
        }

        if ($typesCount >= 2) {
            return $this->validateKeyValueTypeArray($value, $node->genericTypes[0], $node->genericTypes[1], $context, $registry, $isSensitive, $count);
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function validateSingleTypeArray(
        array $value,
        TypeNode $valTypeNode,
        string $context,
        TypeValidatorRegistry $registry,
        bool $isSensitive,
        int $count
    ): ?ErrorMessage {
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

        if ($valTypeNode instanceof IdentifierTypeNode) {
            $elemName = $valTypeNode->name;

            if ($elemName === 'int') {
                foreach ($value as $k => $v) {
                    if (! \is_int($v)) {
                        $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                        return ErrorFactory::createError($context . '[' . $keyStr . '] must be of type int, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'string') {
                foreach ($value as $k => $v) {
                    if (! \is_string($v)) {
                        $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                        return ErrorFactory::createError($context . '[' . $keyStr . '] must be of type string, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'bool') {
                foreach ($value as $k => $v) {
                    if (! \is_bool($v)) {
                        $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                        return ErrorFactory::createError($context . '[' . $keyStr . '] must be of type bool, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'float') {
                foreach ($value as $k => $v) {
                    if (! \is_float($v) && ! \is_int($v)) {
                        $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                        return ErrorFactory::createError($context . '[' . $keyStr . '] must be of type float, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }
        }

        foreach ($value as $k => $v) {
            $err = $isComplexObjectGeneric
                ? $this->validateObjectGeneric($v, $valTypeNode, '', $isSensitive)
                : $registry->validate($v, $valTypeNode, '', $isSensitive);

            if ($err !== null) {
                return ErrorFactory::createError($context . '[' . $k . ']' . $err->getMessage());
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function validateKeyValueTypeArray(
        array $value,
        TypeNode $keyTypeNode,
        TypeNode $valTypeNode,
        string $context,
        TypeValidatorRegistry $registry,
        bool $isSensitive,
        int $count
    ): ?ErrorMessage {
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

    private function resolveIntBound(PhpDocNode $node): ?int
    {
        if ($node instanceof ConstExprIntegerNode) {
            return (int) $node->value;
        }

        if ($node instanceof ConstTypeNode) {
            $expr = $node->constExpr;
            if ($expr instanceof ConstExprIntegerNode) {
                return (int) $expr->value;
            }
            if ($expr instanceof ConstFetchNode) {
                $val = $this->resolveConstantValue($expr->className, $expr->name);
                if (\is_int($val)) {
                    return $val;
                }
            }
        }

        if ($node instanceof ConstFetchNode) {
            $val = $this->resolveConstantValue($node->className, $node->name);
            if (\is_int($val)) {
                return $val;
            }
        }

        if ($node instanceof IdentifierTypeNode) {
            $name = $node->name;
            $lower = strtolower($name);
            if ($lower === 'min' || $lower === 'max' || $lower === '*') {
                return null;
            }

            if (is_numeric($name)) {
                return (int) $name;
            }

            $target = $this->extractConstantTarget($node);
            if ($target !== null) {
                $val = $this->resolveConstantValue($target[0], $target[1]);
                if (\is_int($val)) {
                    return $val;
                }
            }
        }

        $str = (string) $node;
        if (is_numeric($str)) {
            return (int) $str;
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

        $minVal = $minNode !== null ? $this->resolveIntBound($minNode) : null;
        $maxVal = $maxNode !== null ? $this->resolveIntBound($maxNode) : null;

        $redact = $isSensitive || Config::isRedactValuesEnabled();

        if ($minVal !== null && $value < $minVal) {
            $valDisplay = $redact ? 'int given' : "$value given";

            return ErrorFactory::createError($context . " must be >= $minVal, $valDisplay");
        }

        if ($maxVal !== null && $value > $maxVal) {
            $valDisplay = $redact ? 'int given' : "$value given";

            return ErrorFactory::createError($context . " must be <= $maxVal, $valDisplay");
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
        $redact = $isSensitive || Config::isRedactValuesEnabled();

        if ($targetNode instanceof IdentifierTypeNode) {
            $targetName = $targetNode->name;
            $lower = strtolower($targetName);
            if ($lower === 'object' || $lower === 'mixed') {
                return null;
            }

            if (class_exists($targetName) || interface_exists($targetName) || trait_exists($targetName) || enum_exists($targetName)) {
                if (! is_a($value, $targetName, allow_string: true)) {
                    $valDisplay = $redact ? 'string given' : "'$value' given";

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

            $valDisplay = $redact ? 'string given' : "'$value' given";

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

    // =========================================================================
    // SHARED UTILITIES
    // =========================================================================

    /**
     * Extracts constant class name and constant name from ConstTypeNode or IdentifierTypeNode.
     *
     * @return array{0: string, 1: string}|null
     */
    private function extractConstantTarget(?TypeNode $targetType): ?array
    {
        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstFetchNode) {
            return [$targetType->constExpr->className, $targetType->constExpr->name];
        }

        if ($targetType instanceof ConstTypeNode && $targetType->constExpr instanceof ConstExprStringNode) {
            return ['', $targetType->constExpr->value];
        }

        if ($targetType instanceof IdentifierTypeNode) {
            if (str_contains($targetType->name, '::')) {
                [$className, $constName] = explode('::', $targetType->name, 2);

                return [$className, $constName];
            }

            return ['', $targetType->name];
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
}
