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
use Traversable;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates parameter-based and template-based conditional types for parameters and returns.
 */
final class ConditionalChecker
{
    /**
     * @var \WeakMap<TypeNode, bool>|null
     */
    private static ?\WeakMap $containsConditionalCache = null;

    /**
     * Checks whether a TypeNode AST contains any conditional types.
     */
    public static function containsConditional(TypeNode $node): bool
    {
        if ($node instanceof IdentifierTypeNode) {
            return false;
        }

        if ($node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return true;
        }

        self::$containsConditionalCache ??= new \WeakMap();
        if (isset(self::$containsConditionalCache[$node])) {
            return self::$containsConditionalCache[$node];
        }

        return self::$containsConditionalCache[$node] = self::computeContainsConditional($node);
    }

    private static function computeContainsConditional(TypeNode $node): bool
    {
        while ($node instanceof NullableTypeNode || $node instanceof ArrayTypeNode) {
            $node = $node->type;
        }

        if ($node instanceof ConditionalTypeNode || $node instanceof ConditionalTypeForParameterNode) {
            return true;
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
            $genericTypes = [];
            foreach ($typeNode->genericTypes as $gt) {
                $genericTypes[] = self::resolve($gt, $vars, $boundTemplates, $registry, $function);
            }

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $typeNode->type,
                $genericTypes,
                $typeNode->variances
            );
        }

        if ($typeNode instanceof UnionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $t) {
                $types[] = self::resolve($t, $vars, $boundTemplates, $registry, $function);
            }

            return new UnionTypeNode($types);
        }

        if ($typeNode instanceof IntersectionTypeNode) {
            $types = [];
            foreach ($typeNode->types as $t) {
                $types[] = self::resolve($t, $vars, $boundTemplates, $registry, $function);
            }

            return new IntersectionTypeNode($types);
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
            $parameters = [];
            foreach ($typeNode->parameters as $param) {
                $parameters[] = new CallableTypeParameterNode(
                    self::resolve($param->type, $vars, $boundTemplates, $registry, $function),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                );
            }

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

        $targetType = $node->targetType;
        $isTargetMatch = null;

        if ($targetType instanceof IdentifierTypeNode) {
            $tName = $targetType->name;
            $tLower = strtolower($tName);
            $isTargetMatch = match ($tLower) {
                'null' => $paramValue === null,
                'int', 'integer' => \is_int($paramValue),
                'string' => \is_string($paramValue),
                'bool', 'boolean' => \is_bool($paramValue),
                'true' => $paramValue === true,
                'false' => $paramValue === false,
                'float', 'double' => \is_float($paramValue) || \is_int($paramValue),
                'array' => \is_array($paramValue),
                'list' => \is_array($paramValue) && ($paramValue === [] || array_is_list($paramValue)),
                'object' => \is_object($paramValue),
                'iterable' => is_iterable($paramValue),
                'scalar' => \is_scalar($paramValue),
                'array-key' => \is_int($paramValue) || \is_string($paramValue),
                'mixed' => true,
                default => (! SpecialTypeResolver::isBuiltInTypeKeyword($tName) && ClassNameValidator::isValid($tName) && (class_exists($tName, false) || interface_exists($tName, false)))
                    ? (\is_object($paramValue) && ($paramValue instanceof $tName))
                    : null,
            };
        } elseif ($targetType instanceof GenericTypeNode) {
            $gBase = strtolower((string) $targetType->type);
            if ($gBase === 'list' || $gBase === 'non-empty-list') {
                if (! \is_array($paramValue)) {
                    $isTargetMatch = false;
                }
            } elseif ($gBase === 'array' || $gBase === 'non-empty-array' || $gBase === 'iterable' || $gBase === 'traversable' || $gBase === 'iterator' || $gBase === 'generator') {
                if (! \is_array($paramValue) && ! ($paramValue instanceof Traversable)) {
                    $isTargetMatch = false;
                } elseif ($gBase === 'array' && \is_array($paramValue)) {
                    $genericCount = \count($targetType->genericTypes);
                    if ($genericCount === 0) {
                        $isTargetMatch = true;
                    } elseif ($genericCount === 1) {
                        $gt0 = $targetType->genericTypes[0];
                        if ($gt0 instanceof IdentifierTypeNode && \in_array(strtolower($gt0->name), ['mixed', 't', 'tvalue', 'v', 'value', 'telement'], true)) {
                            $isTargetMatch = true;
                        }
                    } else {
                        $gt0 = $targetType->genericTypes[0];
                        $gt1 = $targetType->genericTypes[1];
                        if (
                            $gt0 instanceof IdentifierTypeNode
                            && $gt1 instanceof IdentifierTypeNode
                            && \in_array(strtolower($gt0->name), ['array-key', 'mixed', 'tkey', 'key', 'k'], true)
                            && \in_array(strtolower($gt1->name), ['mixed', 'tvalue', 'v', 'value', 't'], true)
                        ) {
                            $isTargetMatch = true;
                        }
                    }
                }
            } elseif ($gBase === 'int' || $gBase === 'integer' || $gBase === 'int-mask' || $gBase === 'int-mask-of') {
                if (! \is_int($paramValue)) {
                    $isTargetMatch = false;
                }
            } elseif ($gBase === 'class-string') {
                if (! \is_string($paramValue)) {
                    $isTargetMatch = false;
                }
            } elseif ($gBase !== 'key-of' && $gBase !== 'value-of') {
                $gClass = (string) $targetType->type;
                if (! \is_object($paramValue) || ! ($paramValue instanceof $gClass)) {
                    $isTargetMatch = false;
                }
            }
        }

        if ($isTargetMatch === null) {
            $targetErr = $registry->validate($paramValue, $targetType, 'condition');
            $isTargetMatch = ($targetErr === null);
        }

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
        if ($subjectTypeNode instanceof IdentifierTypeNode) {
            if (isset($boundTemplates[$subjectTypeNode->name])) {
                $subjectTypeNode = $boundTemplates[$subjectTypeNode->name];
            } elseif ($function !== '') {
                $contract = DocblockParser::parse($function);
                $allTemplates = $contract['allTemplates'] ?? [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
                if (isset($allTemplates[$subjectTypeNode->name])) {
                    $templateTag = $allTemplates[$subjectTypeNode->name];
                    $subjectTypeNode = $templateTag->default ?? $templateTag->bound ?? $subjectTypeNode;
                }
            }
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
