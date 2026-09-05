<?php

declare(strict_types=1);

namespace TypePHP\Validator;

use Generator;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Traversable;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;

/**
 * Validates array and Traversable collection instances against ArrayTypeNode ASTs (Type[]).
 * Supports both exhaustive O(n) verification and Beartype-style hybrid O(1) sampling.
 *
 * @internal
 */
final class ArrayValidator implements TypeValidatorInterface
{
    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! \is_array($value) && ! ($value instanceof Traversable)) {
            return ErrorFactory::createError($context . ' must be of type array, ' . TypeFormatter::formatGivenValue($value) . ' given');
        }

        /** @var ArrayTypeNode $arrayNode */
        $arrayNode = $node;

        if ($value instanceof Generator) {
            return null;
        }

        if (\is_array($value)) {
            $count = \count($value);
            if ($count === 0) {
                return null;
            }

            if ($count > Config::HYBRID_SAMPLE_THRESHOLD && Config::isArrayValidationHybrid()) {
                return $this->validateArrayHybrid($value, $arrayNode, $context, $registry, $count);
            }

            foreach ($value as $k => $v) {
                $err = $registry->validate($v, $arrayNode->type, '');
                if ($err !== null) {
                    $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                    return ErrorFactory::createError($context . '[' . $keyStr . ']' . $err->getMessage());
                }
            }

            return null;
        }

        foreach ($value as $k => $v) {
            $err = $registry->validate($v, $arrayNode->type, '');
            if ($err !== null) {
                $keyStr = \is_string($k)
                    ? "'" . $k . "'"
                    : ((\is_scalar($k) || $k === null) ? (string) $k : get_debug_type($k));

                return ErrorFactory::createError($context . '[' . $keyStr . ']' . $err->getMessage());
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     */
    private function validateArrayHybrid(
        array $value,
        ArrayTypeNode $arrayNode,
        string $context,
        TypeValidatorRegistry $registry,
        int $count
    ): ?ErrorMessage {
        if (array_is_list($value)) {
            $sampleIndices = [0, $count - 1];
            $samplesToTake = min(3, $count - 2);
            for ($i = 0; $i < $samplesToTake; $i++) {
                $sampleIndices[] = mt_rand(1, $count - 2);
            }

            foreach ($sampleIndices as $idx) {
                $err = $registry->validate($value[$idx], $arrayNode->type, '');
                if ($err !== null) {
                    return ErrorFactory::createError($context . '[' . $idx . ']' . $err->getMessage());
                }
            }

            return null;
        }

        $keys = array_keys($value);
        $sampleKeys = [$keys[0], $keys[$count - 1]];
        $samplesToTake = min(3, $count - 2);
        for ($i = 0; $i < $samplesToTake; $i++) {
            $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
        }

        foreach ($sampleKeys as $k) {
            $err = $registry->validate($value[$k], $arrayNode->type, '');
            if ($err !== null) {
                $keyStr = \is_string($k) ? "'" . $k . "'" : (string) $k;

                return ErrorFactory::createError($context . '[' . $keyStr . ']' . $err->getMessage());
            }
        }

        return null;
    }
}
