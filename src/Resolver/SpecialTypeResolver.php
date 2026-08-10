<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PhpParser\Node\Stmt;
use PhpParser\ParserFactory;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeUnsealedTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;

/**
 * @internal Resolves special type identifiers (self, static, parent, FQCNs) against Reflection or file contexts.
 */
final class SpecialTypeResolver
{
    /**
     * In-memory cache of file import maps keyed by filename.
     *
     * @var array<string, array<string, string>>
     */
    private static array $fileUseImports = [];

    /**
     * In-memory cache of file namespaces keyed by filename.
     *
     * @var array<string, string>
     */
    private static array $fileNamespaces = [];

    /**
     * Validates strict object identity ($value === $thisObj) when the return type node specifies $this.
     */
    public static function checkThisIdentity(TypeNode $returnTypeNode, mixed $value, ?object $thisObj, string $function): ?ErrorMessage
    {
        $isThisType = ($returnTypeNode instanceof ThisTypeNode)
            || ($returnTypeNode instanceof IdentifierTypeNode && strtolower($returnTypeNode->name) === '$this');

        if ($thisObj !== null && $isThisType && $value !== $thisObj) {
            return ErrorFactory::createError($function . '(): Return value must be $this instance, ' . TypeFormatter::formatGivenValue($value) . ' returned');
        }

        return null;
    }

    /**
     * Recursively resolves special type identifiers (self, static, parent, FQCNs, ConstFetch class names) in a TypeNode AST using Reflection context.
     *
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod|string $context
     */
    public static function resolve(TypeNode $node, \ReflectionClass|\ReflectionFunction|\ReflectionMethod|string $context, ?object $thisObj = null): TypeNode
    {
        if (\is_string($context)) {
            if (str_contains($context, '::')) {
                [$className, $methodName] = explode('::', $context, 2);
                $ref = new \ReflectionMethod($className, $methodName);
            } else {
                $ref = new \ReflectionFunction($context);
            }
        } else {
            $ref = $context;
        }

        $declaringClass = $ref instanceof \ReflectionMethod ? $ref->getDeclaringClass()->getName() : ($ref instanceof \ReflectionClass ? $ref->getName() : null);

        if ($node instanceof ThisTypeNode) {
            return $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);

            if ($lower === '$this' || $lower === 'static') {
                return $node;
            }

            if ($lower === 'self' && $declaringClass !== null) {
                return new IdentifierTypeNode($declaringClass);
            }

            if ($lower === 'parent' && $declaringClass !== null) {
                $parentClass = get_parent_class($declaringClass);
                if ($parentClass !== false) {
                    return new IdentifierTypeNode($parentClass);
                }
            }

            $fqcn = self::resolveFqcn($node->name, $ref);
            if ($fqcn !== $node->name) {
                return new IdentifierTypeNode($fqcn);
            }
        }

