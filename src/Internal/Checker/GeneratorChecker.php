<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates generator yield and send (TSend) type validations.
 */
final class GeneratorChecker
{
    public static function checkSend(string $function, mixed $sendValue, TypeValidatorRegistry $registry): mixed
    {
        if ($sendValue === null) {
            return null;
        }

        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $sendTypeNode = $returnTypeNode->genericTypes[2] ?? null;

            if ($sendTypeNode !== null) {
                $err = $registry->validate($sendValue, $sendTypeNode, "$function(): Generator sent value (TSend)");
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return $sendValue;
    }

    public static function checkYield(string $function, mixed $key, mixed $value, TypeValidatorRegistry $registry): mixed
    {
        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return $value;
        }

        $itemTypeNode = null;
        $keyTypeNode = null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $typesCount = \count($returnTypeNode->genericTypes);
            if ($typesCount === 1) {
                $itemTypeNode = $returnTypeNode->genericTypes[0];
            } elseif ($typesCount >= 2) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
            }
        } elseif ($returnTypeNode instanceof ArrayTypeNode) {
            $itemTypeNode = $returnTypeNode->type;
        }

        if ($key !== null && $keyTypeNode !== null) {
            $err = $registry->validate($key, $keyTypeNode, "$function(): Return iterator key");
            if ($err !== null) {
                return $err;
            }
        }

        if ($itemTypeNode !== null) {
            $err = $registry->validate($value, $itemTypeNode, "$function(): Return iterator value");
            if ($err !== null) {
                return $err;
            }
        }

        return $value;
    }
}
