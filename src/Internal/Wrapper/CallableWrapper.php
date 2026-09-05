<?php

declare(strict_types=1);

namespace TypePHP\Internal\Wrapper;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionFunction;
use TypeError;
use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * Wraps callables to enforce argument and return type contracts dynamically at runtime.
 *
 * @internal
 */
final class CallableWrapper
{
    /**
     * Safely checks if a value is callable without triggering PHP 8.2+ deprecation warnings
     * on partially supported callables (e.g. 'static::method', ['static', 'method']).
     */
    public static function isCallable(mixed $value): bool
    {
        if ($value instanceof Closure) {
            return true;
        }

        if (\is_object($value)) {
            return method_exists($value, '__invoke');
        }

        if (\is_string($value)) {
            if ($value === '' || str_starts_with($value, 'static::') || str_starts_with($value, 'self::') || str_starts_with($value, 'parent::')) {
                return false;
            }

            return \is_callable($value);
        }

        if (\is_array($value)) {
            if (! isset($value[0], $value[1]) || \count($value) !== 2) {
                return false;
            }

            if (\is_string($value[0]) && \in_array(strtolower($value[0]), ['static', 'self', 'parent'], true)) {
                return false;
            }

            return \is_callable($value);
        }

        return false;
    }

    /**
     * Resolves callable contract metadata for a function parameter or return value and wraps the callable.
     */
    public static function wrap(string $function, string $paramName, mixed $callable, TypeValidatorRegistry $registry, object|string|null $thisOrClass = null): mixed
    {
        $contract = DocblockParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);
        $aliases = $contract['aliases'] ?? [];
        $templates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];

        if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
            $typeNode = $aliases[$typeNode->name];
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);

        if ($typeNode !== null && (\count($boundTemplates) > 0 || \count($templates) > 0)) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $templates);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $function, $thisObj);
        }

        $prefix = ($paramName === 'return') ? "$function(): Return value" : "$function(): Callback \$$paramName";

        if (self::isCallable($callable)) {
            return self::wrapTypeNode($typeNode, $callable, $prefix, $registry);
        }

        if (\is_array($callable) && $typeNode !== null) {
            $innerCallableTypeNode = null;

            if ($typeNode instanceof GenericTypeNode && \in_array(strtolower($typeNode->type->name), ['list', 'array', 'iterable'], strict: true)) {
                $innerCallableTypeNode = $typeNode->genericTypes[1] ?? $typeNode->genericTypes[0] ?? null;
            } elseif ($typeNode instanceof ArrayTypeNode) {
                $innerCallableTypeNode = $typeNode->type;
            }

            if ($innerCallableTypeNode instanceof CallableTypeNode) {
                $wrappedArray = [];
                foreach ($callable as $k => $item) {
                    if (self::isCallable($item)) {
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
        if (! ($typeNode instanceof CallableTypeNode) || ! self::isCallable($callable)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        self::enforceClosureConstraints($identifierName, $callable, $prefix);

        /** @var callable $callable */
        return function (...$args) use ($callable, $typeNode, $registry, $prefix) {
            self::validateCallbackArguments($typeNode, $args, $prefix, $registry);

            try {
                $result = $callable(...$args);
            } catch (TypeError $e) {
                throw ErrorFactory::prepareException($e);
            }

            $isVoidReturn = ($typeNode->returnType instanceof IdentifierTypeNode)
                && strtolower($typeNode->returnType->name) === 'void';

            if (! $isVoidReturn) {
                $err = $registry->validate($result, $typeNode->returnType, "$prefix return value");
                if ($err !== null) {
                    throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                }
            }

            if ($typeNode->returnType instanceof CallableTypeNode && self::isCallable($result)) {
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
        if (str_contains($identifierName, 'closure') && ! ($callable instanceof Closure)) {
            throw ErrorFactory::prepareException(new TypePHPTypeError($prefix . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given'));
        }

        if (str_contains($identifierName, 'static') && $callable instanceof Closure) {
            $refFunc = new ReflectionFunction($callable);
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
