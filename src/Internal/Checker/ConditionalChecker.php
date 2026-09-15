<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use Throwable;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates parameter-based and template-based conditional types for parameters and returns.
 */
final class ConditionalChecker
{
    /**
     * Recursively resolves multi-branch nested conditional types ($param is Target ? A : B or T is Target ? A : B).
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    public static function resolve(
        TypeNode $typeNode,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry,
        string $function = ''
    ): TypeNode {
        if ($typeNode instanceof ConditionalTypeForParameterNode) {
            return self::resolveParameterConditional($typeNode, $vars, $boundTemplates, $registry, $function);
        }

        if ($typeNode instanceof ConditionalTypeNode) {
            return self::resolveTemplateConditional($typeNode, $vars, $boundTemplates, $registry, $function);
        }

        return $typeNode;
    }

    /**
     * Resolves parameter-based conditional types ($param is Target ? If : Else).
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    public static function resolveParameterConditional(
        ConditionalTypeForParameterNode $node,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry,
        string $function = ''
    ): TypeNode {
        $paramName = ltrim($node->parameterName, '$');
        $paramValue = null;

        if (isset($vars[$paramName]) || \array_key_exists($paramName, $vars)) {
            $paramValue = $vars[$paramName];
        } elseif (\count($vars) > 0 && $function !== '' && str_contains($function, '::')) {
            $paramValue = self::resolveRenamedParamValue($function, $paramName, $vars);
        }

        $targetErr = $registry->validate($paramValue, $node->targetType, 'condition');
        $isTargetMatch = ($targetErr === null);
        if ($node->negated) {
            $isTargetMatch = ! $isTargetMatch;
        }

        $selectedBranch = $isTargetMatch ? $node->if : $node->else;

        return self::resolve($selectedBranch, $vars, $boundTemplates, $registry, $function);
    }

    /**
     * Resolves template-based conditional types (T is Target ? If : Else).
     *
     * @param array<int|string, mixed> $vars
     * @param array<string, TypeNode> $boundTemplates
     */
    public static function resolveTemplateConditional(
        ConditionalTypeNode $node,
        array $vars,
        array $boundTemplates,
        TypeValidatorRegistry $registry,
        string $function = ''
    ): TypeNode {
        $subjectTypeNode = $node->subjectType;
        if ($subjectTypeNode instanceof IdentifierTypeNode && isset($boundTemplates[$subjectTypeNode->name])) {
            $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
        }

        $isTargetMatch = TemplateManager::checkVariance($subjectTypeNode, $node->targetType, GenericTypeNode::VARIANCE_COVARIANT);
        if ($node->negated) {
            $isTargetMatch = ! $isTargetMatch;
        }

        $selectedBranch = $isTargetMatch ? $node->if : $node->else;

        return self::resolve($selectedBranch, $vars, $boundTemplates, $registry, $function);
    }

    /**
     * Disambiguates parameter value by positional index in method hierarchy when renamed in child class.
     *
     * @param array<int|string, mixed> $vars
     */
    private static function resolveRenamedParamValue(string $function, string $paramName, array $vars): mixed
    {
        [$className, $methodName] = explode('::', $function, 2);
        if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className) && ! enum_exists($className)) {
            return null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new ReflectionClass($className);
            if (! $refClass->hasMethod($methodName)) {
                return null;
            }

            $refMethod = $refClass->getMethod($methodName);
            $hierarchy = HierarchyResolver::getMethodHierarchy($refMethod);

            $targetIndex = null;
            foreach ($hierarchy as $hierMethod) {
                foreach ($hierMethod->getParameters() as $idx => $p) {
                    if ($p->getName() === $paramName) {
                        $targetIndex = $idx;

                        break 2;
                    }
                }
            }

            if ($targetIndex !== null) {
                $values = array_values($vars);
                if (isset($values[$targetIndex]) || \array_key_exists($targetIndex, $values)) {
                    return $values[$targetIndex];
                }
            }
        } catch (Throwable $e) {
            // Silently ignore reflection errors
        }

        return null;
    }
}
