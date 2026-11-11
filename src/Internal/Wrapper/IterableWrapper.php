<?php

declare(strict_types=1);

namespace TypePHP\Internal\Wrapper;

use Generator;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Exception\TypeError as TypePHPTypeError;
use TypePHP\Internal\Checker\ConditionalChecker;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\IgnoreManager;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * Wraps Traversable objects and Generators to evaluate key and value type constraints lazily during iteration.
 *
 * @internal
 */
final class IterableWrapper
{
    /**
     * Wraps Traversable iterators and Generators to lazily validate keys and values during iteration.
     */
    public static function wrap(string $function, string $paramName, mixed $iterable, TypeValidatorRegistry $registry, object|string|null $thisOrClass = null): mixed
    {
        if (! is_iterable($iterable)) {
            return $iterable;
        }

        if (\is_array($iterable) && $paramName !== 'return') {
            return $iterable;
        }

        $contract = DocblockParser::parse($function);
        $typeNode = ($paramName === 'return') ? ($contract['return'] ?? null) : ($contract['types'][$paramName] ?? null);

        if ($typeNode === null) {
            return $iterable;
        }

        $aliases = $contract['aliases'] ?? [];
        $templates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $boundTemplates = TemplateManager::getBoundTemplates($function, $thisObj, $templates);

        if (ConditionalChecker::containsConditional($typeNode)) {
            $typeNode = ConditionalChecker::resolve($typeNode, [], $boundTemplates, $registry, $function);
        }

        if (\count($boundTemplates) > 0 || \count($templates) > 0) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $templates);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $function, $thisObj);
        }

        if (ConditionalChecker::containsConditional($typeNode)) {
            $typeNode = ConditionalChecker::resolve($typeNode, [], $boundTemplates, $registry, $function);
        }

        if (\count($boundTemplates) > 0 || \count($templates) > 0) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $templates);
            $typeNode = SpecialTypeResolver::resolve($typeNode, $function, $thisObj);
        }

        $baseName = '';
        if ($typeNode instanceof IdentifierTypeNode) {
            $baseName = strtolower(ltrim($typeNode->name, '\\'));
        } elseif ($typeNode instanceof GenericTypeNode) {
            $baseName = strtolower(ltrim((string) $typeNode->type, '\\'));
        }

        $standardIterables = ['iterable', 'traversable', 'iterator', 'generator'];
        if (! \in_array($baseName, $standardIterables, strict: true)) {
            return $iterable;
        }

        [$keyTypeNode, $itemTypeNode] = self::extractKeyAndItemTypeNodes($typeNode, $aliases);

        if (self::isUnconstrainedKey($keyTypeNode) && self::isUnconstrained($itemTypeNode)) {
            return $iterable;
        }

        $prefix = ($paramName === 'return') ? "$function(): Return iterator" : "$function(): Iterator \$$paramName";
        $typeCheckCallback = self::createValidationCallback($registry, $keyTypeNode, $itemTypeNode, $prefix, $function);

        if (\is_array($iterable)) {
            return self::wrapGenerator((function () use ($iterable) {
                yield from $iterable;
            })(), $typeCheckCallback);
        }

        if (! ($iterable instanceof Generator)) {
            return new IteratorProxy($iterable, $typeCheckCallback);
        }

        return self::wrapGenerator($iterable, $typeCheckCallback);
    }

    /**
     * Checks if a key type node is absent or covers all possible collection keys (mixed, array-key, or any scalar/object).
     */
    private static function isUnconstrainedKey(?TypeNode $node): bool
    {
        if ($node === null) {
            return true;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);

            return \in_array($lower, ['mixed', 'array-key'], true);
        }

        if ($node instanceof UnionTypeNode) {
            $types = [];
            foreach ($node->types as $t) {
                if ($t instanceof IdentifierTypeNode) {
                    $types[strtolower($t->name)] = true;
                }
            }

            if (isset($types['mixed']) || isset($types['array-key'])) {
                return true;
            }

            if (isset($types['string'], $types['int'], $types['bool'], $types['float'], $types['object'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks if a type node is absent or unconstrained mixed.
     */
    private static function isUnconstrained(?TypeNode $node): bool
    {
        if ($node === null) {
            return true;
        }

        return $node instanceof IdentifierTypeNode && strtolower($node->name) === 'mixed';
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
        string $prefix,
        string $function = ''
    ): \Closure {
        return function (mixed $key, mixed $value) use ($registry, $keyTypeNode, $itemTypeNode, $prefix, $function): void {
            if ($keyTypeNode !== null && $key !== null) {
                $err = $registry->validate($key, $keyTypeNode, "$prefix key");
                if ($err !== null) {
                    if ($function !== '' && CallerBoundaryResolver::shouldBypass($function)) {
                        return;
                    }

                    if (CallerBoundaryResolver::isCallerVendor()) {
                        return;
                    }

                    if (IgnoreManager::isCallerIgnored()) {
                        return;
                    }

                    $handled = ViolationCollector::handle($err, 'yield', null, function: $function);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }
            }

            if ($itemTypeNode !== null) {
                $err = $registry->validate($value, $itemTypeNode, "$prefix value");
                if ($err !== null) {
                    if ($function !== '' && CallerBoundaryResolver::shouldBypass($function)) {
                        return;
                    }

                    if (CallerBoundaryResolver::isCallerVendor()) {
                        return;
                    }

                    if (IgnoreManager::isCallerIgnored()) {
                        return;
                    }

                    $handled = ViolationCollector::handle($err, 'yield', null, function: $function);
                    if ($handled instanceof ErrorMessage) {
                        throw ErrorFactory::prepareException(new TypePHPTypeError($err->getMessage()));
                    }
                }
            }
        };
    }

    /**
     * Wraps an iterable generator in an interceptor generator to evaluate type checks on each yield.
     * Advances generators using valid()/current()/next() without calling rewind() on already-started generators.
     *
     * @param iterable<mixed, mixed> $iterable
     * @param \Closure(mixed, mixed): void $typeCheckCallback
     *
     * @return Generator<mixed, mixed>
     */
    private static function wrapGenerator(iterable $iterable, \Closure $typeCheckCallback): Generator
    {
        if ($iterable instanceof Generator) {
            while ($iterable->valid()) {
                $key = $iterable->key();
                $value = $iterable->current();
                $typeCheckCallback($key, $value);
                yield $key => $value;
                $iterable->next();
            }

            return;
        }

        foreach ($iterable as $key => $value) {
            $typeCheckCallback($key, $value);
            yield $key => $value;
        }
    }
}
