<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Util\Config;

/**
 * @internal Shared utility for validating collection and list elements with tight loops and hybrid sampling.
 */
final class CollectionElementValidator
{
    /**
     * Validates all elements in an array against a TypeNode contract.
     *
     * @param array<mixed> $items
     */
    public static function validateElements(
        array $items,
        TypeNode $elementTypeNode,
        string $context,
        TypeValidatorRegistry $registry,
        bool $isList = false,
        bool $isSensitive = false,
        ?int $count = null,
    ): ?ErrorMessage {
        $count ??= \count($items);
        if ($count === 0) {
            return null;
        }

        if ($elementTypeNode instanceof IdentifierTypeNode && \in_array(strtolower($elementTypeNode->name), ['mixed', 't', 'tvalue', 'v', 'value', 'telement'], true)) {
            return null;
        }

        if ($count > Config::HYBRID_SAMPLE_THRESHOLD && Config::isArrayValidationHybrid()) {
            return self::validateHybrid($items, $elementTypeNode, $context, $registry, $count, $isList, $isSensitive);
        }

        if ($elementTypeNode instanceof IdentifierTypeNode) {
            $elemName = $elementTypeNode->name;

            if ($elemName === 'int') {
                foreach ($items as $k => $v) {
                    if (! \is_int($v)) {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type int, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'positive-int') {
                foreach ($items as $k => $v) {
                    if (! \is_int($v) || $v <= 0) {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type positive-int, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'string') {
                foreach ($items as $k => $v) {
                    if (! \is_string($v)) {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type string, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'non-empty-string') {
                foreach ($items as $k => $v) {
                    if (! \is_string($v) || $v === '') {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type non-empty-string, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'bool') {
                foreach ($items as $k => $v) {
                    if (! \is_bool($v)) {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type bool, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }

            if ($elemName === 'float') {
                foreach ($items as $k => $v) {
                    if (! \is_float($v) && ! \is_int($v)) {
                        return ErrorFactory::createError($context . self::formatKey($k, $isList) . ' must be of type float, ' . TypeFormatter::formatGivenValue($v, $isSensitive) . ' given');
                    }
                }

                return null;
            }
        }

        foreach ($items as $k => $v) {
            $err = $registry->validate($v, $elementTypeNode, '', $isSensitive);
            if ($err !== null) {
                return ErrorFactory::createError($context . self::formatKey($k, $isList) . $err->getMessage());
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $items
     */
    private static function validateHybrid(
        array $items,
        TypeNode $elementTypeNode,
        string $context,
        TypeValidatorRegistry $registry,
        int $count,
        bool $isList,
        bool $isSensitive,
    ): ?ErrorMessage {
        if ($isList || array_is_list($items)) {
            $sampleIndices = [0, $count - 1];
            $samplesToTake = min(3, $count - 2);
            for ($i = 0; $i < $samplesToTake; $i++) {
                $sampleIndices[] = mt_rand(1, $count - 2);
            }

            foreach ($sampleIndices as $idx) {
                $err = $registry->validate($items[$idx], $elementTypeNode, '', $isSensitive);
                if ($err !== null) {
                    return ErrorFactory::createError($context . '[' . $idx . ']' . $err->getMessage());
                }
            }

            return null;
        }

        $keys = array_keys($items);
        $sampleKeys = [$keys[0], $keys[$count - 1]];
        $samplesToTake = min(3, $count - 2);
        for ($i = 0; $i < $samplesToTake; $i++) {
            $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
        }

        foreach ($sampleKeys as $k) {
            $err = $registry->validate($items[$k], $elementTypeNode, '', $isSensitive);
            if ($err !== null) {
                $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                return ErrorFactory::createError($context . '[' . $keyStr . ']' . $err->getMessage());
            }
        }

        return null;
    }

    private static function formatKey(int|string $k, bool $isList): string
    {
        if ($isList) {
            return '[' . $k . ']';
        }

        return '[' . (\is_string($k) ? "'" . $k . "'" : (string) $k) . ']';
    }
}
