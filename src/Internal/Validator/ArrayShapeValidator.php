<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\ClassNameValidator;
use WeakMap;

/**
 * @internal Class for validating array shapes and tuple shapes like array{0: string, 1: int} or array{string, int}.
 */
final class ArrayShapeValidator implements TypeValidatorInterface
{
    private const CHECK_FALLBACK = 0;

    private const CHECK_INT = 1;

    private const CHECK_STRING = 2;

    private const CHECK_BOOL = 3;

    private const CHECK_FLOAT = 4;

    private const CHECK_POSITIVE_INT = 5;

    private const CHECK_NEGATIVE_INT = 6;

    private const CHECK_NON_POSITIVE_INT = 7;

    private const CHECK_NON_NEGATIVE_INT = 8;

    private const CHECK_NON_ZERO_INT = 9;

    private const CHECK_NON_EMPTY_STRING = 10;
    private const CHECK_ARRAY_KEY = 11;

    private const CHECK_ARRAY = 12;

    private const CHECK_LIST = 13;

    private const CHECK_MIXED = 14;

    private const CHECK_NULL = 15;

    private const CHECK_TRUE = 16;

    private const CHECK_FALSE = 17;

    private const CHECK_SCALAR = 18;

    private const CHECK_INT_OR_STRING = 19;

    private const CHECK_EXACT_STRING = 20;

    private const CHECK_EXACT_INT = 21;

    private const CHECK_STRING_MAP = 22;

    private const CHECK_INT_MAP = 23;

    private const CHECK_CLASS = 24;

    /**
     * Compiled shape metadata cached per ArrayShapeNode instance.
     *
     * @var WeakMap<ArrayShapeNode, array{
     *     isList: bool,
     *     sealed: bool,
     *     items: list<array{0: string|int, 1: bool, 2: TypeNode, 3: int, 4: string|int|array<int|string, true>|null, 5: bool}>,
     *     knownKeys: array<string|int, true>,
     *     unsealedKeyType: ?TypeNode,
     *     unsealedValueType: ?TypeNode
     * }>|null
     */
    private static ?WeakMap $compiledShapeCache = null;

    public static function reset(): void
    {
        self::$compiledShapeCache = null;
    }

    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        /** @var ArrayShapeNode $shapeNode */
        $shapeNode = $node;

        if (self::$compiledShapeCache === null) {
            self::$compiledShapeCache = new WeakMap();
        }

        $compiled = self::$compiledShapeCache[$shapeNode] ?? null;
        if ($compiled === null) {
            self::$compiledShapeCache[$shapeNode] = $compiled = self::compileShape($shapeNode);
        }

        if ($compiled['isList'] && $value !== [] && ! array_is_list($value)) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $matchedKeysCount = 0;

        foreach ($compiled['items'] as $item) {
            $key = $item[0];

            if (isset($value[$key])) {
                $v = $value[$key];
            } elseif (\array_key_exists($key, $value)) {
                $v = null;
            } else {
                if ($item[1]) {
                    continue;
                }

                return ErrorFactory::createError($context . " is missing required key '$key'");
            }

            $matchedKeysCount++;

            if ($item[5] && $v === null) {
                continue;
            }

            $ok = match ($item[3]) {
                self::CHECK_INT => \is_int($v),
                self::CHECK_STRING => \is_string($v),
                self::CHECK_POSITIVE_INT => \is_int($v) && $v > 0,
                self::CHECK_NON_EMPTY_STRING => \is_string($v) && $v !== '',
                self::CHECK_BOOL => \is_bool($v),
                self::CHECK_ARRAY_KEY => \is_int($v) || \is_string($v),
                self::CHECK_FLOAT => \is_float($v) || \is_int($v),
                self::CHECK_ARRAY => \is_array($v),
                self::CHECK_LIST => \is_array($v) && ($v === [] || array_is_list($v)),
                self::CHECK_EXACT_STRING => \is_string($v) && $v === $item[4],
                self::CHECK_STRING_MAP => \is_string($v) && \is_array($item[4]) && isset($item[4][$v]),
                self::CHECK_EXACT_INT => \is_int($v) && $v === $item[4],
                self::CHECK_INT_MAP => \is_int($v) && \is_array($item[4]) && isset($item[4][$v]),
                self::CHECK_INT_OR_STRING => \is_int($v) || \is_string($v),
                self::CHECK_TRUE => $v === true,
                self::CHECK_FALSE => $v === false,
                self::CHECK_NULL => $v === null,
                self::CHECK_MIXED => true,
                self::CHECK_SCALAR => \is_scalar($v),
                self::CHECK_CLASS => \is_object($v) && \is_string($item[4]) && is_a($v, $item[4]),
                self::CHECK_NEGATIVE_INT => \is_int($v) && $v < 0,
                self::CHECK_NON_NEGATIVE_INT => \is_int($v) && $v >= 0,
                self::CHECK_NON_POSITIVE_INT => \is_int($v) && $v <= 0,
                self::CHECK_NON_ZERO_INT => \is_int($v) && $v !== 0,
                default => false,
            };

            if ($ok) {
                continue;
            }

            $err = $registry->validate($v, $item[2], '', $isSensitive);
            if ($err !== null) {
                return ErrorFactory::createError($context . "['" . $key . "']" . $err->getMessage());
            }
        }

