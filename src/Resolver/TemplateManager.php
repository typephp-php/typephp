<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Contract\ContractParser;
use TypePHP\Contract\DocblockExtractor;
use TypePHP\Contract\FileFilter;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\StubManager;
use WeakMap;

/**
 * Manages generic template bindings for object instances (via WeakMap) and static/method call stack frames.
 *
 * @internal
 */
final class TemplateManager
{
    /**
     * WeakMap storing generic template bindings per object instance.
     *
     * @var WeakMap<object, array<string, TypeNode>>|null
     */
    private static ?WeakMap $instanceTemplateBindings = null;

    /**
     * Call stack frames storing template bindings per function or method call.
     *
     * @var array<string, list<array<string, TypeNode>>>
     */
    private static array $callStackBindings = [];

    /**
     * Cache for resolved hierarchy templates and variances per class name.
     *
     * @var array<string, array{0: array<string, TemplateTagValueNode>, 1: array<string, string>}>
     */
    private static array $classHierarchyTemplatesCache = [];

    /**
     * Cache for inherited template bindings per class name.
     *
     * @var array<string, array<string, TypeNode>>
     */
    private static array $classInheritedBindingsCache = [];

    /**
     * Temporary storage for an original object instance being cloned.
     */
    public static ?object $pendingCloneSource = null;

    /**
     * O(1) direct hash-table matrix for scalar subtype relationships.
     *
     * @var array<string, array<string, bool>>
     */
    private const SCALAR_SUBTYPES = [
        'int' => [
            'int' => true,
            'integer' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
        ],
        'integer' => [
            'int' => true,
            'integer' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
        ],
        'string' => [
            'string' => true,
            'non-empty-string' => true,
            'numeric-string' => true,
            'lowercase-string' => true,
            'non-empty-lowercase-string' => true,
            'uppercase-string' => true,
            'non-empty-uppercase-string' => true,
            'class-string' => true,
            'interface-string' => true,
            'trait-string' => true,
            'enum-string' => true,
            'callable-string' => true,
            'literal-string' => true,
            'truthy-string' => true,
            'non-falsy-string' => true,
        ],
        'float' => [
            'float' => true,
            'double' => true,
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
        ],
        'double' => [
            'float' => true,
            'double' => true,
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
        ],
        'bool' => [
            'bool' => true,
            'boolean' => true,
            'true' => true,
            'false' => true,
        ],
        'boolean' => [
            'bool' => true,
            'boolean' => true,
            'true' => true,
            'false' => true,
        ],
        'array-key' => [
            'array-key' => true,
            'int' => true,
            'integer' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
            'string' => true,
            'non-empty-string' => true,
            'numeric-string' => true,
            'lowercase-string' => true,
            'non-empty-lowercase-string' => true,
            'uppercase-string' => true,
            'non-empty-uppercase-string' => true,
            'class-string' => true,
            'interface-string' => true,
            'trait-string' => true,
            'enum-string' => true,
            'callable-string' => true,
            'literal-string' => true,
            'truthy-string' => true,
            'non-falsy-string' => true,
        ],
        'numeric' => [
            'numeric' => true,
            'number' => true,
            'int' => true,
            'integer' => true,
            'float' => true,
            'double' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
            'numeric-string' => true,
        ],
        'number' => [
            'numeric' => true,
            'number' => true,
            'int' => true,
            'integer' => true,
            'float' => true,
            'double' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
            'numeric-string' => true,
        ],
        'scalar' => [
            'scalar' => true,
            'int' => true,
            'integer' => true,
            'string' => true,
            'float' => true,
            'double' => true,
            'bool' => true,
            'boolean' => true,
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
            'non-empty-string' => true,
            'numeric-string' => true,
            'lowercase-string' => true,
            'non-empty-lowercase-string' => true,
            'uppercase-string' => true,
            'non-empty-uppercase-string' => true,
            'class-string' => true,
            'interface-string' => true,
            'trait-string' => true,
            'enum-string' => true,
            'callable-string' => true,
            'literal-string' => true,
            'truthy-string' => true,
            'non-falsy-string' => true,
            'true' => true,
            'false' => true,
        ],
    ];

    /**
     * O(1) lookup set for valid supertypes of integer ranges and numeric literals.
     *
     * @var array<string, bool>
     */
    private const INT_SUPERTYPES = [
        'int' => true,
        'integer' => true,
        'array-key' => true,
        'numeric' => true,
        'number' => true,
        'scalar' => true,
    ];

