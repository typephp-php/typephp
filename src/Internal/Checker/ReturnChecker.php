<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Resolver\TemplateSubstitutor;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates function and method return contract validations.
 */
final class ReturnChecker
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function checkReturn(string $function, mixed $value, ?object $thisObj, array $vars, TypeValidatorRegistry $registry, callable $wrapIterableCallback): mixed
    {
        if (! (bool) (Config::get()['returns'] ?? true)) {
            return $value;
        }

        $effectiveFunction = $function;
        if ($thisObj !== null && str_contains($function, '::')) {
            [$classOrTrait, $methodName] = explode('::', $function, 2);
            $actualClassName = \get_class($thisObj);
            if ($actualClassName !== $classOrTrait) {
                $effectiveFunction = $actualClassName . '::' . $methodName;
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

        if ($value instanceof \Traversable) {
            $baseName = '';
            if ($returnTypeNode instanceof IdentifierTypeNode) {
                $baseName = strtolower($returnTypeNode->name);
            } elseif ($returnTypeNode instanceof GenericTypeNode) {
                $baseName = strtolower($returnTypeNode->type->name);
            }

            $standardIterables = ['iterable', 'traversable', 'iterator', 'generator', 'iteratoraggregate', 'array'];
            if ($baseName === '' || \in_array($baseName, $standardIterables, true)) {
                return $wrapIterableCallback($effectiveFunction, 'return', $value);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $vars
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

            return $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;
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

            return $isTargetMatch ? $returnTypeNode->if : $returnTypeNode->else;
        }

        return $returnTypeNode;
    }
}
