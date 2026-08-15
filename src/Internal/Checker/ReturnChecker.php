<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;
use TypePHP\Wrapper\CallableWrapper;

/**
 * @internal Evaluates function and method return contract validations (including dynamic @method calls via __call / __callStatic).
 */
final class ReturnChecker
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function checkReturn(string $function, mixed $value, object|string|null $thisOrClass, array $vars, TypeValidatorRegistry $registry, callable $wrapIterableCallback): mixed
    {
        if (! (bool) (Config::get()['returns'] ?? true)) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = $function;

        if (str_contains($function, '::')) {
            [$classOrTrait, $methodName] = explode('::', $function, 2);
            $actualClassName = \is_object($thisOrClass) ? \get_class($thisOrClass) : (\is_string($thisOrClass) ? $thisOrClass : null);
            if ($actualClassName !== null && $actualClassName !== $classOrTrait) {
                $effectiveFunction = $actualClassName . '::' . $methodName;
            }

            if ($thisObj !== null) {
                $targetClass = $actualClassName ?? $classOrTrait;
                $traitAliases = HierarchyResolver::getTraitAliases($targetClass);

                if (\count($traitAliases) > 0) {
                    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 5);
                    foreach ($trace as $frame) {
                        $frameFunc = $frame['function'];
                        $frameClass = $frame['class'] ?? '';
                        if (($frameClass === $actualClassName || $frameClass === $classOrTrait) && isset($traitAliases[$frameFunc])) {
                            $effectiveFunction = $targetClass . '::' . $frameFunc;

                            break;
                        }
                    }
                }
            }
        }

        $isMagicCall = str_ends_with($effectiveFunction, '::__call') || str_ends_with($effectiveFunction, '::__callStatic');
        if ($isMagicCall && (bool) (Config::get()['magic_methods'] ?? true)) {
            $magicMethodName = array_values($vars)[0] ?? null;
            $rawMagicArgs = array_values($vars)[1] ?? [];
            /** @var array<int|string, mixed> $magicArgs */
            $magicArgs = \is_array($rawMagicArgs) ? $rawMagicArgs : [];

            if (\is_string($magicMethodName)) {
                $className = explode('::', $effectiveFunction, 2)[0];
                $magicContract = ContractParser::parseMagicMethod($className, $magicMethodName);

                if ($magicContract !== null && $magicContract['return'] !== null) {
                    $returnTypeNode = $magicContract['return'];
                    $magicFunction = $className . '::' . $magicMethodName;

                    $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $magicFunction);
                    if ($err !== null) {
                        return $err;
                    }

                    $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $magicFunction, $thisObj);

                    $aliases = $magicContract['aliases'] ?? [];
                    if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
                        $returnTypeNode = $aliases[$returnTypeNode->name];
                    }

                    $boundTemplates = TemplateManager::getBoundTemplates($magicFunction, $thisObj, $magicContract['templates']);
                    $declaredTemplates = $magicContract['templates'];

                    if (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0) {
                        $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $declaredTemplates);
                        $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $magicFunction, $thisObj);
                    }

                    $returnTypeNode = self::resolveConditionalReturnType($returnTypeNode, $magicArgs, $boundTemplates, $registry);

                    $err = $registry->validate($value, $returnTypeNode, $magicFunction . '(): Return value');
                    if ($err !== null) {
                        return $err;
                    }

                    if (\is_callable($value) && $returnTypeNode instanceof CallableTypeNode) {
                        return CallableWrapper::wrapTypeNode($returnTypeNode, $value, $magicFunction . '(): Return value', $registry);
                    }
                }
            }
        }

        $contract = ContractParser::parse($effectiveFunction);
        $returnTypeNode = $contract['return'] ?? null;

        if ($returnTypeNode === null) {
            return $value;
        }

        $err = SpecialTypeResolver::checkThisIdentity($returnTypeNode, $value, $thisObj, $effectiveFunction);
        if ($err !== null) {
            return $err;
        }

        $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $effectiveFunction, $thisObj);

        $aliases = $contract['aliases'] ?? [];
        if ($returnTypeNode instanceof IdentifierTypeNode && isset($aliases[$returnTypeNode->name])) {
            $returnTypeNode = $aliases[$returnTypeNode->name];
        }

        $boundTemplates = TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $contract['templates']);
        $declaredTemplates = $contract['templates'];

        if (\count($boundTemplates) > 0 || \count($declaredTemplates) > 0) {
            $returnTypeNode = TemplateSubstitutor::substitute($returnTypeNode, $boundTemplates, $declaredTemplates);
            $returnTypeNode = SpecialTypeResolver::resolve($returnTypeNode, $effectiveFunction, $thisObj);
        }

        $returnTypeNode = self::resolveConditionalReturnType($returnTypeNode, $vars, $boundTemplates, $registry);

        $err = $registry->validate($value, $returnTypeNode, $effectiveFunction . '(): Return value');
        if ($err !== null) {
            return $err;
        }

        if (\is_callable($value) && $returnTypeNode instanceof CallableTypeNode) {
            return CallableWrapper::wrapTypeNode($returnTypeNode, $value, $effectiveFunction . '(): Return value', $registry);
        }

        if ($value instanceof \Traversable) {
            $baseName = '';
            if ($returnTypeNode instanceof IdentifierTypeNode) {
                $baseName = strtolower(ltrim($returnTypeNode->name, '\\'));
            } elseif ($returnTypeNode instanceof GenericTypeNode) {
                $baseName = strtolower(ltrim($returnTypeNode->type->name, '\\'));
            }

            $standardIterables = ['iterable', 'traversable', 'iterator', 'generator', 'iteratoraggregate', 'array'];
            if ($baseName === '' || \in_array($baseName, $standardIterables, true)) {
                return $wrapIterableCallback($effectiveFunction, 'return', $value);
            }
        }

        return $value;
    }

    /**
     * Recursively resolves multi-branch nested conditional return types.
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    private static function resolveConditionalReturnType(
        TypeNode $returnTypeNode,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry
    ): TypeNode {
        if ($returnTypeNode instanceof ConditionalTypeForParameterNode) {
            $paramName = ltrim($returnTypeNode->parameterName, '$');
            $paramValue = $vars[$paramName] ?? null;

            $targetErr = $registry->validate($paramValue, $returnTypeNode->targetType, 'condition');
            $isTargetMatch = ($targetErr === null);

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $selectedBranch = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            return self::resolveConditionalReturnType($selectedBranch, $vars, $boundTemplates, $registry);
        }

        if ($returnTypeNode instanceof ConditionalTypeNode) {
            /** @var TypeNode|IdentifierTypeNode $subjectTypeNode */
            $subjectTypeNode = $returnTypeNode->subjectType;

            if ($subjectTypeNode instanceof IdentifierTypeNode && isset($boundTemplates[$subjectTypeNode->name])) {
                /** @var TypeNode $subjectTypeNode */
                $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
            }

            $subStr = (string) $subjectTypeNode;
            /** @var TypeNode $targetTypeNode */
            $targetTypeNode = $returnTypeNode->targetType;
            $targetStr = (string) $targetTypeNode;

            $isTargetMatch = ($subStr === $targetStr);
            if (! $isTargetMatch) {
                $isTargetMatch = ClassNameValidator::isValid($subStr) && ClassNameValidator::isValid($targetStr) &&
                    (class_exists($subStr) || interface_exists($subStr)) &&
                    (class_exists($targetStr) || interface_exists($targetStr)) &&
                    is_a($subStr, $targetStr, true);
            }

            if ($returnTypeNode->negated) {
                $isTargetMatch = ! $isTargetMatch;
            }

            $selectedBranch = $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;

            return self::resolveConditionalReturnType($selectedBranch, $vars, $boundTemplates, $registry);
        }

        return $returnTypeNode;
    }
}