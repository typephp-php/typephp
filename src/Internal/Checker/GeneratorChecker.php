<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Internal\Docblock\DocblockParser;
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
     * O(1) Fast-path cache for generators determined to have no return contracts.
     *
     * @var array<string, true>
     */
    private static array $noContractCache = [];

    /**
     * Cache for resolved yield & send types of static / non-generic generators:
     * [$function] => array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}.
     *
     * @var array<string, array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}>
     */
    private static array $staticYieldTypeCache = [];

    /**
     * 2D Cache for resolved yield & send types of generic generators:
     * [$function][$templateSignature] => array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}.
     *
     * @var array<string, array<string, array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}>>
     */
    private static array $genericYieldTypeCache = [];

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

        $types = self::resolveYieldAndSendTypes($function, $thisOrClass);
        if ($types === null) {
            return $sendValue;
        }

        $sendTypeNode = $types[2];
        if ($sendTypeNode === null) {
            return $sendValue;
        }

        $err = $registry->validate($sendValue, $sendTypeNode, "$function(): Generator sent value (TSend)");

        return $err ?? $sendValue;
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue with zero-allocation caching.
     */
    public static function checkYield(
        string $function,
        mixed $key,
        mixed $value,
        TypeValidatorRegistry $registry,
        object|string|null $thisOrClass = null
    ): mixed {
        $types = self::resolveYieldAndSendTypes($function, $thisOrClass);
        if ($types === null) {
            return $value;
        }

        [$keyTypeNode, $itemTypeNode] = $types;

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
     * Resolves and caches the generator's yielded key, value, and sent types in memory.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}|null
     */
    private static function resolveYieldAndSendTypes(string $function, object|string|null $thisOrClass): ?array
    {
        if (isset(self::$noContractCache[$function])) {
            return null;
        }

        if (isset(self::$staticYieldTypeCache[$function])) {
            return self::$staticYieldTypeCache[$function];
        }

        $contract = DocblockParser::parse($function);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            self::$noContractCache[$function] = true;

            return null;
        }

        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $aliases = $contract['aliases'] ?? [];
        $hasGenerics = \count($allTemplates) > 0;
        $hasAliases = \count($aliases) > 0;
        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

        // Static / non-generic generator: cache permanently for this function
        if (! $hasGenerics && ! $hasAliases && $thisObj === null) {
            $resolvedNode = SpecialTypeResolver::resolve($returnTypeNode, $function, null);

            return self::$staticYieldTypeCache[$function] = self::extractYieldTypes($resolvedNode);
        }

        // Generic generator: check signature-based 2D cache
        $boundTemplates = $hasGenerics ? TemplateManager::getBoundTemplates($function, $thisObj, $allTemplates) : [];

        $sig = null;
        $boundCount = \count($boundTemplates);
        if ($boundCount > 0 && $boundCount <= 2 && ! $hasAliases && $thisObj === null) {
            if ($boundCount === 1) {
                $first = reset($boundTemplates);
                $sig = $first instanceof IdentifierTypeNode ? $first->name : (string) $first;
            } else {
                $sig = '';
                foreach ($boundTemplates as $v) {
                    $sig .= ($v instanceof IdentifierTypeNode ? $v->name : (string) $v) . '|';
                }
            }

            if (isset(self::$genericYieldTypeCache[$function][$sig])) {
                return self::$genericYieldTypeCache[$function][$sig];
            }
        }

        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $allTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $function, $thisObj);
        }

        $types = self::extractYieldTypes($returnTypeNode);

        if ($sig !== null) {
            self::$genericYieldTypeCache[$function][$sig] = $types;
        }

        return $types;
    }

    /**
     * Extracts yielded key, item, and sent (TSend) TypeNodes from a resolved generator/array AST node.
     *
     * @return array{0: ?TypeNode, 1: ?TypeNode, 2: ?TypeNode}
     */
    private static function extractYieldTypes(TypeNode $returnTypeNode): array
    {
        $itemTypeNode = null;
        $keyTypeNode = null;
        $sendTypeNode = null;

        if ($returnTypeNode instanceof GenericTypeNode) {
            $typesCount = \count($returnTypeNode->genericTypes);
            if ($typesCount === 1) {
                $itemTypeNode = $returnTypeNode->genericTypes[0];
            } elseif ($typesCount >= 2) {
                $keyTypeNode = $returnTypeNode->genericTypes[0];
                $itemTypeNode = $returnTypeNode->genericTypes[1];
                $sendTypeNode = $returnTypeNode->genericTypes[2] ?? null;
            }
        } elseif ($returnTypeNode instanceof ArrayTypeNode) {
            $itemTypeNode = $returnTypeNode->type;
        }

        return [$keyTypeNode, $itemTypeNode, $sendTypeNode];
    }
}
