<?php

declare(strict_types=1);

namespace TypePHP\Internal\Wrapper;

use Closure;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionFunction;
use TypeError;
use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\Checker\ConditionalChecker;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
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
     * Cache for monomorphized callable AST nodes by function, parameter name, and argument signature:
     * [$function][$paramName][$argTypesSignature] => CallableTypeNode.
     *
     * @var array<string, array<string, array<string, CallableTypeNode>>>
     */
    private static array $specializedCallableCache = [];

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

        if ($typeNode !== null && ConditionalChecker::containsConditional($typeNode)) {
            $typeNode = ConditionalChecker::resolve($typeNode, [], $boundTemplates, $registry, $function);
        }

        $prefix = ($paramName === 'return') ? "$function(): Return value" : "$function(): Callback \$$paramName";

        if (self::isCallable($callable)) {
            return self::wrapTypeNode($typeNode, $callable, $prefix, $registry, $function, $paramName);
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
                        $wrappedArray[$k] = self::wrapTypeNode($innerCallableTypeNode, $item, $itemPrefix, $registry, $function, $paramName);
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
    public static function wrapTypeNode(
        ?TypeNode $typeNode,
        mixed $callable,
        string $prefix,
        TypeValidatorRegistry $registry,
        string $function = '',
        string $paramName = 'callback'
    ): mixed {
        if (! ($typeNode instanceof CallableTypeNode) || ! self::isCallable($callable)) {
            return $callable;
        }

        $identifierName = strtolower(ltrim($typeNode->identifier->name, '\\'));
        self::enforceClosureConstraints($identifierName, $callable, $prefix);

        /** @var callable $callable */
        return self::createDispatcherClosure($typeNode, $callable, $prefix, $registry, $function, $paramName);
    }

    /**
     * Dispatches to a closure matching the exact by-reference signature of the callable.
     */
    private static function createDispatcherClosure(
        CallableTypeNode $typeNode,
        callable $callable,
        string $prefix,
        TypeValidatorRegistry $registry,
        string $function = '',
        string $paramName = 'callback'
    ): Closure {
        $hasAnyRef = false;
        $isVariadicRef = false;
        $refPattern = [];

        foreach ($typeNode->parameters as $p) {
            $refPattern[] = $p->isReference;
            if ($p->isReference) {
                $hasAnyRef = true;
                if ($p->isVariadic) {
                    $isVariadicRef = true;
                }
            }
        }

        if (! $hasAnyRef) {
            return function (...$args) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable(...$args);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        if ($isVariadicRef) {
            return function (&...$args) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable(...$args);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        if ($refPattern === [true]) {
            return function (mixed &$a = null) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $args = [&$a];
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable($a);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        if ($refPattern === [true, false]) {
            return function (mixed &$a = null, mixed $b = null) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $args = [&$a, $b];
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable($a, $b);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        if ($refPattern === [false, true]) {
            return function (mixed $a = null, mixed &$b = null) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $args = [$a, &$b];
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable($a, $b);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        if ($refPattern === [true, true]) {
            return function (mixed &$a = null, mixed &$b = null) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
                $args = [&$a, &$b];
                $effectiveTypeNode = $typeNode->templateTypes !== []
                    ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                    : $typeNode;

                self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

                try {
                    $result = $callable($a, $b);
                } catch (TypeError $e) {
                    throw ErrorFactory::prepareException($e);
                }

                self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

                return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
            };
        }

        return function (&...$args) use ($callable, $typeNode, $registry, $prefix, $function, $paramName) {
            $effectiveTypeNode = $typeNode->templateTypes !== []
                ? self::resolveEffectiveCallableTypeNode($typeNode, $args, $prefix, $registry, $callable, $function, $paramName)
                : $typeNode;

            self::validateCallbackArguments($effectiveTypeNode, $args, $prefix, $registry, $callable);

            try {
                $result = $callable(...$args);
            } catch (TypeError $e) {
                throw ErrorFactory::prepareException($e);
            }

            self::validateCallbackByRefMutations($effectiveTypeNode, $args, $prefix, $registry, $callable);

            return self::validateCallbackReturn($effectiveTypeNode, $result, $prefix, $registry, $callable);
        };
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private static function resolveEffectiveCallableTypeNode(
        CallableTypeNode $typeNode,
        array $args,
        string $prefix,
        TypeValidatorRegistry $registry,
        mixed $callable = null,
        string $function = '',
        string $paramName = 'callback'
    ): CallableTypeNode {
        if ($typeNode->templateTypes === []) {
            return $typeNode;
        }

        /** @var array<string, TemplateTagValueNode> $localTemplates */
        $localTemplates = [];
        foreach ($typeNode->templateTypes as $tTag) {
            if (! SpecialTypeResolver::isBuiltInTypeKeyword($tTag->name)) {
                $localTemplates[$tTag->name] = $tTag;
            }
        }

        if ($localTemplates === []) {
            return $typeNode;
        }

        $sigParts = [];
        foreach ($args as $arg) {
            $sigParts[] = get_debug_type($arg);
        }
        $sig = implode('|', $sigParts);

        if ($function !== '' && isset(self::$specializedCallableCache[$function][$paramName][$sig])) {
            return self::$specializedCallableCache[$function][$paramName][$sig];
        }

        $boundLocal = [];
        $argValues = array_values($args);

        foreach ($typeNode->parameters as $index => $paramNode) {
            $rawParamName = ltrim($paramNode->parameterName !== null ? $paramNode->parameterName : '', '$');

            if ($paramNode->isVariadic) {
                $variadicVals = \array_slice($argValues, $index);
                foreach ($variadicVals as $vIdx => $vVal) {
                    self::inferLocalTemplateFromParam(
                        $paramNode->type,
                        $vVal,
                        $localTemplates,
                        $boundLocal,
                        $prefix . ' variadic argument #' . ($index + $vIdx + 1),
                        $registry,
                        $callable,
                        $function
                    );
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
                self::inferLocalTemplateFromParam(
                    $paramNode->type,
                    $val,
                    $localTemplates,
                    $boundLocal,
                    $prefix . ' ' . $argLabel,
                    $registry,
                    $callable,
                    $function
                );
            }
        }

        if ($callable instanceof Closure) {
            try {
                $ref = new ReflectionFunction($callable);
                if ($ref->hasReturnType()) {
                    $retType = $ref->getReturnType();
                    if ($retType instanceof \ReflectionNamedType && $retType->getName() !== 'mixed') {
                        $retName = $retType->getName();
                        if ($typeNode->returnType instanceof IdentifierTypeNode && isset($localTemplates[$typeNode->returnType->name])) {
                            $rName = $typeNode->returnType->name;
                            if (! isset($boundLocal[$rName])) {
                                $boundLocal[$rName] = new IdentifierTypeNode($retName);
                            }
                        }
                    }
                }
            } catch (\Throwable) {
            }
        }

        $resolvedParams = [];
        foreach ($typeNode->parameters as $p) {
            $resolvedParams[] = new CallableTypeParameterNode(
                TemplateSubstitutor::substitute($p->type, $boundLocal, $localTemplates),
                $p->isReference,
                $p->isVariadic,
                $p->parameterName,
                $p->isOptional
            );
        }

        $resolvedReturn = TemplateSubstitutor::substitute($typeNode->returnType, $boundLocal, $localTemplates);
        $monomorphizedNode = new CallableTypeNode($typeNode->identifier, $resolvedParams, $resolvedReturn, $typeNode->templateTypes);

        if ($function !== '') {
            self::$specializedCallableCache[$function][$paramName][$sig] = $monomorphizedNode;
        }

        return $monomorphizedNode;
    }

    /**
     * @param array<string, TemplateTagValueNode> $localTemplates
     * @param array<string, TypeNode> $boundLocal
     */
    private static function inferLocalTemplateFromParam(
        TypeNode $typeNode,
        mixed $value,
        array $localTemplates,
        array &$boundLocal,
        string $context,
        TypeValidatorRegistry $registry,
        mixed $callable = null,
        string $function = ''
    ): void {
        if ($typeNode instanceof NullableTypeNode) {
            if ($value !== null) {
                self::inferLocalTemplateFromParam($typeNode->type, $value, $localTemplates, $boundLocal, $context, $registry, $callable, $function);
            }

            return;
        }

        if ($typeNode instanceof IdentifierTypeNode && isset($localTemplates[$typeNode->name])) {
            $tName = $typeNode->name;
            $tTag = $localTemplates[$tName];

            if ($tTag->bound !== null) {
                $boundToValidate = $tTag->bound;
                if ($function !== '') {
                    $boundToValidate = SpecialTypeResolver::resolve($tTag->bound, $function);
                } elseif ($callable instanceof Closure) {
                    try {
                        $cFile = (new ReflectionFunction($callable))->getFileName();
                        if ($cFile !== false && $cFile !== '') {
                            $boundToValidate = SpecialTypeResolver::resolveForFile($tTag->bound, $cFile);
                        }
                    } catch (\Throwable) {
                    }
                }

                $boundErr = $registry->validate($value, $boundToValidate, $context);
                if ($boundErr !== null) {
                    if ($callable !== null && CallerBoundaryResolver::shouldBypassCallback($callable, $context)) {
                        return;
                    }

                    $handled = ViolationCollector::handle($boundErr, 'callback', null);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($boundErr->getMessage()));
                    }
                }
            }

            $inferred = TemplateManager::inferTypeFromValue($value);

            if (! isset($boundLocal[$tName])) {
                $boundLocal[$tName] = $inferred;
            } else {
                $existingType = $boundLocal[$tName];
                if ($registry->validate($value, $existingType, '') !== null && $tTag->bound !== null) {
                    $boundToCheck = $function !== ''
                        ? SpecialTypeResolver::resolve($tTag->bound, $function)
                        : $tTag->bound;

                    if ($registry->validate($value, $boundToCheck, '') === null) {
                        $boundLocal[$tName] = self::unifyLocalTypes($existingType, $inferred);
                    }
                }
            }

            return;
        }

        if ($typeNode instanceof ArrayTypeNode && \is_array($value)) {
            foreach ($value as $item) {
                self::inferLocalTemplateFromParam($typeNode->type, $item, $localTemplates, $boundLocal, $context, $registry, $callable, $function);
            }

            return;
        }

        if ($typeNode instanceof GenericTypeNode) {
            $baseName = strtolower($typeNode->type->name);
            if (\in_array($baseName, ['list', 'array', 'iterable'], true) && \is_array($value)) {
                $innerType = $typeNode->genericTypes[1] ?? $typeNode->genericTypes[0] ?? null;
                if ($innerType !== null) {
                    foreach ($value as $item) {
                        self::inferLocalTemplateFromParam($innerType, $item, $localTemplates, $boundLocal, $context, $registry, $callable, $function);
                    }
                }
            }

            return;
        }

        if ($typeNode instanceof ArrayShapeNode && \is_array($value)) {
            $nextIdx = 0;
            foreach ($typeNode->items as $item) {
                $key = match (true) {
                    $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
                    $item->keyName instanceof ConstExprIntegerNode => (int) $item->keyName->value,
                    $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
                    $item->keyName !== null => (string) $item->keyName,
                    default => $nextIdx,
                };
                if (\is_int($key)) {
                    $nextIdx = max($nextIdx, $key + 1);
                }
                if (\array_key_exists($key, $value)) {
                    self::inferLocalTemplateFromParam($item->valueType, $value[$key], $localTemplates, $boundLocal, $context, $registry, $callable, $function);
                }
            }

            return;
        }

        if ($typeNode instanceof ObjectShapeNode && \is_object($value)) {
            foreach ($typeNode->items as $item) {
                $prop = $item->keyName instanceof IdentifierTypeNode ? $item->keyName->name : (string) $item->keyName;
                // @phpstan-ignore property.dynamicName
                if (isset($value->$prop) || property_exists($value, $prop)) {
                    // @phpstan-ignore property.dynamicName
                    $propVal = $value->$prop;
                    self::inferLocalTemplateFromParam($item->valueType, $propVal, $localTemplates, $boundLocal, $context, $registry, $callable, $function);
                }
            }
        }
    }

    private static function unifyLocalTypes(TypeNode $type1, TypeNode $type2): TypeNode
    {
        if ((string) $type1 === (string) $type2) {
            return $type1;
        }

        $types = [];
        if ($type1 instanceof UnionTypeNode) {
            $types = $type1->types;
        } else {
            $types[] = $type1;
        }

        if ($type2 instanceof UnionTypeNode) {
            foreach ($type2->types as $t) {
                $types[] = $t;
            }
        } else {
            $types[] = $type2;
        }

        $unique = [];
        $deduped = [];
        foreach ($types as $t) {
            $str = (string) $t;
            if (! isset($unique[$str])) {
                $unique[$str] = true;
                $deduped[] = $t;
            }
        }

        return \count($deduped) === 1 ? $deduped[0] : new UnionTypeNode($deduped);
    }

    private static function validateCallbackReturn(
        CallableTypeNode $typeNode,
        mixed $result,
        string $prefix,
        TypeValidatorRegistry $registry,
        mixed $callable
    ): mixed {
        $isVoidReturn = ($typeNode->returnType instanceof IdentifierTypeNode)
            && strtolower($typeNode->returnType->name) === 'void';

        if (! $isVoidReturn) {
            $err = $registry->validate($result, $typeNode->returnType, "$prefix return value");
            if ($err !== null) {
                if (! CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                    $handled = ViolationCollector::handle($err, 'callback', $result);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }
            }
        }

        if ($typeNode->returnType instanceof CallableTypeNode && self::isCallable($result)) {
            $result = self::wrapTypeNode($typeNode->returnType, $result, "$prefix: Returned callback", $registry);
        }

        return $result;
    }

    /**
     * Enforces strict Closure and static-closure constraints on the provided callable.
     */
    private static function enforceClosureConstraints(string $identifierName, mixed $callable, string $prefix): void
    {
        if (str_contains($identifierName, 'closure') && ! ($callable instanceof Closure)) {
            if (CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                return;
            }

            $err = new ErrorMessage($prefix . ' must be of type Closure, ' . TypeFormatter::formatGivenValue($callable) . ' given');
            $handled = ViolationCollector::handle($err, 'callback', null);

            if ($handled instanceof ErrorMessage) {
                throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
            }
        }

        if (str_contains($identifierName, 'static') && $callable instanceof Closure) {
            $refFunc = new ReflectionFunction($callable);
            if ($refFunc->getClosureThis() !== null) {
                if (CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                    return;
                }

                $err = new ErrorMessage($prefix . ' must be a static Closure (not bound to $this)');
                $handled = ViolationCollector::handle($err, 'callback', null);

                if ($handled instanceof ErrorMessage) {
                    throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                }
            }
        }
    }

    /**
     * Validates variadic, positional, and named arguments passed into an intercepted callback.
     *
     * @param array<int|string, mixed> $args
     */
    private static function validateCallbackArguments(
        CallableTypeNode $typeNode,
        array &$args,
        string $prefix,
        TypeValidatorRegistry $registry,
        mixed $callable = null
    ): void {
        $argValues = array_values($args);
        $argCount = \count($argValues);

        foreach ($typeNode->parameters as $index => $paramNode) {
            $rawParamName = ltrim($paramNode->parameterName !== null ? $paramNode->parameterName : '', '$');

            if ($paramNode->isVariadic) {
                for ($vIdx = $index; $vIdx < $argCount; $vIdx++) {
                    $err = $registry->validate($argValues[$vIdx], $paramNode->type, "$prefix variadic argument #" . ($vIdx + 1));
                    if ($err !== null) {
                        if ($callable !== null && CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                            continue;
                        }

                        $handled = ViolationCollector::handle($err, 'callback', null);
                        if ($handled instanceof ErrorMessage) {
                            throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                        }
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
                    if ($callable !== null && CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                        continue;
                    }

                    $handled = ViolationCollector::handle($err, 'callback', null);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }
            }
        }
    }

    /**
     * Validates in-place reference mutations after the callback finishes execution.
     *
     * @param array<int|string, mixed> $args
     */
    private static function validateCallbackByRefMutations(
        CallableTypeNode $typeNode,
        array &$args,
        string $prefix,
        TypeValidatorRegistry $registry,
        mixed $callable = null
    ): void {
        $argValues = array_values($args);
        $argCount = \count($argValues);

        foreach ($typeNode->parameters as $index => $paramNode) {
            if (! $paramNode->isReference) {
                continue;
            }

            $rawParamName = ltrim($paramNode->parameterName !== null ? $paramNode->parameterName : '', '$');

            if ($paramNode->isVariadic) {
                for ($vIdx = $index; $vIdx < $argCount; $vIdx++) {
                    $err = $registry->validate($argValues[$vIdx], $paramNode->type, "$prefix variadic argument #" . ($vIdx + 1));
                    if ($err !== null) {
                        if ($callable !== null && CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                            continue;
                        }

                        $handled = ViolationCollector::handle($err, 'callback', null);
                        if ($handled instanceof ErrorMessage) {
                            throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                        }
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
                    if ($callable !== null && CallerBoundaryResolver::shouldBypassCallback($callable, $prefix)) {
                        continue;
                    }

                    $handled = ViolationCollector::handle($err, 'callback', null);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }
            }
        }
    }
}