        $valueCount = \count($value);

        if ($valueCount === $matchedKeysCount) {
            return null;
        }

        $knownKeys = $compiled['knownKeys'];

        if ($compiled['sealed']) {
            foreach ($value as $k => $_) {
                if (! isset($knownKeys[$k])) {
                    return ErrorFactory::createError($context . " contains unsealed unexpected key '{$k}'");
                }
            }
        }

        if ($compiled['unsealedValueType'] !== null) {
            $unsealedKeyType = $compiled['unsealedKeyType'];
            $unsealedValueType = $compiled['unsealedValueType'];

            foreach ($value as $k => $v) {
                if (isset($knownKeys[$k])) {
                    continue;
                }

                if ($unsealedKeyType !== null) {
                    $err = $registry->validate($k, $unsealedKeyType, '');
                    if ($err !== null) {
                        return ErrorFactory::createError($context . " extra key '{$k}'" . $err->getMessage());
                    }
                }

                $err = $registry->validate($v, $unsealedValueType, '', $isSensitive);
                if ($err !== null) {
                    return ErrorFactory::createError($context . "['{$k}']" . $err->getMessage());
                }
            }
        }

        return null;
    }

    /**
     * @return array{
     *     isList: bool,
     *     sealed: bool,
     *     items: list<array{0: string|int, 1: bool, 2: TypeNode, 3: int, 4: string|int|array<int|string, true>|null, 5: bool}>,
     *     knownKeys: array<string|int, true>,
     *     unsealedKeyType: ?TypeNode,
     *     unsealedValueType: ?TypeNode
     * }
     */
    private static function compileShape(ArrayShapeNode $shapeNode): array
    {
        $items = [];
        $knownKeys = [];
        $nextAutoIndex = 0;

        foreach ($shapeNode->items as $item) {
            if ($item->keyName instanceof ConstExprStringNode) {
                $key = $item->keyName->value;
            } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                $key = (int) $item->keyName->value;
                $nextAutoIndex = max($nextAutoIndex, $key + 1);
            } elseif ($item->keyName instanceof IdentifierTypeNode) {
                $key = $item->keyName->name;
            } elseif ($item->keyName !== null) {
                $key = (string) $item->keyName;
            } else {
                $key = $nextAutoIndex;
                $nextAutoIndex++;
            }

            $knownKeys[$key] = true;
            [$checkKind, $checkData, $nullable] = self::compileItemType($item->valueType);
            $items[] = [$key, $item->optional, $item->valueType, $checkKind, $checkData, $nullable];
        }

        return [
            'isList' => $shapeNode->kind === ArrayShapeNode::KIND_LIST,
            'sealed' => $shapeNode->sealed,
            'items' => $items,
            'knownKeys' => $knownKeys,
            'unsealedKeyType' => $shapeNode->unsealedType?->keyType,
            'unsealedValueType' => $shapeNode->unsealedType?->valueType,
        ];
    }

    /**
     * @return array{0: int, 1: string|int|array<int|string, true>|null, 2: bool} [checkKind, checkData, nullable]
     */
    private static function compileItemType(TypeNode $typeNode): array
    {
        if ($typeNode instanceof NullableTypeNode) {
            [$check, $data] = self::compileItemType($typeNode->type);

            return [$check, $data, true];
        }

        if ($typeNode instanceof ConstTypeNode) {
            $expr = $typeNode->constExpr;
            if ($expr instanceof ConstExprStringNode) {
                return [self::CHECK_EXACT_STRING, $expr->value, false];
            }
            if ($expr instanceof ConstExprIntegerNode) {
                return [self::CHECK_EXACT_INT, (int) $expr->value, false];
            }
            if ($expr instanceof ConstExprTrueNode) {
                return [self::CHECK_TRUE, null, false];
            }
            if ($expr instanceof ConstExprFalseNode) {
                return [self::CHECK_FALSE, null, false];
            }
            if ($expr instanceof ConstExprNullNode) {
                return [self::CHECK_NULL, null, true];
            }

            return [self::CHECK_FALLBACK, null, false];
        }

        if ($typeNode instanceof IdentifierTypeNode) {
            $name = $typeNode->name;
            $lower = strtolower($name);

            return match ($lower) {
                'int', 'integer' => [self::CHECK_INT, null, false],
                'string' => [self::CHECK_STRING, null, false],
                'bool', 'boolean' => [self::CHECK_BOOL, null, false],
                'float', 'double' => [self::CHECK_FLOAT, null, false],
                'positive-int' => [self::CHECK_POSITIVE_INT, null, false],
                'negative-int' => [self::CHECK_NEGATIVE_INT, null, false],
                'non-positive-int' => [self::CHECK_NON_POSITIVE_INT, null, false],
                'non-negative-int', 'unsigned-int' => [self::CHECK_NON_NEGATIVE_INT, null, false],
                'non-zero-int' => [self::CHECK_NON_ZERO_INT, null, false],
                'non-empty-string' => [self::CHECK_NON_EMPTY_STRING, null, false],
                'array-key' => [self::CHECK_ARRAY_KEY, null, false],
                'array' => [self::CHECK_ARRAY, null, false],
                'list' => [self::CHECK_LIST, null, false],
                'mixed' => [self::CHECK_MIXED, null, false],
                'null' => [self::CHECK_NULL, null, true],
                'true' => [self::CHECK_TRUE, null, false],
                'false' => [self::CHECK_FALSE, null, false],
                'scalar' => [self::CHECK_SCALAR, null, false],
                default => (ClassNameValidator::isValid($name) && (class_exists($name, false) || interface_exists($name, false)))
                    ? [self::CHECK_CLASS, $name, false]
                    : [self::CHECK_FALLBACK, null, false],
            };
        }

        if ($typeNode instanceof UnionTypeNode) {
            $nullable = false;
            $nonNullMembers = [];

            foreach ($typeNode->types as $member) {
                if ($member instanceof NullableTypeNode) {
                    $nullable = true;
                    $nonNullMembers[] = $member->type;
                } elseif ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null') {
                    $nullable = true;
                } elseif ($member instanceof ConstTypeNode && $member->constExpr instanceof ConstExprNullNode) {
                    $nullable = true;
                } else {
                    $nonNullMembers[] = $member;
                }
            }

            if (\count($nonNullMembers) === 1) {
                [$innerCheck, $innerData] = self::compileItemType($nonNullMembers[0]);

                return [$innerCheck, $innerData, $nullable];
            }

            if (\count($nonNullMembers) === 2) {
                $t0 = $nonNullMembers[0];
                $t1 = $nonNullMembers[1];
                if ($t0 instanceof IdentifierTypeNode && $t1 instanceof IdentifierTypeNode) {
                    $n0 = strtolower($t0->name);
                    $n1 = strtolower($t1->name);
                    if (($n0 === 'int' || $n0 === 'integer') && $n1 === 'string') {
                        return [self::CHECK_INT_OR_STRING, null, $nullable];
                    }
                    if ($n0 === 'string' && ($n1 === 'int' || $n1 === 'integer')) {
                        return [self::CHECK_INT_OR_STRING, null, $nullable];
                    }
                }
            }

            $allStrings = true;
            $stringMap = [];
            foreach ($nonNullMembers as $member) {
                if ($member instanceof ConstTypeNode && $member->constExpr instanceof ConstExprStringNode) {
                    $stringMap[$member->constExpr->value] = true;
                } else {
                    $allStrings = false;

                    break;
                }
            }

            if ($allStrings && $stringMap !== []) {
                return [self::CHECK_STRING_MAP, $stringMap, $nullable];
            }

            $allInts = true;
            $intMap = [];
            foreach ($nonNullMembers as $member) {
                if ($member instanceof ConstTypeNode && $member->constExpr instanceof ConstExprIntegerNode) {
                    $intMap[(int) $member->constExpr->value] = true;
                } else {
                    $allInts = false;

                    break;
                }
            }

            if ($allInts && $intMap !== []) {
                return [self::CHECK_INT_MAP, $intMap, $nullable];
            }

            return [self::CHECK_FALLBACK, null, $nullable];
        }

        return [self::CHECK_FALLBACK, null, false];
    }
}