    /**
     * O(1) lookup set for valid supertypes of string literals and class-string<T>.
     *
     * @var array<string, bool>
     */
    private const STRING_SUPERTYPES = [
        'string' => true,
        'array-key' => true,
        'scalar' => true,
    ];

    /**
     * Resets all static generic template bindings and call stack frames.
     */
    public static function reset(): void
    {
        self::$instanceTemplateBindings = null;
        self::$callStackBindings = [];
        self::$classHierarchyTemplatesCache = [];
        self::$classInheritedBindingsCache = [];
        self::$pendingCloneSource = null;
    }

    /**
     * Normalizes generic type arguments when a single type argument is supplied
     * for a 2-template collection/map whose first template is a key type (TKey of array-key).
     *
     * @param array<TypeNode> $genericTypes
     * @param array<TemplateTagValueNode> $templateList
     *
     * @return array<TypeNode>
     */
    public static function normalizeGenericArguments(array $genericTypes, array $templateList): array
    {
        $genericTypes = array_values($genericTypes);
        $templateList = array_values($templateList);

        if (\count($genericTypes) === 1 && \count($templateList) === 2) {
            $firstTemplate = $templateList[0];
            $firstBound = $firstTemplate->bound !== null ? strtolower((string) $firstTemplate->bound) : '';
            $firstName = strtolower($firstTemplate->name);

            $isKeyTemplate = $firstBound === 'array-key'
                || \in_array($firstName, ['tkey', 'key', 'k'], true);

            if ($isKeyTemplate) {
                $defaultKeyNode = $firstTemplate->default ?? $firstTemplate->bound ?? new IdentifierTypeNode('array-key');

                return [$defaultKeyNode, $genericTypes[0]];
            }
        }

        return $genericTypes;
    }

    /**
     * Copies bound generic template types from a source object to a cloned target object.
     */
    public static function copyInstanceBindings(object $source, object $target): void
    {
        if (self::$instanceTemplateBindings !== null && isset(self::$instanceTemplateBindings[$source])) {
            $bindings = self::$instanceTemplateBindings[$source];
            self::$instanceTemplateBindings[$target] = $bindings;
        }
    }

    /**
     * Pushes a new empty call frame onto the stack for a function execution.
     */
    public static function pushCallFrame(string $function): void
    {
        self::$callStackBindings[$function][] = [];
    }

    /**
     * Pops the top call frame from the stack upon function completion or exception.
     */
    public static function popCallFrame(string $function): void
    {
        if (self::hasCallFrame($function)) {
            array_pop(self::$callStackBindings[$function]);
        }
    }

    /**
     * Clears and initializes a fresh call frame for a function call.
     *
     * @param array<string, TemplateTagValueNode> $templates
     */
    public static function clearCallBindings(string $function, array $templates): void
    {
        self::pushCallFrame($function);
    }

