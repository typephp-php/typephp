<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\Checker\GeneratorChecker;
use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;
use TypePHP\Wrapper\IterableWrapper;

/**
 * Core runtime type checking engine facade for parameter validation, return type enforcement, and variable tracking.
 */
final class RuntimeTypeChecker
{
    private static ?TypeValidatorRegistry $registry = null;

    /**
     * Returns whether TypePHP is globally enabled in configuration.
     */
    public static function isEnabled(): bool
    {
        return Config::isEnabled();
    }

    /**
     * Delegates generic template binding for class instances.
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?ErrorMessage
    {
        if (! Config::isEnabled()) {
            return null;
        }

        return TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);
    }

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(mixed $value, string $typeString, string $varName, string $file): mixed
    {
        if (! Config::isEnabled()) {
            return $value;
        }

        return InlineChecker::checkVariable($value, $typeString, $varName, $file, self::getRegistry());
    }

    /**
     * Evaluates class property validation dynamically based on configuration.
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file): mixed
    {
        if (! Config::isEnabled()) {
            return $value;
        }

        return InlineChecker::checkProperty($value, $objectOrClass, $propName, $file, self::getRegistry());
    }

    /**
     * Initializes generic call frames and returns a ScopeCleaner that pops the call frame on destruction.
     *
     * @param array<string, mixed> $vars
     */
    public static function setupScope(string $function, array $vars, object|string|null $thisOrClass = null): ErrorMessage|ScopeCleaner|null
    {
        if (! Config::isEnabled()) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        $err = ParamChecker::checkParams($function, $vars, $thisOrClass, self::getRegistry(), $effectiveFunction);

        $contract = ContractParser::parse($effectiveFunction);
        $methodTemplates = $contract['templates'] ?? [];
        $hasMethodTemplates = \count($methodTemplates) > 0;

        if ($err !== null) {
            if ($hasMethodTemplates) {
                TemplateManager::popCallFrame($effectiveFunction);
            }

            return $err;
        }

        return $hasMethodTemplates ? new ScopeCleaner($effectiveFunction) : null;
    }

    /**
     * Validates all incoming parameters against the function or method's declared contract.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, object|string|null $thisOrClass = null): ?ErrorMessage
    {
        if (! Config::isEnabled()) {
            return null;
        }

        return ParamChecker::checkParams($function, $vars, $thisOrClass, self::getRegistry());
    }

    /**
     * Validates a function or method's return value against its declared contract and returns value or ErrorMessage.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkReturn(string $function, mixed $value, object|string|null $thisOrClass = null, array $vars = []): mixed
    {
        if (! Config::isEnabled()) {
            return $value;
        }

        return ReturnChecker::checkReturn($function, $value, $thisOrClass, $vars, self::getRegistry(), [self::class, 'wrapIterable']);
    }

    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(string $function, mixed $sendValue, object|string|null $thisOrClass = null): mixed
    {
        if (! Config::isEnabled()) {
            return $sendValue;
        }

        return GeneratorChecker::checkSend($function, $sendValue, self::getRegistry(), $thisOrClass);
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue.
     */
    public static function checkYield(string $function, mixed $key, mixed $value, object|string|null $thisOrClass = null): mixed
    {
        if (! Config::isEnabled()) {
            return $value;
        }

        return GeneratorChecker::checkYield($function, $key, $value, self::getRegistry(), $thisOrClass);
    }

    /**
     * Wraps a callable parameter to intercept calls and validate inputs/returns dynamically.
     */
    public static function wrapCallable(string $function, string $paramName, mixed $callable, object|string|null $thisOrClass = null): mixed
    {
        if (! Config::isEnabled()) {
            return $callable;
        }

        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry(), $thisOrClass);
    }

    /**
     * Wraps an iterable or generator parameter to lazily validate items during iteration.
     */
    public static function wrapIterable(string $function, string $paramName, mixed $iterable, object|string|null $thisOrClass = null): mixed
    {
        if (! Config::isEnabled()) {
            return $iterable;
        }

        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry(), $thisOrClass);
    }

    /**
     * Registers a pending clone source object before clone execution.
     */
    public static function prepareClone(mixed $original): mixed
    {
        if (\is_object($original)) {
            TemplateManager::$pendingCloneSource = $original;
        }

        return $original;
    }

    /**
     * Copies generic template bindings from an original object to a cloned object instance.
     */
    public static function cloneInstance(mixed $cloned, mixed $original): mixed
    {
        if (\is_object($cloned) && \is_object($original)) {
            TemplateManager::copyInstanceBindings($original, $cloned);
        }

        TemplateManager::$pendingCloneSource = null;

        return $cloned;
    }

    /**
     * Infers a TypeNode AST representation from a raw PHP value.
     */
    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    /**
     * Returns a singleton instance of the TypeValidatorRegistry.
     */
    public static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }
}
