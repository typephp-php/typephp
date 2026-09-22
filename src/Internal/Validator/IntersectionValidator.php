<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;

final class IntersectionValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var IntersectionTypeNode $intersectionNode */
        $intersectionNode = $node;

        $types = $intersectionNode->types;

        if (\is_array($value)) {
            $types = self::mergeArrayShapesInTypes($types);
        }

        if (\is_object($value)) {
            $types = self::mergeObjectShapesInTypes($types);
        }

        foreach ($types as $type) {
            $err = $registry->validate($value, $type, $context, $isSensitive);
            if ($err !== null) {
                $msg = $err->getMessage();

                if (
                    str_starts_with($msg, $context . '[') ||
                    str_starts_with($msg, $context . '->') ||
                    str_starts_with($msg, $context . ' is missing required') ||
                    str_starts_with($msg, $context . ' contains unsealed') ||
                    str_starts_with($msg, $context . ' property') ||
                    str_starts_with($msg, $context . ' key')
                ) {
                    return $err;
                }

                return ErrorFactory::createError(
                    $context . ' must be of type ' . $intersectionNode . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given'
                );
            }
        }

        return null;
    }

    /**
     * @param array<TypeNode> $types
     *
     * @return array<TypeNode>
     */
    private static function mergeArrayShapesInTypes(array $types): array
    {
        /** @var list<ArrayShapeNode> $shapes */
        $shapes = [];

        foreach ($types as $type) {
            if ($type instanceof ArrayShapeNode) {
                $shapes[] = $type;
            }
        }

        if (\count($shapes) < 2) {
            return $types;
        }

        $mergedShape = self::mergeArrayShapes($shapes);

        $newTypes = [];
        $mergedInserted = false;

        foreach ($types as $type) {
            if ($type instanceof ArrayShapeNode) {
                if (! $mergedInserted) {
                    $newTypes[] = $mergedShape;
                    $mergedInserted = true;
                }
            } else {
                $newTypes[] = $type;
            }
        }

        return $newTypes;
    }

    /**
     * @param list<ArrayShapeNode> $shapes
     */
    private static function mergeArrayShapes(array $shapes): ArrayShapeNode
    {
        /** @var array<string|int, array{keyName: ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode|null, optional: bool, valueTypes: list<TypeNode>}> $itemsByKey */
        $itemsByKey = [];
        $isSealed = true;
        $unsealedType = null;
        $kind = ArrayShapeNode::KIND_ARRAY;

        foreach ($shapes as $shape) {
            if (! $shape->sealed) {
                $isSealed = false;
                if ($shape->unsealedType !== null) {
                    $unsealedType = $shape->unsealedType;
                }
            }

            if ($shape->kind === ArrayShapeNode::KIND_LIST) {
                $kind = ArrayShapeNode::KIND_LIST;
            }

            foreach ($shape->items as $item) {
                $key = self::extractItemKey($item->keyName);
                if ($key === null) {
                    $key = \count($itemsByKey);
                }

                if (! isset($itemsByKey[$key])) {
                    $itemsByKey[$key] = [
                        'keyName' => $item->keyName,
                        'optional' => $item->optional,
                        'valueTypes' => [$item->valueType],
                    ];
                } else {
                    $itemsByKey[$key]['optional'] = $itemsByKey[$key]['optional'] && $item->optional;
                    $itemsByKey[$key]['valueTypes'][] = $item->valueType;
                }
            }
        }

        $mergedItems = [];
        foreach ($itemsByKey as $info) {
            $valueType = \count($info['valueTypes']) === 1
                ? $info['valueTypes'][0]
                : new IntersectionTypeNode($info['valueTypes']);

            $mergedItems[] = new ArrayShapeItemNode(
                $info['keyName'],
                $info['optional'],
                $valueType
            );
        }

        if ($isSealed) {
            return ArrayShapeNode::createSealed($mergedItems, $kind);
        }

        return ArrayShapeNode::createUnsealed($mergedItems, $unsealedType, $kind);
    }

    /**
     * @param array<TypeNode> $types
     *
     * @return array<TypeNode>
     */
    private static function mergeObjectShapesInTypes(array $types): array
    {
        /** @var list<ObjectShapeNode> $shapes */
        $shapes = [];

        foreach ($types as $type) {
            if ($type instanceof ObjectShapeNode) {
                $shapes[] = $type;
            }
        }

        if (\count($shapes) < 2) {
            return $types;
        }

        $mergedShape = self::mergeObjectShapes($shapes);

        $newTypes = [];
        $mergedInserted = false;

        foreach ($types as $type) {
            if ($type instanceof ObjectShapeNode) {
                if (! $mergedInserted) {
                    $newTypes[] = $mergedShape;
                    $mergedInserted = true;
                }
            } else {
                $newTypes[] = $type;
            }
        }

        return $newTypes;
    }

    /**
     * @param list<ObjectShapeNode> $shapes
     */
    private static function mergeObjectShapes(array $shapes): ObjectShapeNode
    {
        /** @var array<string, array{keyName: ConstExprStringNode|IdentifierTypeNode, optional: bool, valueTypes: list<TypeNode>}> $itemsByKey */
        $itemsByKey = [];

        foreach ($shapes as $shape) {
            foreach ($shape->items as $item) {
                $prop = (string) $item->keyName;

                if (! isset($itemsByKey[$prop])) {
                    $itemsByKey[$prop] = [
                        'keyName' => $item->keyName,
                        'optional' => $item->optional,
                        'valueTypes' => [$item->valueType],
                    ];
                } else {
                    $itemsByKey[$prop]['optional'] = $itemsByKey[$prop]['optional'] && $item->optional;
                    $itemsByKey[$prop]['valueTypes'][] = $item->valueType;
                }
            }
        }

        $mergedItems = [];
        foreach ($itemsByKey as $info) {
            $valueType = \count($info['valueTypes']) === 1
                ? $info['valueTypes'][0]
                : new IntersectionTypeNode($info['valueTypes']);

            $mergedItems[] = new ObjectShapeItemNode(
                $info['keyName'],
                $info['optional'],
                $valueType
            );
        }

        return new ObjectShapeNode($mergedItems);
    }

    private static function extractItemKey(ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode|null $keyName): string|int|null
    {
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
}