    /**
     * Retrieves currently bound template types for a function call or object instance.
     *
     * @param array<string, TemplateTagValueNode> $templates
     *
     * @return array<string, TypeNode>
     */
    public static function getBoundTemplates(string $function, ?object $thisObj, array $templates): array
    {
        $bindings = [];

        if ($thisObj !== null) {
            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            if (isset(self::$instanceTemplateBindings[$thisObj])) {
                $bindings = self::$instanceTemplateBindings[$thisObj];
            }
        }

        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);
            if ($topFrame !== false) {
                if ($thisObj !== null) {
                    $contract = ContractParser::parse($function);
                    $methodTemplates = $contract['templates'] ?? [];
                    foreach ($topFrame as $tName => $tNode) {
                        if (isset($methodTemplates[$tName])) {
                            $bindings[$tName] = $tNode;
                        }
                    }
                } else {
                    $bindings = [...$bindings, ...$topFrame];
                }
            }
        }

        return $bindings;
    }

    /**
     * Retrieves all bound template TypeNodes for a specific object instance.
     *
     * @return array<string, TypeNode>
     */
    public static function getBoundTemplatesForInstance(object $instance): array
    {
        if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$instance])) {
            self::resolveInheritedTemplates($instance, \get_class($instance));
        }

        if (self::$instanceTemplateBindings !== null && isset(self::$instanceTemplateBindings[$instance])) {
            return self::$instanceTemplateBindings[$instance];
        }

        return [];
    }

    /**
     * Retrieves all declared template variances ('covariant', 'contravariant', 'invariant') for an object instance.
     *
     * @return array<string, string>
     */
    public static function getTemplateVariances(object $instance): array
    {
        $className = \get_class($instance);

        try {
            $stubDoc = StubManager::getClassDoc($className);
            /** @var class-string<object> $className */
            $ref = new \ReflectionClass($className);
            $classDoc = $stubDoc ?? $ref->getDocComment();

            if ($classDoc !== false && $classDoc !== null) {
                $classPhpDocNode = DocblockExtractor::parseDocString($classDoc);

                return DocblockExtractor::extractTemplateVariances($classPhpDocNode);
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection errors
        }

        return [];
    }

    /**
     * Checks if a template name is bound in the current instance or call stack frame.
     */
    public static function isBound(string $function, ?object $thisObj, string $templateName): bool
    {
        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);
            if ($topFrame !== false && isset($topFrame[$templateName])) {
                return true;
            }
        }

        if ($thisObj !== null) {
            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            return isset(self::$instanceTemplateBindings[$thisObj][$templateName]);
        }

        return false;
    }

    /**
     * Retrieves the bound TypeNode for a template name from instance or call stack context.
     */
    public static function getBoundType(string $function, ?object $thisObj, string $templateName): ?TypeNode
    {
        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);
            if ($topFrame !== false && isset($topFrame[$templateName])) {
                return $topFrame[$templateName];
            }
        }

        if ($thisObj !== null) {
            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            return self::$instanceTemplateBindings[$thisObj][$templateName] ?? null;
        }

        return null;
    }

    /**
     * Binds an inferred TypeNode to a template parameter for an instance or call stack frame.
     */
    public static function bindTemplate(string $function, ?object $thisObj, string $templateName, TypeNode $inferredType): void
    {
        if ($thisObj !== null) {
            self::$instanceTemplateBindings ??= new WeakMap();
            $bindings = self::$instanceTemplateBindings[$thisObj] ?? [];
            $bindings[$templateName] = $inferredType;
            self::$instanceTemplateBindings[$thisObj] = $bindings;
        } else {
            if (! self::hasCallFrame($function)) {
                self::$callStackBindings[$function][] = [];
            }
            $lastIndex = \count(self::$callStackBindings[$function]) - 1;
            self::$callStackBindings[$function][$lastIndex][$templateName] = $inferredType;
        }
    }

    /**
     * Binds generic template types to an object instance or validates variance against an existing binding.
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?ErrorMessage
    {
        $className = $typeNode->type->name;
        if (\in_array(strtolower($className), ['self', 'static', '$this'], true)) {
            $className = \get_class($instance);
        }

        if (! is_a($instance, $className) || ! ClassNameValidator::isValid($className) || (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className))) {
            return null;
        }

        self::resolveInheritedTemplates($instance, $className);

        try {
            /** @var class-string<object> $className */
            $ref = new \ReflectionClass($className);
            [$templates, $classVariances] = self::collectHierarchyTemplatesAndVariances($ref);

            self::$instanceTemplateBindings ??= new WeakMap();
            $templateList = array_values($templates);
            $normalizedTypeArgs = self::normalizeGenericArguments($typeNode->genericTypes, $templateList);

            foreach ($templateList as $index => $templateTag) {
                if (! isset($normalizedTypeArgs[$index])) {
                    continue;
                }

                $err = self::bindSingleTemplateArgument($instance, $className, $normalizedTypeArgs[$index], $typeNode->variances[$index] ?? GenericTypeNode::VARIANCE_INVARIANT, $templateTag, $classVariances, $context, $forceBind);
                if ($err !== null) {
                    return $err;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection or parsing errors
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $ref
     *
     * @return array{0: array<string, TemplateTagValueNode>, 1: array<string, string>}
     */
    private static function collectHierarchyTemplatesAndVariances(\ReflectionClass $ref): array
    {
        $className = $ref->getName();
        if (isset(self::$classHierarchyTemplatesCache[$className])) {
            return self::$classHierarchyTemplatesCache[$className];
        }

        $classHierarchy = HierarchyResolver::getClassHierarchy($ref);
        $templates = [];
        $classVariances = [];

        foreach ($classHierarchy as $hierClass) {
            $hierClassName = $hierClass->getName();
            $stubDoc = StubManager::getClassDoc($hierClassName);
            $classDoc = $stubDoc ?? $hierClass->getDocComment();

            if ($classDoc === false || $classDoc === null) {
                continue;
            }

            $classPhpDocNode = DocblockExtractor::parseDocString($classDoc);
            $hierTemplates = DocblockExtractor::extractTemplates($classPhpDocNode);
            $hierVariances = DocblockExtractor::extractTemplateVariances($classPhpDocNode);

            foreach ($hierTemplates as $tName => $tagNode) {
                if (! isset($templates[$tName])) {
                    if ($tagNode->bound !== null || $tagNode->default !== null) {
                        $tagNode = new TemplateTagValueNode(
                            $tagNode->name,
                            $tagNode->bound !== null ? SpecialTypeResolver::resolve($tagNode->bound, $hierClass) : null,
                            $tagNode->description,
                            $tagNode->default !== null ? SpecialTypeResolver::resolve($tagNode->default, $hierClass) : null
                        );
                    }

                    $templates[$tName] = $tagNode;
                    $classVariances[$tName] = match ($hierVariances[$tName] ?? 'invariant') {
                        'covariant' => GenericTypeNode::VARIANCE_COVARIANT,
                        'contravariant' => GenericTypeNode::VARIANCE_CONTRAVARIANT,
                        default => GenericTypeNode::VARIANCE_INVARIANT,
                    };
                }
            }
        }

        return self::$classHierarchyTemplatesCache[$className] = [$templates, $classVariances];
    }

    /**
     * @param array<string, string> $classVariances
     */
    private static function bindSingleTemplateArgument(
        object $instance,
        string $className,
        TypeNode $expectedTypeNode,
        string $usageVariance,
        TemplateTagValueNode $templateTag,
        array $classVariances,
        string $context,
        bool $forceBind
    ): ?ErrorMessage {
        if ($expectedTypeNode instanceof IdentifierTypeNode) {
            $isBuiltIn = SpecialTypeResolver::isBuiltInTypeKeyword($expectedTypeNode->name);
            $isRealType = class_exists($expectedTypeNode->name) || interface_exists($expectedTypeNode->name) || enum_exists($expectedTypeNode->name) || trait_exists($expectedTypeNode->name);

            if (! $isBuiltIn && ! $isRealType) {
                return null;
            }
        }

        if ($templateTag->bound !== null) {
            $satisfiesBound = self::checkVariance($expectedTypeNode, $templateTag->bound, GenericTypeNode::VARIANCE_COVARIANT);

            if (! $satisfiesBound) {
                return ErrorFactory::createError(
                    ($context !== '' ? $context . ': ' : '') . "Generic type argument {$expectedTypeNode} does not satisfy upper bound {$templateTag->bound} of template {$templateTag->name} in {$className}"
                );
            }
        }

        if (self::$instanceTemplateBindings === null) {
            self::$instanceTemplateBindings = new WeakMap();
        }

        $declaredVariance = $classVariances[$templateTag->name] ?? GenericTypeNode::VARIANCE_INVARIANT;

        // Return values are naturally in a covariant position under Liskov Substitution Principle
        $isReturnContext = str_contains($context, 'Return value');

        $variance = ($usageVariance !== GenericTypeNode::VARIANCE_INVARIANT)
            ? $usageVariance
            : ($isReturnContext ? GenericTypeNode::VARIANCE_COVARIANT : $declaredVariance);

        $templateName = $templateTag->name;
        $existingBindings = self::$instanceTemplateBindings[$instance] ?? [];

        if (isset($existingBindings[$templateName])) {
            $existingTypeNode = $existingBindings[$templateName];
            $valid = self::checkVariance($existingTypeNode, $expectedTypeNode, $variance);

            if (! $valid) {
                $isDefaultOrBound = ($existingTypeNode instanceof IdentifierTypeNode) && (
                    strtolower($existingTypeNode->name) === 'mixed'
                    || strtolower($existingTypeNode->name) === 'array-key'
                    || ($templateTag->bound !== null && (string) $existingTypeNode === (string) $templateTag->bound)
                    || ($templateTag->default !== null && (string) $existingTypeNode === (string) $templateTag->default)
                );

                if (self::checkVariance($expectedTypeNode, $existingTypeNode, GenericTypeNode::VARIANCE_COVARIANT)) {
                    if ($isDefaultOrBound || $isReturnContext) {
                        $bindings = self::$instanceTemplateBindings[$instance] ?? [];
                        $bindings[$templateName] = $expectedTypeNode;
                        self::$instanceTemplateBindings[$instance] = $bindings;

                        return null;
                    }
                }

                if ($isReturnContext) {
                    $isWrapping = ($expectedTypeNode instanceof ArrayTypeNode)
                        && self::checkVariance($expectedTypeNode->type, $existingTypeNode, GenericTypeNode::VARIANCE_COVARIANT);

                    $isUnwrapping = ($existingTypeNode instanceof ArrayTypeNode)
                        && self::checkVariance($expectedTypeNode, $existingTypeNode->type, GenericTypeNode::VARIANCE_COVARIANT);

                    if ($isWrapping || $isUnwrapping) {
                        $bindings = self::$instanceTemplateBindings[$instance] ?? [];
                        $bindings[$templateName] = $expectedTypeNode;
                        self::$instanceTemplateBindings[$instance] = $bindings;

                        return null;
                    }
                }

                return ErrorFactory::createError(
                    $context . " expects {$className}<{$variance} {$expectedTypeNode}>, but {$className}<{$existingTypeNode}> was given"
                );
            }
        }

        if ($forceBind || ! isset($existingBindings[$templateName])) {
            $bindings = self::$instanceTemplateBindings[$instance] ?? [];
            $bindings[$templateName] = $expectedTypeNode;
            self::$instanceTemplateBindings[$instance] = $bindings;
        }

        return null;
    }

    /**
     * Resolves and binds parent class (@extends), interface (@implements), and trait (@use) template mappings.
     * Respects Vendor Isolation by skipping docblocks from excluded vendor ancestor files unless stubbed.
     */
    public static function resolveInheritedTemplates(object $instance, string $targetClassName): void
    {
        $actualClassName = \get_class($instance);

        if (isset(self::$classInheritedBindingsCache[$actualClassName])) {
            if (self::$instanceTemplateBindings === null) {
                self::$instanceTemplateBindings = new WeakMap();
            }

            /** @var array<string, TypeNode> $cachedBindings */
            $cachedBindings = self::$classInheritedBindingsCache[$actualClassName];
            if (\count($cachedBindings) > 0) {
                /** @var array<string, TypeNode> $existing */
                $existing = self::$instanceTemplateBindings[$instance] ?? [];
                self::$instanceTemplateBindings[$instance] = [...$cachedBindings, ...$existing];
            }

            return;
        }

        $resolvedClassBindings = self::computeClassInheritedBindings($actualClassName);
        self::$classInheritedBindingsCache[$actualClassName] = $resolvedClassBindings;

        if (\count($resolvedClassBindings) > 0) {
            if (self::$instanceTemplateBindings === null) {
                self::$instanceTemplateBindings = new WeakMap();
            }
            /** @var array<string, TypeNode> $existing */
            $existing = self::$instanceTemplateBindings[$instance] ?? [];
            self::$instanceTemplateBindings[$instance] = [...$resolvedClassBindings, ...$existing];
        }
    }

    /**
     * @return array<string, TypeNode>
     */
    private static function computeClassInheritedBindings(string $actualClassName): array
    {
        /** @var array<string, TypeNode> $bindings */
        $bindings = [];

        if (! class_exists($actualClassName) && ! interface_exists($actualClassName) && ! trait_exists($actualClassName)) {
            return [];
        }

        try {
            /** @var class-string<object> $actualClassName */
            $ref = new \ReflectionClass($actualClassName);
            $classHierarchy = HierarchyResolver::getClassHierarchy($ref);

            foreach ($classHierarchy as $hierClass) {
                $fileName = $hierClass->getFileName();
                $hierClassName = $hierClass->getName();
                $stubDoc = StubManager::getClassDoc($hierClassName);

                if ($stubDoc === null && $hierClass->getName() !== $actualClassName && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                    continue;
                }

                $docsToInspect = self::collectDocsForClassHierarchyMember($hierClass);

                foreach ($docsToInspect as $rawDoc) {
                    $classPhpDocNode = DocblockExtractor::parseDocString($rawDoc);

                    $declaredTemplateNames = [];
                    foreach ($classPhpDocNode->getTags() as $tag) {
                        if ($tag->value instanceof TemplateTagValueNode) {
                            $declaredTemplateNames[$tag->value->name] = true;
                        }
                    }

                    $inheritedTags = DocblockExtractor::getInheritedTags($classPhpDocNode);

                    foreach ($inheritedTags as $inheritedTag) {
                        $genericTypeNode = $inheritedTag->type;
                        if ($genericTypeNode instanceof GenericTypeNode) {
                            self::collectInheritedGenericTagBindings($genericTypeNode, $hierClass, $declaredTemplateNames, $actualClassName, $bindings);
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection or parsing errors
        }

        return $bindings;
    }

    /**
     * @param array<string, bool> $declaredTemplateNames
     * @param array<string, TypeNode> $bindings
     * @param \ReflectionClass<object> $hierClass
     */
    private static function collectInheritedGenericTagBindings(
        GenericTypeNode $genericTypeNode,
        \ReflectionClass $hierClass,
        array $declaredTemplateNames,
        string $actualClassName,
        array &$bindings
    ): void {
        $parentName = SpecialTypeResolver::resolveFqcn($genericTypeNode->type->name, $hierClass);
        $isHierarchyMember = is_a($actualClassName, $parentName, true) || trait_exists($parentName);

        if (! ClassNameValidator::isValid($parentName) || ! $isHierarchyMember) {
            return;
        }

        if (! class_exists($parentName) && ! interface_exists($parentName) && ! trait_exists($parentName)) {
            return;
        }

        try {
            $stubDoc = StubManager::getClassDoc($parentName);
            /** @var class-string<object> $parentName */
            $parentRef = new \ReflectionClass($parentName);
            $parentDoc = $stubDoc ?? $parentRef->getDocComment();

            if ($parentDoc === false || $parentDoc === null) {
                return;
            }

            $parentPhpDocNode = DocblockExtractor::parseDocString($parentDoc);
            $parentTemplateNames = array_keys(DocblockExtractor::extractTemplates($parentPhpDocNode));
            $parentTemplateNodes = array_values(DocblockExtractor::extractTemplates($parentPhpDocNode));
            $normalizedGenericTypes = self::normalizeGenericArguments($genericTypeNode->genericTypes, $parentTemplateNodes);

            foreach ($parentTemplateNames as $idx => $templateName) {
                if (isset($normalizedGenericTypes[$idx])) {
                    $resolved = self::resolveTypeNodeAst($normalizedGenericTypes[$idx], $hierClass);

                    if ($resolved instanceof IdentifierTypeNode) {
                        $isBuiltIn = SpecialTypeResolver::isBuiltInTypeKeyword($resolved->name);
                        $isRealType = class_exists($resolved->name) || interface_exists($resolved->name) || enum_exists($resolved->name) || trait_exists($resolved->name);

                        if (! $isBuiltIn && ! $isRealType) {
                            continue;
                        }

                        if (isset($declaredTemplateNames[$resolved->name])) {
                            continue;
                        }
                    }

                    $bindings[$templateName] = $resolved;
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection errors
        }
    }

    /**
     * @param \ReflectionClass<object> $hierClass
     *
     * @return array<int, string>
     */
    private static function collectDocsForClassHierarchyMember(\ReflectionClass $hierClass): array
    {
        $docs = [];

        $stubDoc = StubManager::getClassDoc($hierClass->getName());
        $classDoc = $stubDoc ?? $hierClass->getDocComment();
        if ($classDoc !== false && $classDoc !== null) {
            $docs[] = $classDoc;
        }

        foreach (SpecialTypeResolver::getClassTraitUseDocs($hierClass->getName()) as $tDoc) {
            $docs[] = $tDoc;
        }

        return $docs;
    }

    /**
     * Recursively checks if an existing type node satisfies an expected type node under a given variance modifier.
     */
    public static function checkVariance(TypeNode $existing, TypeNode $expected, string $variance): bool
    {
        $existingStr = (string) $existing;
        $expectedStr = (string) $expected;

        if ($existingStr === $expectedStr || $variance === GenericTypeNode::VARIANCE_BIVARIANT || $expectedStr === 'mixed') {
            return true;
        }

        $lowerExpected = strtolower($expectedStr);
        $lowerExisting = strtolower($existingStr);

        if ($lowerExpected === 'object' && ClassNameValidator::isValid($existingStr) && (class_exists($existingStr) || interface_exists($existingStr))) {
            return true;
        }

        if (self::isScalarSubtype($lowerExisting, $lowerExpected)) {
            return true;
        }

        if ($expected instanceof UnionTypeNode) {
            return self::checkExpectedUnionVariance($existing, $expected, $variance);
        }

        if ($existing instanceof UnionTypeNode) {
            return self::checkExistingUnionVariance($existing, $expected, $variance);
        }

        if ($expected instanceof IntersectionTypeNode) {
            return self::checkExpectedIntersectionVariance($existing, $expected, $variance);
        }

        if ($existing instanceof IntersectionTypeNode) {
            return self::checkExistingIntersectionVariance($existing, $expected, $variance);
        }

        if ($existing instanceof GenericTypeNode && $expected instanceof GenericTypeNode) {
            return self::checkNestedGenericVariance($existing, $expected);
        }

        if ($existing instanceof IdentifierTypeNode && $expected instanceof GenericTypeNode) {
            if ($variance === GenericTypeNode::VARIANCE_COVARIANT && self::isSubclass($existing->name, $expected->type->name)) {
                return true;
            }
        }

        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            return self::isSubclass($existingStr, $expectedStr);
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            return self::isSubclass($expectedStr, $existingStr);
        }

        return false;
    }

    /**
     * O(1) scalar subtype verification.
     */
    private static function isScalarSubtype(string $sub, string $super): bool
    {
        if (isset(self::SCALAR_SUBTYPES[$super][$sub])) {
            return true;
        }

        if ((str_starts_with($sub, 'int<') || is_numeric($sub)) && isset(self::INT_SUPERTYPES[$super])) {
            return true;
        }

        if ((str_starts_with($sub, 'class-string<') || ($sub !== '' && ($sub[0] === "'" || $sub[0] === '"'))) && isset(self::STRING_SUPERTYPES[$super])) {
            return true;
        }

        return false;
    }

    private static function checkExpectedUnionVariance(TypeNode $existing, UnionTypeNode $expected, string $variance): bool
    {
        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            foreach ($expected->types as $unionVariant) {
                if (! self::checkVariance($existing, $unionVariant, $variance)) {
                    return false;
                }
            }

            return true;
        }

        // For Covariant and Invariant union matching: if existing satisfies any member in expected union
        foreach ($expected->types as $unionVariant) {
            if (self::checkVariance($existing, $unionVariant, GenericTypeNode::VARIANCE_COVARIANT)) {
                return true;
            }
        }

        return false;
    }

    private static function checkExistingUnionVariance(UnionTypeNode $existing, TypeNode $expected, string $variance): bool
    {
        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            foreach ($existing->types as $existingVariant) {
                if (! self::checkVariance($existingVariant, $expected, $variance)) {
                    return false;
                }
            }

            return true;
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            foreach ($existing->types as $existingVariant) {
                if (self::checkVariance($existingVariant, $expected, $variance)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    private static function checkExpectedIntersectionVariance(TypeNode $existing, IntersectionTypeNode $expected, string $variance): bool
    {
        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            foreach ($expected->types as $intersectionMember) {
                if (! self::checkVariance($existing, $intersectionMember, $variance)) {
                    return false;
                }
            }

            return true;
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            foreach ($expected->types as $intersectionMember) {
                if (self::checkVariance($existing, $intersectionMember, $variance)) {
                    return true;
                }
            }

            return true;
        }

        return false;
    }

    private static function checkExistingIntersectionVariance(IntersectionTypeNode $existing, TypeNode $expected, string $variance): bool
    {
        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            foreach ($existing->types as $existingMember) {
                if (self::checkVariance($existingMember, $expected, $variance)) {
                    return true;
                }
            }

            return true;
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            foreach ($existing->types as $existingMember) {
                if (! self::checkVariance($existingMember, $expected, $variance)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function checkNestedGenericVariance(GenericTypeNode $existing, GenericTypeNode $expected): bool
    {
        if (! is_a($existing->type->name, $expected->type->name, true)) {
            return false;
        }

        foreach ($expected->genericTypes as $idx => $expectedInner) {
            $existingInner = $existing->genericTypes[$idx] ?? new IdentifierTypeNode('mixed');
            $innerVariance = $expected->variances[$idx] ?? GenericTypeNode::VARIANCE_INVARIANT;

            if (! self::checkVariance($existingInner, $expectedInner, $innerVariance)) {
                return false;
            }
        }

        return true;
    }

    private static function isSubclass(string $sub, string $super): bool
    {
        $baseSub = ($pos = strpos($sub, '<')) !== false ? substr($sub, 0, $pos) : $sub;
        $baseSuper = ($pos = strpos($super, '<')) !== false ? substr($super, 0, $pos) : $super;

        $baseSub = ltrim(trim($baseSub), '\\');
        $baseSuper = ltrim(trim($baseSuper), '\\');

        if (
            ClassNameValidator::isValid($baseSub)
            && ClassNameValidator::isValid($baseSuper)
            && (class_exists($baseSub) || interface_exists($baseSub) || trait_exists($baseSub) || enum_exists($baseSub))
            && (class_exists($baseSuper) || interface_exists($baseSuper) || trait_exists($baseSuper) || enum_exists($baseSuper))
        ) {
            return is_a($baseSub, $baseSuper, true);
        }

        return false;
    }

    /**
     * Parses a type string and binds generic templates to an object instance.
     */
    public static function bindInstance(object $instance, string $typeString, string $file = ''): object
    {
        try {
            [$typeParser, $lexer] = self::getTypeParserComponents();

            $tokens = new TokenIterator($lexer->tokenize($typeString));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
            }

            if ($typeNode instanceof GenericTypeNode) {
                self::bindInstanceFromNode($instance, $typeNode, '', true);
            }
        } catch (\Throwable $e) {
            // Silently ignore malformed docblock strings
        }

        return $instance;
    }

    /**
     * Infers a TypeNode AST representation from a raw PHP value.
     */
    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        if (\is_int($value)) {
            return new IdentifierTypeNode('int');
        }
        if (\is_string($value)) {
            return new IdentifierTypeNode('string');
        }
        if (\is_float($value)) {
            return new IdentifierTypeNode('float');
        }
        if (\is_bool($value)) {
            return new IdentifierTypeNode('bool');
        }
        if (\is_array($value)) {
            return new IdentifierTypeNode(array_is_list($value) ? 'list' : 'array');
        }

        if (\is_object($value)) {
            $className = \get_class($value);
            if (self::$instanceTemplateBindings !== null && isset(self::$instanceTemplateBindings[$value]) && \count(self::$instanceTemplateBindings[$value]) > 0) {
                $genericTypes = array_values(self::$instanceTemplateBindings[$value]);

                return new GenericTypeNode(new IdentifierTypeNode($className), $genericTypes);
            }

            return new IdentifierTypeNode($className);
        }

        if ($value === null) {
            return new IdentifierTypeNode('null');
        }

        return new IdentifierTypeNode('mixed');
    }

    /**
     * Checks whether a function has at least one active call frame on the stack.
     */
    private static function hasCallFrame(string $function): bool
    {
        return isset(self::$callStackBindings[$function]) && \count(self::$callStackBindings[$function]) > 0;
    }

    /**
     * Resolves FQCNs inside an inherited generic TypeNode AST.
     *
     * @param \ReflectionClass<object> $ref
     */
    private static function resolveTypeNodeAst(TypeNode $n, \ReflectionClass $ref): TypeNode
    {
        if ($n instanceof IdentifierTypeNode) {
            return new IdentifierTypeNode(SpecialTypeResolver::resolveFqcn($n->name, $ref));
        }
        if ($n instanceof GenericTypeNode) {
            $base = new IdentifierTypeNode(SpecialTypeResolver::resolveFqcn($n->type->name, $ref));
            $generics = array_map(fn ($t) => self::resolveTypeNodeAst($t, $ref), $n->genericTypes);

            return new GenericTypeNode($base, $generics, $n->variances);
        }
        if ($n instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::resolveTypeNodeAst($n->type, $ref));
        }
        if ($n instanceof NullableTypeNode) {
            return new NullableTypeNode(self::resolveTypeNodeAst($n->type, $ref));
        }
        if ($n instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(fn ($t) => self::resolveTypeNodeAst($t, $ref), $n->types));
        }
        if ($n instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(fn ($t) => self::resolveTypeNodeAst($t, $ref), $n->types));
        }

        return $n;
    }

    /**
     * Returns shared static instances of PHPStan's TypeParser and Lexer.
     *
     * @return array{TypeParser, Lexer}
     */
    private static function getTypeParserComponents(): array
    {
        /** @var TypeParser|null $typeParser */
        static $typeParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($typeParser === null || $lexer === null) {
            $configParser = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($configParser);
            $constExprParser = new ConstExprParser($configParser);
            $typeParser = new TypeParser($configParser, $constExprParser);
        }

        return [$typeParser, $lexer];
    }
}