        if ($node instanceof ConstTypeNode) {
            if ($node->constExpr instanceof ConstFetchNode && $node->constExpr->className !== '') {
                $className = $node->constExpr->className;
                $lowerClassName = strtolower($className);

                if ($lowerClassName === 'self' && $declaringClass !== null) {
                    $resolvedClass = $declaringClass;
                } elseif ($lowerClassName === 'parent' && $declaringClass !== null) {
                    $parentClass = get_parent_class($declaringClass);
                    $resolvedClass = $parentClass !== false ? $parentClass : $className;
                } else {
                    $resolvedClass = self::resolveFqcn($className, $ref);
                }

                return new ConstTypeNode(new ConstFetchNode($resolvedClass, $node->constExpr->name));
            }

            return $node;
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolve($node->type, $context, $thisObj);
            $innerTypes = array_map(
                fn ($t) => self::resolve($t, $context, $thisObj),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof ConditionalTypeNode) {
            return new ConditionalTypeNode(
                self::resolve($node->subjectType, $context, $thisObj),
                self::resolve($node->targetType, $context, $thisObj),
                self::resolve($node->if, $context, $thisObj),
                self::resolve($node->else, $context, $thisObj),
                $node->negated
            );
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return new ConditionalTypeForParameterNode(
                $node->parameterName,
                self::resolve($node->targetType, $context, $thisObj),
                self::resolve($node->if, $context, $thisObj),
                self::resolve($node->else, $context, $thisObj),
                $node->negated
            );
        }

        if ($node instanceof OffsetAccessTypeNode) {
            $baseType = self::resolve($node->type, $context, $thisObj);
            $offsetType = self::resolve($node->offset, $context, $thisObj);

            $offsetKey = null;
            if ($offsetType instanceof ConstTypeNode) {
                $expr = $offsetType->constExpr;
                if ($expr instanceof ConstExprStringNode) {
                    $offsetKey = $expr->value;
                } elseif ($expr instanceof ConstExprIntegerNode) {
                    $offsetKey = (int) $expr->value;
                }
            } elseif ($offsetType instanceof IdentifierTypeNode) {
                $offsetKey = $offsetType->name;
            }

            if ($offsetKey !== null) {
                if ($baseType instanceof ArrayShapeNode) {
                    foreach ($baseType->items as $item) {
                        $itemKey = null;
                        if ($item->keyName instanceof ConstExprStringNode) {
                            $itemKey = $item->keyName->value;
                        } elseif ($item->keyName instanceof IdentifierTypeNode) {
                            $itemKey = $item->keyName->name;
                        } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                            $itemKey = (int) $item->keyName->value;
                        }

                        if ((string) $itemKey === (string) $offsetKey) {
                            return $item->valueType;
                        }
                    }
                }

                if ($baseType instanceof ConstTypeNode && $baseType->constExpr instanceof ConstFetchNode) {
                    $constExpr = $baseType->constExpr;
                    $fqcn = $constExpr->className;
                    $constName = $constExpr->name;

                    if ($fqcn !== '' && (class_exists($fqcn) || interface_exists($fqcn))) {
                        try {
                            $refClass = new \ReflectionClass($fqcn);
                            if ($refClass->hasConstant($constName)) {
                                $constValue = $refClass->getConstant($constName);
                                if (\is_array($constValue) && \array_key_exists($offsetKey, $constValue)) {
                                    $val = $constValue[$offsetKey];
                                    if (\is_string($val)) {
                                        return new ConstTypeNode(new ConstExprStringNode($val, ConstExprStringNode::SINGLE_QUOTED));
                                    } elseif (\is_int($val)) {
                                        return new ConstTypeNode(new ConstExprIntegerNode((string) $val));
                                    }
                                }
                            }
                        } catch (\ReflectionException $e) {
                        }
                    }
                }
            }

            return new OffsetAccessTypeNode($baseType, $offsetType);
        }

        if ($node instanceof ArrayShapeNode) {
            $items = array_map(function ($item) use ($context, $thisObj) {
                return new ArrayShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolve($item->valueType, $context, $thisObj)
                );
            }, $node->items);

