<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Docblock\ContractParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates generator yield and send (TSend) type validations.
 */
final class GeneratorChecker
{
    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(
        string $function,
        mixed $sendValue,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null
    ): mixed {
        if ($sendValue === null) {
            return null;
        }

        $returnTypeNode = self::resolveGeneratorReturnType($function, $thisOrClass);
        if (! ($returnTypeNode instanceof GenericTypeNode)) {
            return $sendValue;
        }

        $sendTypeNode = $returnTypeNode->genericTypes[2] ?? null;
        if ($sendTypeNode === null) {
            return $sendValue;
        }

        $err = $registry->validate($sendValue, $sendTypeNode, "$function(): Generator sent value (TSend)");

        return $err ?? $sendValue;
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue.
     */
    public static function checkYield(
        string $function,
        mixed $key,
        mixed $value,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null
    ): mixed {
        $returnTypeNode = self::resolveGeneratorReturnType($function, $thisOrClass);
        if ($returnTypeNode === null) {
            return $value;
        }

        [$keyTypeNode, $itemTypeNode] = self::extractYieldTypes($returnTypeNode);

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

    /**
     * Resolves the generator's return contract, applying alias expansion, template substitution, and special types.
     */
    private static function resolveGeneratorReturnType(string $function, object|string|null $thisOrClass): ?TypeNode
    {
        $contract = ContractParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return null;
        }

        $aliases = $contract['aliases'] ?? [];
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $allTemplates);

        if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $allTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        }

        return $returnTypeNode;
    }

    /**
     * Extracts yielded key and item TypeNodes from a resolved generator/array AST node.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode}
     */
    private static function extractYieldTypes(TypeNode $returnTypeNode): array
    {
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

        return [$keyTypeNode, $itemTypeNode];
    }
}
