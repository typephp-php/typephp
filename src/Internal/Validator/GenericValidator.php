<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Node as PhpDocNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\RuntimeTypeChecker;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use WeakMap;

/**
 * @internal Validates values against generic AST structures (int ranges, class-string<T>, list<T>, array<K,V>, object generics, key-of, value-of, int-mask, int-mask-of).
 */
final class GenericValidator implements TypeValidatorInterface
{
    private const KIND_LIST = 1;
    private const KIND_NON_EMPTY_LIST = 2;
    private const KIND_ARRAY = 3;
    private const KIND_NON_EMPTY_ARRAY = 4;
    private const KIND_INT_RANGE = 5;
    private const KIND_CLASS_STRING = 6;
    private const KIND_KEY_OF = 7;
    private const KIND_VALUE_OF = 8;
    private const KIND_INT_MASK = 9;
    private const KIND_INT_MASK_OF = 10;
    private const KIND_OBJECT_GENERIC = 11;

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
     * @var WeakMap<GenericTypeNode, array{0: int, 1: mixed}>|null
     */
    private static ?WeakMap $compiledGenericCache = null;

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
     * @var array<string, array<string, true>>
     */
    private static array $classStringBoundCache = [];

    public static function reset(): void
    {
        self::$compiledGenericCache = null;
        self::$constantCache = [];
        self::$enumKeyCache = [];
        self::$enumValueCache = [];
        self::$maskOfCache = [];
        self::$classStringBoundCache = [];
    }

    /**
     * Validates a value against a GenericTypeNode AST.
     */
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var GenericTypeNode $genericNode */
        $genericNode = $node;

        if (self::$compiledGenericCache === null) {
            self::$compiledGenericCache = new WeakMap();
        }

        $compiled = self::$compiledGenericCache[$genericNode] ?? null;
        if ($compiled === null) {
            self::$compiledGenericCache[$genericNode] = $compiled = $this->compileGeneric($genericNode);
        }

        $kind = $compiled[0];

