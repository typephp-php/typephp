<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Wraps Traversable objects and Generators to evaluate key and value type constraints lazily during iteration.
 */
final class IterableWrapper
{
    /**
     * Wraps Traversable iterators and Generators to lazily validate keys and values during iteration.
     *
     * Performs the following steps:
     * 1. Resolves key and item TypeNodes from contract metadata or aliases.
     * 2. Constructs a callback to evaluate key and value type constraints.
     * 3. Wraps Traversable objects with IteratorProxy for rewindability and method forwarding.
     * 4. Wraps Generators with an interceptor generator to evaluate yielded items lazily.
     */
    public static function wrap(string $function, string $paramName, mixed $iterable, TypeValidatorRegistry $registry): mixed
    {
        if (! is_iterable($iterable) || \is_array($iterable)) {
            return $iterable;
        }

        $contract = ContractParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode !== null) {
            $baseName = '';
            if ($typeNode instanceof IdentifierTypeNode) {
                $baseName = strtolower($typeNode->name);
            } elseif ($typeNode instanceof GenericTypeNode) {
                $baseName = strtolower($typeNode->type->name);
            }

            $standardIterables = ['iterable', 'traversable', 'iterator', 'generator', 'iteratoraggregate', 'array'];
            if ($baseName !== '' && ! \in_array($baseName, $standardIterables, true)) {
                return $iterable;
            }
        }

        [$keyTypeNode, $itemTypeNode] = self::extractKeyAndItemTypeNodes($typeNode, $aliases);

        $prefix = ($paramName === 'return') ? "$function(): Return iterator" : "$function(): Iterator \$$paramName";
        $typeCheckCallback = self::createValidationCallback($registry, $keyTypeNode, $itemTypeNode, $prefix);

        if (! ($iterable instanceof \Generator)) {
            return new IteratorProxy($iterable, $typeCheckCallback);
        }

        return self::wrapGenerator($iterable, $typeCheckCallback);
    }

    /**
     * Extracts key and value TypeNodes from an iterable type node AST or type alias.
     *
     * @param array<string, TypeNode> $aliases
     *
     * @return array{?TypeNode, ?TypeNode}
     */
    private static function extractKeyAndItemTypeNodes(?TypeNode $typeNode, array $aliases): array
    {
        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $itemTypeNode = null;
        $keyTypeNode = null;

        if ($typeNode instanceof GenericTypeNode) {
            $typesCount = \count($typeNode->genericTypes);
            if ($typesCount === 1) {
                $itemTypeNode = $typeNode->genericTypes[0];
            } elseif ($typesCount >= 2) {
                $keyTypeNode = $typeNode->genericTypes[0];
                $itemTypeNode = $typeNode->genericTypes[1];
            }
        } elseif ($typeNode instanceof ArrayTypeNode) {
            $itemTypeNode = $typeNode->type;
        }

        return [$keyTypeNode, $itemTypeNode];
    }

    /**
     * Builds a validation closure to check yielded keys and values during iteration.
     *
     * @return \Closure(mixed, mixed): void
     */
    private static function createValidationCallback(
        TypeValidatorRegistry $registry,
        ?TypeNode $keyTypeNode,
        ?TypeNode $itemTypeNode,
        string $prefix
    ): \Closure {
        return function (mixed $key, mixed $value) use ($registry, $keyTypeNode, $itemTypeNode, $prefix): void {
            if ($keyTypeNode !== null && $key !== null) {
                $err = $registry->validate($key, $keyTypeNode, "$prefix key");
                if ($err !== null) {
                    throw ErrorFactory::prepareException(new \TypeError($err->getMessage()));
                }
            }

            if ($itemTypeNode !== null) {
                $err = $registry->validate($value, $itemTypeNode, "$prefix value");
                if ($err !== null) {
                    throw ErrorFactory::prepareException(new \TypeError($err->getMessage()));
                }
            }
        };
    }

    /**
     * Wraps an iterable generator in an interceptor generator to evaluate type checks on each yield.
     *
     * @param iterable<mixed, mixed> $iterable
     * @param \Closure(mixed, mixed): void $typeCheckCallback
     *
     * @return \Generator<mixed, mixed>
     */
    private static function wrapGenerator(iterable $iterable, \Closure $typeCheckCallback): \Generator
    {
        foreach ($iterable as $key => $value) {
            $typeCheckCallback($key, $value);
            yield $key => $value;
        }
    }
}
