<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;
use Throwable;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates parameter-based and template-based conditional types for parameters and returns.
 */
final class ConditionalChecker
{
    /**
     * Checks whether a TypeNode AST contains any conditional types.
     */
    public static function containsConditional(TypeNode $node): bool
    {
        if ($node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return true;
        }

        if ($node instanceof NullableTypeNode || $node instanceof ArrayTypeNode) {
            return self::containsConditional($node->type);
        }

        if ($node instanceof GenericTypeNode) {
            if (self::containsConditional($node->type)) {
                return true;
            }
            foreach ($node->genericTypes as $gt) {
                if (self::containsConditional($gt)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::containsConditional($t)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ArrayShapeNode) {
            foreach ($node->items as $item) {
                if (self::containsConditional($item->valueType)) {
                    return true;
                }
            }
            if ($node->unsealedType !== null) {
                return self::containsConditional($node->unsealedType->valueType)
                    || ($node->unsealedType->keyType !== null && self::containsConditional($node->unsealedType->keyType));
            }

            return false;
        }

        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                if (self::containsConditional($item->valueType)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof CallableTypeNode) {
            foreach ($node->parameters as $p) {
                if (self::containsConditional($p->type)) {
                    return true;
                }
            }

            return self::containsConditional($node->returnType);
        }

        if ($node instanceof OffsetAccessTypeNode) {
            return self::containsConditional($node->type) || self::containsConditional($node->offset);
        }

        return false;
    }

    /**
     * Recursively resolves multi-branch and nested conditional types anywhere in a TypeNode AST.
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

        if (! self::containsConditional($typeNode)) {
            return $typeNode;
        }

        if ($typeNode instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($typeNode->type, $vars, $boundTemplates, $registry, $function));
        }

        if ($typeNode instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($typeNode->type, $vars, $boundTemplates, $registry, $function));
        }

        if ($typeNode instanceof GenericTypeNode) {
            $genericType = self::resolve($typeNode->type, $vars, $boundTemplates, $registry, $function);
            $genericTypes = array_map(
                fn ($t) => self::resolve($t, $vars, $boundTemplates, $registry, $function),
                $typeNode->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $typeNode->type,
                $genericTypes,
                $typeNode->variances
            );
        }

        if ($typeNode instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::resolve($t, $vars, $boundTemplates, $registry, $function),
                $typeNode->types
            ));
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::resolve($t, $vars, $boundTemplates, $registry, $function),
                $typeNode->types
            ));
        }

        if ($typeNode instanceof ArrayShapeNode) {
            $newItems = [];
            foreach ($typeNode->items as $item) {
                $newItems[] = new ArrayShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolve($item->valueType, $vars, $boundTemplates, $registry, $function)
                );
            }

            $newUnsealed = null;
            if ($typeNode->unsealedType !== null) {
                $unsealedKey = $typeNode->unsealedType->keyType !== null
                    ? self::resolve($typeNode->unsealedType->keyType, $vars, $boundTemplates, $registry, $function)
                    : null;
                $unsealedVal = self::resolve($typeNode->unsealedType->valueType, $vars, $boundTemplates, $registry, $function);
                $newUnsealed = new ArrayShapeUnsealedTypeNode($unsealedVal, $unsealedKey);
            }

            if ($typeNode->sealed) {
                return ArrayShapeNode::createSealed($newItems, $typeNode->kind);
            }

            return ArrayShapeNode::createUnsealed($newItems, $newUnsealed, $typeNode->kind);
        }

        if ($typeNode instanceof ObjectShapeNode) {
            $newItems = [];
            foreach ($typeNode->items as $item) {
                $newItems[] = new ObjectShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolve($item->valueType, $vars, $boundTemplates, $registry, $function)
                );
            }

            return new ObjectShapeNode($newItems);
        }

        if ($typeNode instanceof CallableTypeNode) {
            $parameters = array_map(
                fn (CallableTypeParameterNode $param) => new CallableTypeParameterNode(
                    self::resolve($param->type, $vars, $boundTemplates, $registry, $function),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                ),
                $typeNode->parameters
            );

            $returnType = self::resolve($typeNode->returnType, $vars, $boundTemplates, $registry, $function);

            return new CallableTypeNode(
                $typeNode->identifier,
                $parameters,
                $returnType,
                $typeNode->templateTypes
            );
        }

        if ($typeNode instanceof OffsetAccessTypeNode) {
            return new OffsetAccessTypeNode(
                self::resolve($typeNode->type, $vars, $boundTemplates, $registry, $function),
                self::resolve($typeNode->offset, $vars, $boundTemplates, $registry, $function)
            );
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

        if (! $isTargetMatch && $node->targetType instanceof \PHPStan\PhpDocParser\Ast\Type\ConstTypeNode && $function !== '' && \count($vars) > 0) {
            $contract = DocblockParser::parse($function);
            foreach ($contract['types'] as $pName => $pType) {
                $pTemplateName = ($pType instanceof IdentifierTypeNode) ? $pType->name : null;
                $matchesSubject = false;

                if ($pTemplateName !== null && $node->subjectType instanceof IdentifierTypeNode) {
                    $matchesSubject = ($pTemplateName === $node->subjectType->name)
                        || (isset($boundTemplates[$pTemplateName]) && (string) $boundTemplates[$pTemplateName] === (string) $node->subjectType);
                }

                if ($matchesSubject && (isset($vars[$pName]) || \array_key_exists($pName, $vars))) {
                    if ($registry->validate($vars[$pName], $node->targetType, 'condition') === null) {
                        $isTargetMatch = true;

                        break;
                    }
                }
            }
        }

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