            if ($node->sealed) {
                return ArrayShapeNode::createSealed($items, $node->kind);
            } else {
                $unsealedType = null;
                if ($node->unsealedType !== null) {
                    $unsealedKey = $node->unsealedType->keyType !== null ? self::resolve($node->unsealedType->keyType, $context, $thisObj) : null;
                    $unsealedValue = self::resolve($node->unsealedType->valueType, $context, $thisObj);
                    $unsealedType = new ArrayShapeUnsealedTypeNode($unsealedValue, $unsealedKey);
                }

                return ArrayShapeNode::createUnsealed($items, $unsealedType, $node->kind);
            }
        }

        if ($node instanceof ObjectShapeNode) {
            $items = array_map(function ($item) use ($context, $thisObj) {
                return new ObjectShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolve($item->valueType, $context, $thisObj)
                );
            }, $node->items);

            return new ObjectShapeNode($items);
        }

        if ($node instanceof CallableTypeNode) {
            $resolvedParameters = array_map(function (CallableTypeParameterNode $param) use ($context, $thisObj) {
                return new CallableTypeParameterNode(
                    self::resolve($param->type, $context, $thisObj),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                );
            }, $node->parameters);

            $resolvedReturnType = self::resolve($node->returnType, $context, $thisObj);

            return new CallableTypeNode(
                $node->identifier,
                $resolvedParameters,
                $resolvedReturnType,
                $node->templateTypes
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolve($node->type, $context, $thisObj));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolve($node->type, $context, $thisObj));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::resolve($t, $context, $thisObj),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::resolve($t, $context, $thisObj),
                $node->types
            ));
        }

        return $node;
    }

    /**
     * Recursively resolves type identifiers in a TypeNode AST using file context (use imports and namespace).
     */
    public static function resolveForFile(TypeNode $node, string $file): TypeNode
    {
        if ($node instanceof ThisTypeNode) {
            return clone $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            $lower = strtolower($node->name);
            if (\in_array($lower, ['self', 'static', 'parent', '$this'], true)) {
                return clone $node;
            }

            $fqcn = self::resolveFqcnForFile($node->name, $file);
            if ($fqcn !== $node->name) {
                return new IdentifierTypeNode($fqcn);
            }
        }

        if ($node instanceof ConstTypeNode) {
            if ($node->constExpr instanceof ConstFetchNode && $node->constExpr->className !== '') {
                $className = $node->constExpr->className;
                $lowerClassName = strtolower($className);

                if ($lowerClassName === 'self' || $lowerClassName === 'parent') {
                    $resolvedClass = $className;
                } else {
                    $resolvedClass = self::resolveFqcnForFile($className, $file);
                }

                return new ConstTypeNode(new ConstFetchNode($resolvedClass, $node->constExpr->name));
            }

            return clone $node;
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::resolveForFile($node->type, $file);
            $innerTypes = array_map(
                fn ($t) => self::resolveForFile($t, $file),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $innerTypes,
                $node->variances
            );
        }

        if ($node instanceof ConditionalTypeNode) {
            return new ConditionalTypeNode(
                self::resolveForFile($node->subjectType, $file),
                self::resolveForFile($node->targetType, $file),
                self::resolveForFile($node->if, $file),
                self::resolveForFile($node->else, $file),
                $node->negated
            );
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return new ConditionalTypeForParameterNode(
                $node->parameterName,
                self::resolveForFile($node->targetType, $file),
                self::resolveForFile($node->if, $file),
                self::resolveForFile($node->else, $file),
                $node->negated
            );
        }

        if ($node instanceof OffsetAccessTypeNode) {
            $baseType = self::resolveForFile($node->type, $file);
            $offsetType = self::resolveForFile($node->offset, $file);

            $offsetKey = null;
            if ($offsetType instanceof ConstTypeNode) {
                $expr = $offsetType->constExpr;
                if ($expr instanceof ConstExprStringNode) {
                    $offsetKey = $expr->value;
                } elseif ($expr instanceof ConstExprIntegerNode) {
                    $offsetKey = (int) $expr->value;
                }
            } elseif ($offsetType instanceof IdentifierTypeNode) {
                $offsetKey = $offsetType->name;
            }

            if ($offsetKey !== null) {
                if ($baseType instanceof ArrayShapeNode) {
                    foreach ($baseType->items as $item) {
                        $itemKey = null;
                        if ($item->keyName instanceof ConstExprStringNode) {
                            $itemKey = $item->keyName->value;
                        } elseif ($item->keyName instanceof IdentifierTypeNode) {
                            $itemKey = $item->keyName->name;
                        } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                            $itemKey = (int) $item->keyName->value;
                        }

                        if ((string) $itemKey === (string) $offsetKey) {
                            return $item->valueType;
                        }
                    }
                }

                if ($baseType instanceof ConstTypeNode && $baseType->constExpr instanceof ConstFetchNode) {
                    $constExpr = $baseType->constExpr;
                    $fqcn = $constExpr->className;
                    $constName = $constExpr->name;

                    if ($fqcn !== '' && (class_exists($fqcn) || interface_exists($fqcn))) {
                        try {
                            $refClass = new \ReflectionClass($fqcn);
                            if ($refClass->hasConstant($constName)) {
                                $constValue = $refClass->getConstant($constName);
                                if (\is_array($constValue) && \array_key_exists($offsetKey, $constValue)) {
                                    $val = $constValue[$offsetKey];
                                    if (\is_string($val)) {
                                        return new ConstTypeNode(new ConstExprStringNode($val, ConstExprStringNode::SINGLE_QUOTED));
                                    } elseif (\is_int($val)) {
                                        return new ConstTypeNode(new ConstExprIntegerNode((string) $val));
                                    }
                                }
                            }
                        } catch (\ReflectionException $e) {
                        }
                    }
                }
            }

            return new OffsetAccessTypeNode($baseType, $offsetType);
        }

        if ($node instanceof ArrayShapeNode) {
            $items = array_map(function ($item) use ($file) {
                return new ArrayShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolveForFile($item->valueType, $file)
                );
            }, $node->items);

            if ($node->sealed) {
                return ArrayShapeNode::createSealed($items, $node->kind);
            } else {
                $unsealedType = null;
                if ($node->unsealedType !== null) {
                    $unsealedKey = $node->unsealedType->keyType !== null ? self::resolveForFile($node->unsealedType->keyType, $file) : null;
                    $unsealedValue = self::resolveForFile($node->unsealedType->valueType, $file);
                    $unsealedType = new ArrayShapeUnsealedTypeNode($unsealedValue, $unsealedKey);
                }

                return ArrayShapeNode::createUnsealed($items, $unsealedType, $node->kind);
            }
        }

        if ($node instanceof ObjectShapeNode) {
            $items = array_map(function ($item) use ($file) {
                return new ObjectShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::resolveForFile($item->valueType, $file)
                );
            }, $node->items);

            return new ObjectShapeNode($items);
        }

        if ($node instanceof CallableTypeNode) {
            $resolvedParameters = array_map(function (CallableTypeParameterNode $param) use ($file) {
                return new CallableTypeParameterNode(
                    self::resolveForFile($param->type, $file),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                );
            }, $node->parameters);

            $resolvedReturnType = self::resolveForFile($node->returnType, $file);

            return new CallableTypeNode(
                $node->identifier,
                $resolvedParameters,
                $resolvedReturnType,
                $node->templateTypes
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolveForFile($node->type, $file));
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolveForFile($node->type, $file));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::resolveForFile($t, $file),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::resolveForFile($t, $file),
                $node->types
            ));
        }

        return clone $node;
    }

    /**
     * Seeds the in-memory cache directly from StreamWrapper to prevent double file reads and re-parsing.
     *
     * @param array<string, string> $imports
     */
    public static function seedFileMetadata(string $fileName, string $namespace, array $imports): void
    {
        if ($fileName !== '') {
            self::$fileNamespaces[$fileName] = $namespace;
            self::$fileUseImports[$fileName] = $imports;
        }
    }

    /**
     * Returns use imports for the declaring file of a Reflection object.
     *
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod $ref
     *
     * @return array<string, string>
     */
    public static function getUseImports(\ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref): array
    {
        $fileName = $ref->getFileName();
        if ($fileName === false || ! file_exists($fileName)) {
            return [];
        }

        return self::getUseImportsFromFile($fileName);
    }

    /**
     * Extracts and caches use imports directly from a file path.
     *
     * @return array<string, string>
     */
    public static function getUseImportsFromFile(string $fileName): array
    {
        if ($fileName === '' || ! file_exists($fileName)) {
            return [];
        }

        if (isset(self::$fileUseImports[$fileName])) {
            return self::$fileUseImports[$fileName];
        }

        $source = file_get_contents($fileName);
        if ($source === false) {
            return self::$fileUseImports[$fileName] = [];
        }

        self::parseFileMetadata($fileName, $source);

        return self::$fileUseImports[$fileName] ?? [];
    }

    /**
     * Extracts and caches the namespace directly from a file path.
     */
    public static function getNamespaceFromFile(string $fileName): string
    {
        if ($fileName === '' || ! file_exists($fileName)) {
            return '';
        }

        if (isset(self::$fileNamespaces[$fileName])) {
            return self::$fileNamespaces[$fileName];
        }

        $source = file_get_contents($fileName);
        if ($source === false) {
            return self::$fileNamespaces[$fileName] = '';
        }

        self::parseFileMetadata($fileName, $source);

        return self::$fileNamespaces[$fileName] ?? '';
    }

    /**
     * Resolves a short class name to its fully qualified class name (FQCN) using Reflection context.
     *
     * @param \ReflectionClass<object>|\ReflectionFunction|\ReflectionMethod $ref
     */
    public static function resolveFqcn(string $name, \ReflectionClass|\ReflectionFunction|\ReflectionMethod $ref): string
    {
        if (self::isBuiltInTypeKeyword($name)) {
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (! ClassNameValidator::isValid($name)) {
            return $name;
        }

        $imports = self::getUseImports($ref);
        if (isset($imports[$name])) {
            return $imports[$name];
        }

        $namespace = match (true) {
            $ref instanceof \ReflectionClass => $ref->getNamespaceName(),
            $ref instanceof \ReflectionMethod => $ref->getDeclaringClass()->getNamespaceName(),
            $ref instanceof \ReflectionFunction => $ref->getNamespaceName(),
        };

        if ($namespace !== '') {
            $namespacedClass = $namespace . '\\' . $name;
            if (class_exists($namespacedClass) || interface_exists($namespacedClass) || trait_exists($namespacedClass) || enum_exists($namespacedClass)) {
                return $namespacedClass;
            }
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return $name;
        }

        return $name;
    }

    /**
     * Resolves a short class name to its FQCN purely based on file context (namespace and use imports).
     */
    public static function resolveFqcnForFile(string $name, string $file): string
    {
        if (self::isBuiltInTypeKeyword($name)) {
            return $name;
        }

        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        if (! ClassNameValidator::isValid($name)) {
            return $name;
        }

        $imports = self::getUseImportsFromFile($file);
        if (isset($imports[$name])) {
            return $imports[$name];
        }

        $namespace = self::getNamespaceFromFile($file);
        if ($namespace !== '') {
            $namespacedClass = $namespace . '\\' . $name;
            if (class_exists($namespacedClass) || interface_exists($namespacedClass) || trait_exists($namespacedClass) || enum_exists($namespacedClass)) {
                return $namespacedClass;
            }
        }

        if (class_exists($name) || interface_exists($name) || trait_exists($name) || enum_exists($name)) {
            return $name;
        }

        return $name;
    }

    /**
     * Checks if a type name is a built-in PHP or PHPDoc type keyword.
     */
    private static function isBuiltInTypeKeyword(string $name): bool
    {
        return \in_array(strtolower($name), [
            'int',
            'integer',
            'string',
            'float',
            'double',
            'bool',
            'boolean',
            'array',
            'list',
            'object',
            'callable',
            'iterable',
            'resource',
            'null',
            'true',
            'false',
            'mixed',
            'scalar',
            'void',
            'self',
            'static',
            'parent',
            '$this',
            'positive-int',
            'negative-int',
            'non-positive-int',
            'non-negative-int',
            'non-zero-int',
            'unsigned-int',
            'positive-float',
            'negative-float',
            'non-positive-float',
            'non-negative-float',
            'non-zero-float',
            'class-string',
            'interface-string',
            'trait-string',
            'enum-string',
            'callable-string',
            'numeric-string',
            'non-empty-string',
            'lowercase-string',
            'non-empty-lowercase-string',
            'uppercase-string',
            'non-empty-uppercase-string',
            'array-key',
            'literal-string',
            'truthy-string',
            'non-empty-array',
            'non-empty-list',
            'number',
            'numeric',
            'truthy',
            'falsy',
            'falsey',
            'min',
            'max',
            '*',
            'never',
            'never-return',
            'never-returns',
            'no-return',
            'open-resource',
            'closed-resource',
        ], true);
    }

    /**
     * Parses the AST of a PHP file once to extract both namespace and use import statements.
     */
    private static function parseFileMetadata(string $fileName, string $source): void
    {
        self::$fileNamespaces[$fileName] = '';
        self::$fileUseImports[$fileName] = [];

        /** @var \PhpParser\Parser|null $parser */
        static $parser = null;
        if ($parser === null) {
            $parser = (new ParserFactory())->createForNewestSupportedVersion();
        }

        try {
            $stmts = $parser->parse($source);
            if ($stmts === null) {
                return;
            }

            $imports = [];
            $namespace = '';

            /** @var array<Stmt> $nodesToScan */
            $nodesToScan = $stmts;
            foreach ($stmts as $stmt) {
                if ($stmt instanceof Stmt\Namespace_) {
                    $namespace = $stmt->name !== null ? $stmt->name->toString() : '';
                    $nodesToScan = $stmt->stmts;

                    break;
                }
            }

            foreach ($nodesToScan as $stmt) {
                if ($stmt instanceof Stmt\Use_) {
                    if ($stmt->type !== Stmt\Use_::TYPE_NORMAL) {
                        continue;
                    }

                    foreach ($stmt->uses as $use) {
                        $fqcn = $use->name->toString();
                        $alias = $use->getAlias()->toString();
                        $imports[$alias] = $fqcn;
                    }
                } elseif ($stmt instanceof Stmt\GroupUse) {
                    $prefix = $stmt->prefix->toString();

                    foreach ($stmt->uses as $use) {
                        if ($use->type !== Stmt\Use_::TYPE_NORMAL && $use->type !== Stmt\Use_::TYPE_UNKNOWN && $stmt->type !== Stmt\Use_::TYPE_NORMAL) {
                            continue;
                        }

                        $fqcn = $prefix . '\\' . $use->name->toString();
                        $alias = $use->getAlias()->toString();
                        $imports[$alias] = $fqcn;
                    }
                }
            }

            self::$fileNamespaces[$fileName] = $namespace;
            self::$fileUseImports[$fileName] = $imports;
        } catch (\Throwable $e) {
            // Silently fall back to empty metadata if parsing fails
        }
    }
}
