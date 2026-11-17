<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use WeakMap;

/**
 * @internal Class for validating array shapes and tuple shapes like array{0: string, 1: int} or array{string, int}.
 */
final class ArrayShapeValidator implements TypeValidatorInterface
{
    /**
     * Compiled shape metadata cached per ArrayShapeNode instance.
     *
     * @var WeakMap<ArrayShapeNode, array{
     *     isList: bool,
     *     sealed: bool,
     *     items: list<array{0: string|int, 1: bool, 2: TypeNode}>,
     *     knownKeys: array<string|int, true>,
     *     unsealedKeyType: ?TypeNode,
     *     unsealedValueType: ?TypeNode
     * }>|null
     */
    private static ?WeakMap $compiledShapeCache = null;

    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        if (! \is_array($value)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        /** @var ArrayShapeNode $shapeNode */
        $shapeNode = $node;

        self::$compiledShapeCache ??= new WeakMap();
        $compiled = self::$compiledShapeCache[$shapeNode] ??= self::compileShape($shapeNode);

        if ($compiled['isList'] && $value !== [] && ! array_is_list($value)) {
            return ErrorFactory::createError($context . ' must be a list, ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        $matchedKeysCount = 0;

        foreach ($compiled['items'] as [$key, $optional, $valueType]) {
            if (! \array_key_exists($key, $value)) {
                if (! $optional) {
                    return ErrorFactory::createError($context . " is missing required key '$key'");
                }

                continue;
            }

            $matchedKeysCount++;

            $err = $registry->validate($value[$key], $valueType, '', $isSensitive);
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
     *     items: list<array{0: string|int, 1: bool, 2: TypeNode}>,
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
            $items[] = [$key, $item->optional, $item->valueType];
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
}