        if ($kind === self::KIND_LIST) {
            if (! \is_array($value) || ($value !== [] && ! array_is_list($value))) {
                return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            $count = \count($value);
            $valueTypeNode = $compiled[1];

            if (! ($valueTypeNode instanceof TypeNode) || $count === 0) {
                return null;
            }

            return CollectionElementValidator::validateElements(
                $value,
                $valueTypeNode,
                $context,
                $registry,
                isList: true,
                isSensitive: $isSensitive,
                count: $count,
            );
        }

        if ($kind === self::KIND_NON_EMPTY_LIST) {
            if (! \is_array($value) || ($value !== [] && ! array_is_list($value))) {
                return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            $count = \count($value);
            if ($count === 0) {
                return ErrorFactory::createError($context . ' must be a non-empty list, empty array given');
            }

            $valueTypeNode = $compiled[1];
            if (! ($valueTypeNode instanceof TypeNode)) {
                return null;
            }

            return CollectionElementValidator::validateElements(
                $value,
                $valueTypeNode,
                $context,
                $registry,
                isList: true,
                isSensitive: $isSensitive,
                count: $count,
            );
        }

        if ($kind === self::KIND_ARRAY) {
            /** @var array{requireNonEmpty: bool, typesCount: int, baseName: string, keyNode: ?TypeNode, valNode: ?TypeNode, keyIsArrayKey: bool, valIsMixed: bool, keyFast: int, valFast: int, valExtra: ?string, isComplexObjectGeneric: bool} $plan */
            $plan = $compiled[1];

            return $this->validateArrayWithPlan($value, $plan, $context, $registry, $isSensitive);
        }

        if ($kind === self::KIND_NON_EMPTY_ARRAY) {
            /** @var array{requireNonEmpty: bool, typesCount: int, baseName: string, keyNode: ?TypeNode, valNode: ?TypeNode, keyIsArrayKey: bool, valIsMixed: bool, keyFast: int, valFast: int, valExtra: ?string, isComplexObjectGeneric: bool} $plan */
            $plan = $compiled[1];

            return $this->validateArrayWithPlan($value, $plan, $context, $registry, $isSensitive);
        }

        if ($kind === self::KIND_INT_RANGE) {
            if (! \is_int($value)) {
                return ErrorFactory::createError($context . ' must be of type int, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            /** @var array{0: ?int, 1: ?int} $bounds */
            $bounds = $compiled[1];
            [$minVal, $maxVal] = $bounds;
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

        if ($kind === self::KIND_CLASS_STRING) {
            return $this->validateClassString($value, $genericNode, $context, $isSensitive);
        }

        if ($kind === self::KIND_KEY_OF) {
            return $this->validateKeyOf($value, $genericNode, $context, $registry, $isSensitive);
        }

        if ($kind === self::KIND_VALUE_OF) {
            return $this->validateValueOf($value, $genericNode, $context, $registry, $isSensitive);
        }

        if ($kind === self::KIND_INT_MASK) {
            return $this->validateIntMask($value, $genericNode, $context, $isSensitive);
        }

        if ($kind === self::KIND_INT_MASK_OF) {
            return $this->validateIntMaskOf($value, $genericNode, $context, $isSensitive);
        }

        if ($kind === self::KIND_OBJECT_GENERIC) {
            $targetClass = $compiled[1];
            if (! \is_string($targetClass)) {
                return null;
            }

            if (! \is_object($value)) {
                return ErrorFactory::createError($context . ' must be an object of type ' . $targetClass . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            if (! ($value instanceof $targetClass)) {
                return ErrorFactory::createError($context . ' must be an instance of ' . $targetClass . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
            }

            return TemplateManager::bindInstanceFromNode($value, $genericNode, $context);
        }

        return $this->validateObjectGeneric($value, $genericNode, $context, $isSensitive);
    }

    /**
     * @return array{0: int, 1: mixed}
     */
    private function compileGeneric(GenericTypeNode $node): array
    {
        $typeName = $node->type instanceof IdentifierTypeNode
            ? $node->type->name
            : (string) $node->type;
        $baseType = strtolower($typeName);

        return match ($baseType) {
            'list' => [self::KIND_LIST, $node->genericTypes[0] ?? null],
            'non-empty-list', 'non-empty-array-list' => [self::KIND_NON_EMPTY_LIST, $node->genericTypes[0] ?? null],
            'array', 'iterable', 'traversable', 'generator', 'iterator' => [self::KIND_ARRAY, $this->compileArrayPlan($node, $typeName, false)],
            'non-empty-array' => [self::KIND_NON_EMPTY_ARRAY, $this->compileArrayPlan($node, $typeName, true)],
            'int', 'integer' => [
                self::KIND_INT_RANGE,
                [
                    isset($node->genericTypes[0]) ? $this->resolveIntBound($node->genericTypes[0]) : null,
                    isset($node->genericTypes[1]) ? $this->resolveIntBound($node->genericTypes[1]) : null,
                ],
            ],
            'class-string' => [self::KIND_CLASS_STRING, $node->genericTypes[0] ?? null],
            'key-of' => [self::KIND_KEY_OF, null],
            'value-of' => [self::KIND_VALUE_OF, null],
            'int-mask' => [self::KIND_INT_MASK, null],
            'int-mask-of' => [self::KIND_INT_MASK_OF, null],
            default => [
                self::KIND_OBJECT_GENERIC,
                ClassNameValidator::isValid($typeName) ? $typeName : null,
            ],
        };
    }

    /**
     * @return array{
     *     requireNonEmpty: bool,
     *     typesCount: int,
     *     baseName: string,
     *     keyNode: ?TypeNode,
     *     valNode: ?TypeNode,
     *     keyIsArrayKey: bool,
     *     valIsMixed: bool,
     *     keyFast: int,
     *     valFast: int,
     *     valExtra: ?string,
     *     isComplexObjectGeneric: bool
     * }
     */
    private function compileArrayPlan(GenericTypeNode $node, string $baseName, bool $requireNonEmpty): array
    {
        $typesCount = \count($node->genericTypes);
        if ($typesCount === 0) {
            return [
                'requireNonEmpty' => $requireNonEmpty,
                'typesCount' => 0,
                'baseName' => $baseName,
                'keyNode' => null,
                'valNode' => null,
                'keyIsArrayKey' => true,
                'valIsMixed' => true,
                'keyFast' => 3,
                'valFast' => 6,
                'valExtra' => null,
                'isComplexObjectGeneric' => false,
            ];
        }

        if ($typesCount === 1) {
            $valNode = $node->genericTypes[0];
            $valIsMixed = $valNode instanceof IdentifierTypeNode
                && \in_array(strtolower($valNode->name), ['mixed', 'tvalue', 'v', 'value', 't', 'telement'], true);

            return [
                'requireNonEmpty' => $requireNonEmpty,
                'typesCount' => 1,
                'baseName' => $baseName,
                'keyNode' => null,
                'valNode' => $valNode,
                'keyIsArrayKey' => true,
                'valIsMixed' => $valIsMixed,
                'keyFast' => 3,
                'valFast' => 0,
                'valExtra' => null,
                'isComplexObjectGeneric' => false,
            ];
        }

        $keyNode = $node->genericTypes[0];
        $valNode = $node->genericTypes[1];

        $keyIsArrayKey = ($keyNode instanceof IdentifierTypeNode && \in_array(strtolower($keyNode->name), ['array-key', 'mixed', 'tkey', 'key', 'k'], true))
            || ($keyNode instanceof UnionTypeNode && self::isIntStringUnion($keyNode));

        $keyFast = 0;
        if ($keyIsArrayKey) {
            $keyFast = 3;
        } elseif ($keyNode instanceof IdentifierTypeNode) {
            $keyFast = match (strtolower($keyNode->name)) {
                'int', 'integer' => 1,
                'string' => 2,
                default => 0,
            };
        }

        $valIsMixed = $valNode instanceof IdentifierTypeNode
            && \in_array(strtolower($valNode->name), ['mixed', 'tvalue', 'v', 'value', 't', 'telement'], true);

        $valFast = 0;
        $valExtra = null;

        if ($valIsMixed) {
            $valFast = 6;
        } elseif ($valNode instanceof IdentifierTypeNode) {
            $vName = $valNode->name;
            $vLower = strtolower($vName);
            $valFast = match ($vLower) {
                'int', 'integer' => 1,
                'string' => 2,
                'bool', 'boolean' => 3,
                'float', 'double' => 4,
                'object' => 5,
                'array' => 7,
                'class-string' => 9,
                default => 0,
            };

            if ($valFast === 0 && ClassNameValidator::isValid($vName) && (class_exists($vName, false) || interface_exists($vName, false))) {
                $valFast = 8;
                $valExtra = $vName;
            }
        } elseif ($valNode instanceof UnionTypeNode) {
            if (self::isIntStringUnion($valNode)) {
                $valFast = 10;
            } elseif (\count($valNode->types) === 2) {
                $hasNull = false;
                $other = null;
                foreach ($valNode->types as $m) {
                    if ($m instanceof IdentifierTypeNode && strtolower($m->name) === 'null') {
                        $hasNull = true;
                    } else {
                        $other = $m;
                    }
                }
                if ($hasNull && $other instanceof IdentifierTypeNode) {
                    $oLower = strtolower($other->name);
                    if ($oLower === 'string') {
                        $valFast = 12;
                    } elseif ($oLower === 'int' || $oLower === 'integer') {
                        $valFast = 11;
                    } elseif ($oLower === 'bool' || $oLower === 'boolean') {
                        $valFast = 13;
                    } elseif (ClassNameValidator::isValid($other->name) && (class_exists($other->name, false) || interface_exists($other->name, false))) {
                        $valFast = 14;
                        $valExtra = $other->name;
                    }
                }
            }
        }

        $isComplexObjectGeneric = ($valNode instanceof GenericTypeNode && ! isset(self::BUILTIN_GENERICS[strtolower((string) $valNode->type)]));

        return [
            'requireNonEmpty' => $requireNonEmpty,
            'typesCount' => $typesCount,
            'baseName' => $baseName,
            'keyNode' => $keyNode,
            'valNode' => $valNode,
            'keyIsArrayKey' => $keyIsArrayKey,
            'valIsMixed' => $valIsMixed,
            'keyFast' => $keyFast,
            'valFast' => $valFast,
            'valExtra' => $valExtra,
            'isComplexObjectGeneric' => $isComplexObjectGeneric,
        ];
    }

    private static function isIntStringUnion(UnionTypeNode $node): bool
    {
        if (\count($node->types) !== 2) {
            return false;
        }

        $hasInt = false;
        $hasString = false;

        foreach ($node->types as $member) {
            if (! ($member instanceof IdentifierTypeNode)) {
                return false;
            }
            $lower = strtolower($member->name);
            if ($lower === 'int' || $lower === 'integer') {
                $hasInt = true;
            } elseif ($lower === 'string') {
                $hasString = true;
            } else {
                return false;
            }
        }

        return $hasInt && $hasString;
    }

    /**
     * @param array{
     *     requireNonEmpty: bool,
     *     typesCount: int,
     *     baseName: string,
     *     keyNode: ?TypeNode,
     *     valNode: ?TypeNode,
     *     keyIsArrayKey: bool,
     *     valIsMixed: bool,
     *     keyFast: int,
     *     valFast: int,
     *     valExtra: ?string,
     *     isComplexObjectGeneric: bool
     * } $plan
     */
    private function validateArrayWithPlan(
        mixed $value,
        array $plan,
        string $context,
        TypeValidatorRegistry $registry,
        bool $isSensitive = false
    ): ?ErrorMessage {
        if (! \is_array($value) && ! ($value instanceof \Traversable)) {
            return ErrorFactory::createError($context . ' must be of type ' . $plan['baseName'] . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (! \is_array($value)) {
            return null;
        }

        $count = \count($value);

        if ($plan['requireNonEmpty'] && $count === 0) {
            return ErrorFactory::createError($context . ' must be a non-empty array, empty array given');
        }

        if ($count === 0 || $plan['typesCount'] === 0) {
            return null;
        }

        if ($plan['typesCount'] === 1) {
            if ($plan['valIsMixed'] || $plan['valNode'] === null) {
                return null;
            }

            return CollectionElementValidator::validateElements(
                $value,
                $plan['valNode'],
                $context,
                $registry,
                isList: false,
                isSensitive: $isSensitive,
                count: $count,
            );
        }

        $keyFast = $plan['keyFast'];
        $valFast = $plan['valFast'];

        if ($keyFast === 3 && $valFast === 6) {
            return null;
        }

        /** @var TypeNode $valNode */
        $valNode = $plan['valNode'];
        /** @var TypeNode $keyNode */
        $keyNode = $plan['keyNode'];

        if ($keyFast === 3 && ! $plan['isComplexObjectGeneric'] && $valFast < 8) {
            return CollectionElementValidator::validateElements(
                $value,
                $valNode,
                $context,
                $registry,
                isList: false,
                isSensitive: $isSensitive,
                count: $count,
            );
        }

        $valExtra = $plan['valExtra'];
        $isComplexObjectGeneric = $plan['isComplexObjectGeneric'];

        if ($count > Config::HYBRID_SAMPLE_THRESHOLD && RuntimeTypeChecker::$arrayValidationHybrid) {
            $keys = array_keys($value);
            $sampleKeys = [$keys[0], $keys[$count - 1]];
            $samplesToTake = min(3, $count - 2);
            for ($i = 0; $i < $samplesToTake; $i++) {
                $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
            }

            foreach ($sampleKeys as $k) {
                if ($keyFast !== 3) {
                    $keyOk = match ($keyFast) {
                        1 => \is_int($k),
                        2 => \is_string($k),
                        default => false,
                    };
                    if (! $keyOk) {
                        $err = $registry->validate($k, $keyNode, '');
                        if ($err !== null) {
                            return ErrorFactory::createError($context . ' key' . $err->getMessage());
                        }
                    }
                }

                $v = $value[$k];
                if ($valFast !== 6) {
                    $valOk = match ($valFast) {
                        1 => \is_int($v),
                        2 => \is_string($v),
                        3 => \is_bool($v),
                        4 => \is_float($v) || \is_int($v),
                        5 => \is_object($v),
                        7 => \is_array($v),
                        8 => \is_object($v) && $valExtra !== null && ($v instanceof $valExtra),
                        9 => \is_string($v) && (ClassNameValidator::$validClassStringCache[$v] ?? ClassNameValidator::isValidClassString($v)),
                        10 => \is_int($v) || \is_string($v),
                        11 => $v === null || \is_int($v),
                        12 => $v === null || \is_string($v),
                        13 => $v === null || \is_bool($v),
                        14 => $v === null || (\is_object($v) && $valExtra !== null && ($v instanceof $valExtra)),
                        default => false,
                    };
                    if (! $valOk) {
                        $err = ($isComplexObjectGeneric && $valNode instanceof GenericTypeNode)
                            ? $this->validateObjectGeneric($v, $valNode, '', $isSensitive)
                            : $registry->validate($v, $valNode, '', $isSensitive);

                        if ($err !== null) {
                            return ErrorFactory::createError($context . "['" . $k . "']" . $err->getMessage());
                        }
                    }
                }
            }

            return null;
        }

        foreach ($value as $k => $v) {
            if ($keyFast !== 3) {
                $keyOk = match ($keyFast) {
                    1 => \is_int($k),
                    2 => \is_string($k),
                    default => false,
                };
                if (! $keyOk) {
                    $err = $registry->validate($k, $keyNode, '');
                    if ($err !== null) {
                        return ErrorFactory::createError($context . ' key' . $err->getMessage());
                    }
                }
            }

            if ($valFast !== 6) {
                $valOk = match ($valFast) {
                    1 => \is_int($v),
                    2 => \is_string($v),
                    3 => \is_bool($v),
                    4 => \is_float($v) || \is_int($v),
                    5 => \is_object($v),
                    7 => \is_array($v),
                    8 => \is_object($v) && $valExtra !== null && ($v instanceof $valExtra),
                    9 => \is_string($v) && (ClassNameValidator::$validClassStringCache[$v] ?? ClassNameValidator::isValidClassString($v)),
                    10 => \is_int($v) || \is_string($v),
                    11 => $v === null || \is_int($v),
                    12 => $v === null || \is_string($v),
                    13 => $v === null || \is_bool($v),
                    14 => $v === null || (\is_object($v) && $valExtra !== null && ($v instanceof $valExtra)),
                    default => false,
                };
                if (! $valOk) {
                    $err = ($isComplexObjectGeneric && $valNode instanceof GenericTypeNode)
                        ? $this->validateObjectGeneric($v, $valNode, '', $isSensitive)
                        : $registry->validate($v, $valNode, '', $isSensitive);

                    if ($err !== null) {
                        return ErrorFactory::createError($context . "['" . $k . "']" . $err->getMessage());
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extracts an integer flag from an int literal or an int BackedEnum case.
     */
    private static function extractIntFlag(mixed $val): ?int
    {
        if (\is_int($val)) {
            return $val;
        }

        if ($val instanceof \BackedEnum && \is_int($val->value)) {
            return $val->value;
        }

        return null;
    }

    private function validateKeyOf(mixed $value, GenericTypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        $targetType = $node->genericTypes[0] ?? null;
        if ($targetType === null) {
            return null;
        }

        if ($targetType instanceof GenericTypeNode) {
            $innerBase = strtolower((string) $targetType->type);
            if ($innerBase === 'value-of') {
                return $this->validateKeyOfNestedValueOf($value, $targetType, $context, $isSensitive);
            }

            if ($innerBase === 'list' || $innerBase === 'non-empty-list' || $innerBase === 'non-empty-array-list') {
                return $registry->validate($value, new IdentifierTypeNode('int'), $context, $isSensitive);
            }

            if (\in_array($innerBase, ['array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator'], true)) {
                $keyType = \count($targetType->genericTypes) >= 2
                    ? $targetType->genericTypes[0]
                    : new IdentifierTypeNode('array-key');

                return $registry->validate($value, $keyType, $context, $isSensitive);
            }
        }

        if ($targetType instanceof ArrayTypeNode) {
            return $registry->validate($value, new IdentifierTypeNode('array-key'), $context, $isSensitive);
        }

        if ($targetType instanceof IdentifierTypeNode) {
            $lower = strtolower($targetType->name);
            if ($lower === 'list' || $lower === 'non-empty-list') {
                return $registry->validate($value, new IdentifierTypeNode('int'), $context, $isSensitive);
            }
            if (\in_array($lower, ['array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator'], true)) {
                return $registry->validate($value, new IdentifierTypeNode('array-key'), $context, $isSensitive);
            }
            if (ClassNameValidator::isValid($targetType->name) && enum_exists($targetType->name)) {
                return $this->validateKeyOfEnum($value, $targetType->name, $context, $isSensitive);
            }
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
            $keys = [];
            foreach ($enumClass::cases() as $case) {
                $keys[] = $case->name;
            }
            self::$enumKeyCache[$enumClass] = $keys;
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

        if ($targetType instanceof GenericTypeNode) {
            $innerBase = strtolower((string) $targetType->type);
            if ($innerBase === 'list' || $innerBase === 'non-empty-list' || $innerBase === 'non-empty-array-list') {
                $valType = $targetType->genericTypes[0] ?? null;

                return $valType !== null ? $registry->validate($value, $valType, $context, $isSensitive) : null;
            }

            if (\in_array($innerBase, ['array', 'non-empty-array', 'iterable', 'traversable', 'generator', 'iterator'], true)) {
                $valType = \count($targetType->genericTypes) >= 2
                    ? $targetType->genericTypes[1]
                    : ($targetType->genericTypes[0] ?? null);

                return $valType !== null ? $registry->validate($value, $valType, $context, $isSensitive) : null;
            }
        }

        if ($targetType instanceof ArrayTypeNode) {
            return $registry->validate($value, $targetType->type, $context, $isSensitive);
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
            $values = [];
            foreach ($enumClass::cases() as $case) {
                $values[] = $case->value;
            }
            self::$enumValueCache[$enumClass] = $values;
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
                    $intVal = self::extractIntFlag($constVal);
                    if ($intVal !== null) {
                        $allowedMask |= $intVal;
                    }
                }
            } elseif ($typeNode instanceof IdentifierTypeNode) {
                $target = $this->extractConstantTarget($typeNode);
                if ($target !== null) {
                    $constVal = $this->resolveConstantValue($target[0], $target[1]);
                    $intVal = self::extractIntFlag($constVal);
                    if ($intVal !== null) {
                        $allowedMask |= $intVal;
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
            $target = null;
            if ($targetType instanceof IdentifierTypeNode && ClassNameValidator::isValid($targetType->name) && enum_exists($targetType->name)) {
                $target = [$targetType->name, '*'];
            } else {
                $target = $this->extractConstantTarget($targetType);
            }

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
                    $intVal = self::extractIntFlag($val);
                    if ($intVal !== null) {
                        $allowedMask |= $intVal;
                        $foundFlags = true;
                    }
                }
            } elseif ($unionMember instanceof IdentifierTypeNode) {
                $target = $this->extractConstantTarget($unionMember);
                if ($target !== null) {
                    $val = $this->resolveConstantValue($target[0], $target[1]);
                    $intVal = self::extractIntFlag($val);
                    if ($intVal !== null) {
                        $allowedMask |= $intVal;
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
            if (class_exists($className) || interface_exists($className) || enum_exists($className)) {
                /** @var class-string<object> $className */
                $refClass = new \ReflectionClass($className);

                if (str_contains($pattern, '*')) {
                    $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
                    foreach ($refClass->getConstants() as $cName => $cValue) {
                        $intVal = self::extractIntFlag($cValue);
                        if ($intVal !== null && preg_match($regex, $cName) === 1) {
                            $allowedMask |= $intVal;
                            $foundFlags = true;
                        }
                    }
                } else {
                    $cValue = $this->resolveConstantValue($className, $pattern);
                    $intVal = self::extractIntFlag($cValue);
                    if ($intVal !== null) {
                        $allowedMask |= $intVal;
                        $foundFlags = true;
                    } elseif (\is_array($cValue)) {
                        foreach ($cValue as $item) {
                            $itemVal = self::extractIntFlag($item);
                            if ($itemVal !== null) {
                                $allowedMask |= $itemVal;
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
                $intVal = self::extractIntFlag($cValue);
                if ($intVal !== null) {
                    $allowedMask |= $intVal;
                    $foundFlags = true;
                }
            }
        }

        return self::$maskOfCache[$cacheKey] = [$allowedMask, $foundFlags];
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

    private function validateClassString(mixed $value, GenericTypeNode $node, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        $targetClassNode = $node->genericTypes[0] ?? null;
        if (\is_string($value) && $targetClassNode instanceof IdentifierTypeNode && isset(self::$classStringBoundCache[$targetClassNode->name][$value])) {
            return null;
        }

        if (! \is_string($value) || ! ClassNameValidator::isValidClassString($value)) {
            return ErrorFactory::createError($context . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if ($targetClassNode === null) {
            return null;
        }

        return $this->validateClassStringBound($value, $targetClassNode, $context, $isSensitive);
    }

    private function validateClassStringBound(string $value, TypeNode $targetNode, string $context, bool $isSensitive = false): ?ErrorMessage
    {
        if ($targetNode instanceof IdentifierTypeNode) {
            $targetName = $targetNode->name;
            if (isset(self::$classStringBoundCache[$targetName][$value])) {
                return null;
            }

            $lower = strtolower($targetName);
            if ($lower === 'object' || $lower === 'mixed') {
                return null;
            }

            if (class_exists($targetName) || interface_exists($targetName) || trait_exists($targetName) || enum_exists($targetName)) {
                if (! is_a($value, $targetName, allow_string: true)) {
                    $redact = $isSensitive || Config::isRedactValuesEnabled();
                    $valDisplay = $redact ? 'string given' : "'$value' given";

                    return ErrorFactory::createError($context . ' must be a class-string of ' . $targetName . ", $valDisplay");
                }

                self::$classStringBoundCache[$targetName][$value] = true;
            }

            return null;
        }

        if ($targetNode instanceof UnionTypeNode) {
            foreach ($targetNode->types as $unionType) {
                if ($this->validateClassStringBound($value, $unionType, $context, $isSensitive) === null) {
                    return null;
                }
            }

            $redact = $isSensitive || Config::isRedactValuesEnabled();
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
        if (! ClassNameValidator::isValid((string) $node->type)) {
            return null;
        }

        if (! \is_object($value)) {
            return ErrorFactory::createError($context . ' must be an object of type ' . (string) $node->type . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if (! is_a($value, (string) $node->type)) {
            return ErrorFactory::createError($context . ' must be an instance of ' . (string) $node->type . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        return TemplateManager::bindInstanceFromNode($value, $node, $context);
    }

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
                if (class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn)) {
                    /** @var class-string<object> $fqcn */
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
