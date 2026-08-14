<?php

declare(strict_types=1);

namespace TypePHP\Wrapper;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Wraps callables to enforce argument and return type contracts dynamically at runtime.
 */
final class CallableWrapper
{
    /**
     * Resolves callable contract metadata for a function parameter or return value and wraps the callable.
     */
    public static function wrap(string $function, string $paramName, mixed $callable, TypeValidatorRegistry $registry): mixed
    {
        $contract = ContractParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $prefix = ($paramName === 'return') ? "$function(): Return value" : "$function(): Callback \$$paramName";

        if (\is_callable($callable)) {
            return self::wrapTypeNode($typeNode, $callable, $prefix, $registry);
        }

        if (\is_array($callable) && $typeNode !== null) {
            $innerCallableTypeNode = null;

            if ($typeNode instanceof GenericTypeNode && \in_array(strtolower($typeNode->type->name), ['list', 'array', 'iterable'], true)) {
                $innerCallableTypeNode = $typeNode->genericTypes[1] ?? $typeNode->genericTypes[0] ?? null;
            } elseif ($typeNode instanceof ArrayTypeNode) {
                $innerCallableTypeNode = $typeNode->type;
            }

            if ($innerCallableTypeNode instanceof CallableTypeNode) {
                $wrappedArray = [];
                foreach ($callable as $k => $item) {
                    if (\is_callable($item)) {
                        $itemPrefix = $prefix . (\is_int($k) ? "[$k]" : "['$k']");
                        $wrappedArray[$k] = self::wrapTypeNode($innerCallableTypeNode, $item, $itemPrefix, $registry);
                    } else {
                        $wrappedArray[$k] = $item;
                    }
                }

                return $wrappedArray;
            }
        }

        return $callable;
    }

    /**
     * Wraps a callable with runtime argument and return value type validation based on a CallableTypeNode AST.
     */
    public static function wrapTypeNode(?TypeNode $typeNode, mixed $callable, string $prefix, TypeValidatorRegistry $registry): mixed
    {
        if (! \is_callable($callable) || ! ($typeNode instanceof CallableTypeNode)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        self::enforceClosureConstraints($identifierName, $callable, $prefix);

        return function (...$args) use ($callable, $typeNode, $registry, $prefix) {
            self::validateCallbackArguments($typeNode, $args, $prefix, $registry);

            $result = $callable(...$args);

            $err = $registry->validate($result, $typeNode->returnType, "$prefix return value");
            if ($err !== null) {
                throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
            }

            if ($typeNode->returnType instanceof CallableTypeNode && \is_callable($result)) {
                $result = self::wrapTypeNode($typeNode->returnType, $result, "$prefix: Returned callback", $registry);
            }

            return $result;
        };
    }

    /**
     * Enforces strict Closure and static-closure constraints on the provided callable.
     */
    private static function enforceClosureConstraints(string $identifierName, mixed $callable, string $prefix): void
    {
        if (str_contains($identifierName, 'closure') && ! ($callable instanceof \Closure)) {
            throw ErrorFactory::prepareException(new TypePHPTypeError($prefix . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given'));
        }

        if (str_contains($identifierName, 'static') && $callable instanceof \Closure) {
            $refFunc = new \ReflectionFunction($callable);
            if ($refFunc->getClosureThis() !== null) {
                throw ErrorFactory::prepareException(new TypePHPTypeError($prefix . ' must be a static Closure (not bound to $this)'));
            }
        }
    }

    /**
     * Validates variadic, positional, and named arguments passed into an intercepted callback.
     *
     * @param array<int|string, mixed> $args
     */
    private static function validateCallbackArguments(CallableTypeNode $typeNode, array $args, string $prefix, TypeValidatorRegistry $registry): void
    {
        $argValues = array_values($args);
        $argCount = \count($argValues);

        foreach ($typeNode->parameters as $index => $paramNode) {
            $rawParamName = ltrim($paramNode->parameterName ?? '', '$');

            if ($paramNode->isVariadic) {
                for ($vIdx = $index; $vIdx < $argCount; $vIdx++) {
                    $err = $registry->validate($argValues[$vIdx], $paramNode->type, "$prefix variadic argument #" . ($vIdx + 1));
                    if ($err !== null) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }

                break;
            }

            $val = null;
            $hasVal = false;

            if ($rawParamName !== '' && \array_key_exists($rawParamName, $args)) {
                $val = $args[$rawParamName];
                $hasVal = true;
            } elseif (\array_key_exists($index, $argValues)) {
                $val = $argValues[$index];
                $hasVal = true;
            }

            if ($hasVal) {
                $argLabel = $rawParamName !== '' ? "\$$rawParamName" : ('argument #' . ($index + 1));
                $err = $registry->validate($val, $paramNode->type, "$prefix $argLabel");
                if ($err !== null) {
                    throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                }
            }
        }
    }
}
