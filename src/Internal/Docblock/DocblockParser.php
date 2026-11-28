<?php

declare(strict_types=1);

namespace TypePHP\Internal\Docblock;

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
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
use TypePHP\Internal\Checker\ConditionalChecker;
use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\FileFilter;
use TypePHP\Internal\Util\StubManager;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @phpstan-type FunctionContract array{
 *     types: array<string, TypeNode>,
 *     constrainedTypes?: array<string, TypeNode>,
 *     paramConditionals?: array<string, true>,
 *     paramOuts: array<string, TypeNode>,
 *     selfOut: ?TypeNode,
 *     templates: array<string, TemplateTagValueNode>,
 *     classTemplates: array<string, TemplateTagValueNode>,
 *     allTemplates?: array<string, TemplateTagValueNode>,
 *     return: ?TypeNode,
 *     aliases: array<string, TypeNode>,
 *     sensitiveParams: array<string, bool>,
 *     hasParamContract: bool,
 *     hasParamOutContract: bool,
 *     hasSelfOutContract: bool,
 *     hasReturnContract: bool,
 *     paramsUseGenerics: bool,
 *     paramsAreDynamic: bool,
 *     returnUsesGenerics: bool,
 *     returnUsesMethodTemplates: bool,
 *     returnIsThis: bool,
 *     returnIsDynamic: bool,
 *     allParamsUnconstrained: bool,
 *     returnUnconstrained: bool,
 *     returnHasConditional?: bool,
 *     returnAllowsNull?: bool,
 *     paramsAreSimple?: bool,
 *     hasCrossReferencedBounds?: bool,
 *     paramsNeedPreInference?: bool,
 *     isSimple: bool
 * }
 *
 * @internal Main orchestrator parsing and caching PHPDoc contracts (@param, @param-out, @return, @template, @phpstan-type, @var, stubs).
 */
final class DocblockParser
{
    /**
     * Cache for resolved contract metadata.
     *
     * @var array<string, FunctionContract>
     */
    private static array $cache = [];

    /**
     * 2D Cache for resolved class property types: [$className][$propertyName] => ?TypeNode.
     *
     * @var array<string, array<string, ?TypeNode>>
     */
    private static array $propertyCache = [];

    /**
     * 2D Cache for resolved magic method contracts: [$className][$methodName] => ?array{...}.
     *
     * @var array<string, array<string, ?array{return: ?TypeNode, parameters: array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>, aliases: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>}>>
     */
    private static array $magicMethodCache = [];

    /**
     * In-memory cache for class-level templates and aliases per class name.
     *
     * @var array<string, array{templates: array<string, TemplateTagValueNode>, aliases: array<string, TypeNode>}>
     */
    private static array $classLevelDocCache = [];

    /**
     * Fast O(1) lookup matrix for scalar refinements compatible with PHP native built-in types.
     *
     * @var array<string, array<string, bool>>
     */
    private const BUILTIN_REFINEMENTS = [
        'int' => [
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
        ],
        'integer' => [
            'positive-int' => true,
            'negative-int' => true,
            'non-positive-int' => true,
            'non-negative-int' => true,
            'non-zero-int' => true,
            'unsigned-int' => true,
        ],
        'string' => [
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
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
            'double' => true,
        ],
        'double' => [
            'positive-float' => true,
            'negative-float' => true,
            'non-positive-float' => true,
            'non-negative-float' => true,
            'non-zero-float' => true,
            'double' => true,
        ],
        'bool' => [
            'true' => true,
            'false' => true,
            'boolean' => true,
        ],
        'boolean' => [
            'true' => true,
            'false' => true,
            'boolean' => true,
        ],
    ];

    /**
     * 2D Cache for resolved magic property contracts: [$className][$propertyName] => ?array.
     *
     * @var array<string, array<string, ?array{readable: bool, writable: bool, readType: ?TypeNode, writeType: ?TypeNode}>>
     */
    private static array $magicPropertyContractCache = [];

    /**
     * Resets the contract, property, and class-level docblock caches.
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$propertyCache = [];
        self::$magicMethodCache = [];
        self::$classLevelDocCache = [];
        self::$magicPropertyContractCache = [];
        InlineChecker::reset();
        DocblockExtractor::reset();
        FileFilter::reset();
        TypeValidatorRegistry::reset();
        StubManager::reset();
        SpecialTypeResolver::reset();
    }

    /**
     * Checks recursively whether an AST TypeNode references any declared generic templates.
     *
     * @param array<string, mixed> $templateNames
     */
    public static function typeReferencesTemplate(?TypeNode $node, array $templateNames): bool
    {
        if ($node === null || $templateNames === []) {
            return false;
        }

        while ($node instanceof ArrayTypeNode || $node instanceof NullableTypeNode) {
            $node = $node->type;
        }

        if ($node instanceof IdentifierTypeNode) {
            return isset($templateNames[$node->name]);
        }

        if ($node instanceof GenericTypeNode) {
            if (isset($templateNames[(string) $node->type])) {
                return true;
            }
            foreach ($node->genericTypes as $gt) {
                if (self::typeReferencesTemplate($gt, $templateNames)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ConditionalTypeNode) {
            return self::typeReferencesTemplate($node->subjectType, $templateNames)
                || self::typeReferencesTemplate($node->targetType, $templateNames)
                || self::typeReferencesTemplate($node->if, $templateNames)
                || self::typeReferencesTemplate($node->else, $templateNames);
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return self::typeReferencesTemplate($node->targetType, $templateNames)
                || self::typeReferencesTemplate($node->if, $templateNames)
                || self::typeReferencesTemplate($node->else, $templateNames);
        }

        if ($node instanceof OffsetAccessTypeNode) {
            return self::typeReferencesTemplate($node->type, $templateNames)
                || self::typeReferencesTemplate($node->offset, $templateNames);
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                if (self::typeReferencesTemplate($t, $templateNames)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof ArrayShapeNode) {
            foreach ($node->items as $item) {
                if (self::typeReferencesTemplate($item->valueType, $templateNames)) {
                    return true;
                }
            }

            if ($node->unsealedType !== null) {
                if ($node->unsealedType->keyType !== null && self::typeReferencesTemplate($node->unsealedType->keyType, $templateNames)) {
                    return true;
                }

                return self::typeReferencesTemplate($node->unsealedType->valueType, $templateNames);
            }

            return false;
        }

        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                if (self::typeReferencesTemplate($item->valueType, $templateNames)) {
                    return true;
                }
            }

            return false;
        }

        if ($node instanceof CallableTypeNode) {
            foreach ($node->parameters as $p) {
                if (self::typeReferencesTemplate($p->type, $templateNames)) {
                    return true;
                }
            }

            return self::typeReferencesTemplate($node->returnType, $templateNames);
        }

        return false;
    }

    /**
     * Checks if a TypeNode represents an unconstrained type (mixed or array).
     */
    private static function isUnconstrainedType(TypeNode $typeNode): bool
    {
        if (! ($typeNode instanceof IdentifierTypeNode)) {
            return false;
        }
        $lower = strtolower($typeNode->name);

        return $lower === 'mixed' || $lower === 'array';
    }

    /**
     * @param array<string, TypeNode> $types
     * @param array<string, TypeNode> $aliases
     * @param array<string, TemplateTagValueNode> $methodTemplates
     * @param array<string, TemplateTagValueNode> $allTemplates
     *
     * @return array{
     *     constrainedTypes: array<string, TypeNode>,
     *     paramConditionals: array<string, true>,
     *     allParamsUnconstrained: bool,
     *     returnUnconstrained: bool,
     *     returnHasConditional: bool,
     *     returnAllowsNull: bool,
     *     isSimple: bool,
     *     paramsAreSimple: bool,
     *     hasCrossReferencedBounds: bool,
     *     paramsNeedPreInference: bool
     * }
     */
    private static function computeContractFlags(
        array $types,
        ?TypeNode $returnType,
        bool $paramsUseGenerics,
        bool $returnUsesGenerics,
        array $aliases,
        array $methodTemplates,
        bool $paramsAreDynamic = false,
        array $allTemplates = [],
        bool $returnIsThis = false
    ): array {
        $constrainedTypes = [];
        $paramConditionals = [];
        $paramsNeedPreInference = false;

        foreach ($types as $paramName => $tNode) {
            if (! ($tNode instanceof IdentifierTypeNode) && ConditionalChecker::containsConditional($tNode)) {
                $paramConditionals[$paramName] = true;
                $constrainedTypes[$paramName] = $tNode;
            } elseif (! self::isUnconstrainedType($tNode)) {
                $constrainedTypes[$paramName] = $tNode;
            }

            if (! $paramsNeedPreInference) {
                $inner = $tNode;
                while ($inner instanceof NullableTypeNode) {
                    $inner = $inner->type;
                }
                if (! ($inner instanceof IdentifierTypeNode)) {
                    if ($inner instanceof GenericTypeNode) {
                        $base = strtolower((string) $inner->type);
                        if (! \in_array($base, ['class-string', 'int', 'integer', 'int-mask', 'int-mask-of', 'key-of', 'value-of'], true)) {
                            $paramsNeedPreInference = true;
                        }
                    } else {
                        $paramsNeedPreInference = true;
                    }
                }
            }
        }

        $allParamsUnconstrained = ($constrainedTypes === []);

        $returnUnconstrained = false;
        $returnHasConditional = false;
        if ($returnType instanceof IdentifierTypeNode) {
            $lower = strtolower($returnType->name);
            $returnUnconstrained = ($lower === 'mixed' || $lower === 'array');
        } elseif ($returnType !== null) {
            $returnHasConditional = ConditionalChecker::containsConditional($returnType);
        }

        $returnAllowsNull = $returnType !== null
            && ! $returnIsThis
            && ! $returnHasConditional
            && self::typeContainsNull($returnType);

        $paramsAreSimple = ! $paramsUseGenerics
            && ! $paramsAreDynamic
            && \count($aliases) === 0
            && \count($methodTemplates) === 0;

        $isSimple = $paramsAreSimple && ! $returnUsesGenerics;

        $hasCrossReferencedBounds = false;
        if ($allTemplates !== []) {
            foreach ($allTemplates as $templateNode) {
                if ($templateNode->bound !== null && self::typeReferencesTemplate($templateNode->bound, $allTemplates)) {
                    $hasCrossReferencedBounds = true;

                    break;
                }
            }
        }

        return [
            'constrainedTypes' => $constrainedTypes,
            'paramConditionals' => $paramConditionals,
            'allParamsUnconstrained' => $allParamsUnconstrained,
            'returnUnconstrained' => $returnUnconstrained,
            'returnHasConditional' => $returnHasConditional,
            'returnAllowsNull' => $returnAllowsNull,
            'isSimple' => $isSimple,
            'paramsAreSimple' => $paramsAreSimple,
            'hasCrossReferencedBounds' => $hasCrossReferencedBounds,
            'paramsNeedPreInference' => $paramsNeedPreInference,
        ];
    }

    /**
     * Parses PHPDoc contracts for a function or class method.
     *
     * @return FunctionContract
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
        }

        try {
            if (str_contains($function, '::')) {
                [$className, $methodName] = explode('::', $function, 2);

                if (class_exists($className, false) || class_exists($className) || interface_exists($className) || trait_exists($className) || enum_exists($className)) {
                    /** @var class-string<object> $className */
                    $refClass = new \ReflectionClass($className);
                    if ($refClass->hasMethod($methodName)) {
                        $ref = $refClass->getMethod($methodName);
                        $contract = self::parseMethod($ref, $refClass);
                    } else {
                        $classTemplates = [];
                        $aliases = [];
                        self::parseClassLevelDocs($refClass, $classTemplates, $aliases);
                        $contract = [
                            'types' => [],
                            'constrainedTypes' => [],
                            'paramConditionals' => [],
                            'paramOuts' => [],
                            'selfOut' => null,
                            'templates' => [],
                            'classTemplates' => $classTemplates,
                            'return' => null,
                            'aliases' => $aliases,
                            'sensitiveParams' => [],
                            'hasParamContract' => false,
                            'hasParamOutContract' => false,
                            'hasSelfOutContract' => false,
                            'hasReturnContract' => false,
                            'paramsUseGenerics' => false,
                            'paramsAreDynamic' => false,
                            'returnUsesGenerics' => false,
                            'returnUsesMethodTemplates' => false,
                            'returnIsThis' => false,
                            'returnIsDynamic' => false,
                            'allParamsUnconstrained' => true,
                            'returnUnconstrained' => false,
                            'returnHasConditional' => false,
                            'isSimple' => true,
                        ];
                    }
                } else {
                    $contract = [
                        'types' => [],
                        'constrainedTypes' => [],
                        'paramConditionals' => [],
                        'paramOuts' => [],
                        'selfOut' => null,
                        'templates' => [],
                        'classTemplates' => [],
                        'return' => null,
                        'aliases' => [],
                        'sensitiveParams' => [],
                        'hasParamContract' => false,
                        'hasParamOutContract' => false,
                        'hasSelfOutContract' => false,
                        'hasReturnContract' => false,
                        'paramsUseGenerics' => false,
                        'paramsAreDynamic' => false,
                        'returnUsesGenerics' => false,
                        'returnUsesMethodTemplates' => false,
                        'returnIsThis' => false,
                        'returnIsDynamic' => false,
                        'allParamsUnconstrained' => true,
                        'returnUnconstrained' => false,
                        'returnHasConditional' => false,
                        'isSimple' => true,
                    ];
                }
            } else {
                $ref = new \ReflectionFunction($function);
                $contract = self::parseFunction($ref);
            }
        } catch (\ReflectionException $e) {
            $contract = [
                'types' => [],
                'constrainedTypes' => [],
                'paramConditionals' => [],
                'paramOuts' => [],
                'selfOut' => null,
                'templates' => [],
                'classTemplates' => [],
                'return' => null,
                'aliases' => [],
                'sensitiveParams' => [],
                'hasParamContract' => false,
                'hasParamOutContract' => false,
                'hasSelfOutContract' => false,
                'hasReturnContract' => false,
                'paramsUseGenerics' => false,
                'paramsAreDynamic' => false,
                'returnUsesGenerics' => false,
                'returnUsesMethodTemplates' => false,
                'returnIsThis' => false,
                'returnIsDynamic' => false,
                'allParamsUnconstrained' => true,
                'returnUnconstrained' => false,
                'returnHasConditional' => false,
                'isSimple' => true,
            ];
        }

        return self::$cache[$function] = $contract;
    }

    /**
     * Extracts and returns all class-level templates for a given class.
     *
     * @return array<string, TemplateTagValueNode>
     */
    public static function parseClassTemplates(string $className): array
    {
        if (isset(self::$classLevelDocCache[$className])) {
            return self::$classLevelDocCache[$className]['templates'];
        }

        if (! class_exists($className, false) && ! class_exists($className) && ! interface_exists($className, false) && ! interface_exists($className) && ! trait_exists($className, false) && ! trait_exists($className) && ! enum_exists($className, false) && ! enum_exists($className)) {
            return [];
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);
            $aliases = [];
            $classTemplates = [];
            self::parseClassLevelDocs($refClass, $classTemplates, $aliases);

            return $classTemplates;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Parses and resolves the @var or @property docblock for a given class property with zero-allocation 2D caching.
     */
    public static function parseProperty(string $className, string $propertyName): ?TypeNode
    {
        if (isset(self::$propertyCache[$className][$propertyName]) || \array_key_exists($propertyName, self::$propertyCache[$className] ?? [])) {
            return self::$propertyCache[$className][$propertyName];
        }

        if (! class_exists($className, false) && ! class_exists($className) && ! trait_exists($className, false) && ! trait_exists($className) && ! interface_exists($className, false) && ! interface_exists($className) && ! enum_exists($className, false) && ! enum_exists($className)) {
            return self::$propertyCache[$className][$propertyName] = null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);

            $resolved = self::findDeclaredPropertyDoc($refClass, $propertyName);
            $doc = $resolved['doc'] ?? false;
            $declaringClass = $resolved['declaringClass'] ?? null;
            $typeNode = null;
            $isMagicProperty = false;

            if ($doc === false && Config::isMagicPropertiesEnabled()) {
                $magicResolved = self::findMagicPropertyDoc($refClass, $propertyName);
                if ($magicResolved !== null) {
                    $doc = $magicResolved['doc'];
                    $declaringClass = $magicResolved['declaringClass'];
                    $typeNode = $magicResolved['typeNode'];
                    $isMagicProperty = true;
                }
            }

            if ($doc === false && $typeNode === null && $refClass->hasProperty($propertyName)) {
                $refProp = $refClass->getProperty($propertyName);
                if ($refProp->isPromoted() && $refClass->hasMethod('__construct')) {
                    $ctorTarget = $refClass->getName() . '::__construct';
                    $ctorContract = self::parse($ctorTarget);
                    if (isset($ctorContract['types'][$propertyName])) {
                        return self::$propertyCache[$className][$propertyName] = $ctorContract['types'][$propertyName];
                    }
                }
            }

            if ($doc === false || $declaringClass === null || self::shouldIgnoreDoc($doc)) {
                return self::$propertyCache[$className][$propertyName] = null;
            }

            if (! $isMagicProperty) {
                $phpDocNode = DocblockExtractor::parseDocString($doc);
                $varTags = DocblockExtractor::getVarTags($phpDocNode);

                if (\count($varTags) === 0) {
                    return self::$propertyCache[$className][$propertyName] = null;
                }

                $typeNode = $varTags[0]->type;
            }

            if ($typeNode === null) {
                return self::$propertyCache[$className][$propertyName] = null;
            }

            $aliases = [];
            $classTemplates = [];
            self::parseClassLevelDocs($refClass, $classTemplates, $aliases);
            if ($declaringClass->getName() !== $refClass->getName()) {
                self::parseClassLevelDocs($declaringClass, $classTemplates, $aliases);
            }

            if (! $isMagicProperty) {
                $phpDocNode = DocblockExtractor::parseDocString($doc);
                DocblockExtractor::extractAliases($phpDocNode, $aliases, $declaringClass);
            }

            $typeNode = self::substituteAliases($typeNode, $aliases);
            $resolvedNode = SpecialTypeResolver::resolve($typeNode, $declaringClass);

            return self::$propertyCache[$className][$propertyName] = $resolvedNode;
        } catch (\Throwable $e) {
            return self::$propertyCache[$className][$propertyName] = null;
        }
    }

    /**
     * Resolves access permissions and types for magic @property annotations with zero-allocation 2D caching.
     *
     * @return array{readable: bool, writable: bool, readType: ?TypeNode, writeType: ?TypeNode}|null
     */
    public static function parseMagicPropertyContract(string $className, string $propertyName): ?array
    {
        if (isset(self::$magicPropertyContractCache[$className][$propertyName]) || \array_key_exists($propertyName, self::$magicPropertyContractCache[$className] ?? [])) {
            return self::$magicPropertyContractCache[$className][$propertyName];
        }

        if (! class_exists($className, false) && ! class_exists($className) && ! trait_exists($className) && ! interface_exists($className)) {
            return self::$magicPropertyContractCache[$className][$propertyName] = null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);
            $classHierarchy = HierarchyResolver::getClassHierarchy($refClass);

            foreach ($classHierarchy as $hierClass) {
                $hierClassName = $hierClass->getName();
                $fileName = $hierClass->getFileName();
                $stubDoc = StubManager::getClassDoc($hierClassName);

                if ($stubDoc === null && $hierClass !== $refClass && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                    continue;
                }

                $classDoc = $stubDoc ?? $hierClass->getDocComment();
                if ($classDoc !== false && $classDoc !== null) {
                    $contract = DocblockExtractor::extractMagicPropertyContract($classDoc, $propertyName);
                    if ($contract !== null) {
                        $aliases = [];
                        $classTemplates = [];
                        self::parseClassLevelDocs($hierClass, $classTemplates, $aliases);

                        if ($contract['readType'] !== null) {
                            $contract['readType'] = SpecialTypeResolver::resolve(
                                self::substituteAliases($contract['readType'], $aliases),
                                $hierClass
                            );
                        }

                        if ($contract['writeType'] !== null) {
                            $contract['writeType'] = SpecialTypeResolver::resolve(
                                self::substituteAliases($contract['writeType'], $aliases),
                                $hierClass
                            );
                        }

                        return self::$magicPropertyContractCache[$className][$propertyName] = $contract;
                    }
                }
            }

            return self::$magicPropertyContractCache[$className][$propertyName] = null;
        } catch (\Throwable $e) {
            return self::$magicPropertyContractCache[$className][$propertyName] = null;
        }
    }

    /**
     * @param \ReflectionClass<object> $refClass
     *
     * @return array{doc: string, declaringClass: \ReflectionClass<object>}|null
     */
    public static function findDeclaredPropertyDoc(\ReflectionClass $refClass, string $propertyName): ?array
    {
        $current = $refClass;
        while ($current !== false) {
            $className = $current->getName();
            $stubDoc = StubManager::getPropertyDoc($className, $propertyName);
            if ($stubDoc !== null) {
                return ['doc' => $stubDoc, 'declaringClass' => $current];
            }

            foreach (self::getTraitsRecursive($current) as $trait) {
                if ($trait->hasProperty($propertyName)) {
                    $traitProp = $trait->getProperty($propertyName);
                    $declaringClass = $traitProp->getDeclaringClass();
                    $doc = $traitProp->getDocComment();
                    if ($doc === false && $declaringClass->getName() !== $trait->getName() && $declaringClass->hasProperty($propertyName)) {
                        $doc = $declaringClass->getProperty($propertyName)->getDocComment();
                    }
                    if ($doc !== false) {
                        return ['doc' => $doc, 'declaringClass' => $declaringClass];
                    }
                }
            }

            if ($current->hasProperty($propertyName)) {
                $refProp = $current->getProperty($propertyName);
                $doc = $refProp->getDocComment();
                if ($doc !== false) {
                    return ['doc' => $doc, 'declaringClass' => $refProp->getDeclaringClass()];
                }
            }
            $parent = $current->getParentClass();
            $current = $parent !== false ? $parent : false;
        }

        foreach ($refClass->getInterfaces() as $interface) {
            $interfaceName = $interface->getName();
            $stubDoc = StubManager::getPropertyDoc($interfaceName, $propertyName);
            if ($stubDoc !== null) {
                return ['doc' => $stubDoc, 'declaringClass' => $interface];
            }

            if ($interface->hasProperty($propertyName)) {
                $interfaceProp = $interface->getProperty($propertyName);
                $doc = $interfaceProp->getDocComment();
                if ($doc !== false) {
                    return ['doc' => $doc, 'declaringClass' => $interfaceProp->getDeclaringClass()];
                }
            }
        }

        return null;
    }

    /**
     * Recursively collects all traits used by a class or trait, deepest (innermost) first.
     *
     * @param \ReflectionClass<object> $class
     * @param array<string, bool> $visited
     *
     * @return array<string, \ReflectionClass<object>>
     */
    private static function getTraitsRecursive(\ReflectionClass $class, array &$visited = []): array
    {
        $traits = [];
        foreach ($class->getTraits() as $trait) {
            $name = $trait->getName();
            if (isset($visited[$name])) {
                continue;
            }
            $visited[$name] = true;

            foreach (self::getTraitsRecursive($trait, $visited) as $nestedName => $nestedTrait) {
                if (! isset($traits[$nestedName])) {
                    $traits[$nestedName] = $nestedTrait;
                }
            }

            if (! isset($traits[$name])) {
                $traits[$name] = $trait;
            }
        }

        return $traits;
    }

    /**
     * @param \ReflectionClass<object> $refClass
     *
     * @return array{doc: string, declaringClass: \ReflectionClass<object>, typeNode: TypeNode}|null
     */
    private static function findMagicPropertyDoc(\ReflectionClass $refClass, string $propertyName): ?array
    {
        $className = $refClass->getName();
        $contract = self::parseMagicPropertyContract($className, $propertyName);
        if ($contract !== null) {
            $typeNode = $contract['writeType'] ?? $contract['readType'];
            if ($typeNode !== null) {
                return [
                    'doc' => '',
                    'declaringClass' => $refClass,
                    'typeNode' => $typeNode,
                ];
            }
        }

        return null;
    }

    /**
     * Parses and resolves a class-level @method docblock for __call / __callStatic with zero-allocation 2D caching.
     *
     * @return array{return: ?TypeNode, parameters: array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>, aliases: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>}|null
     */
    public static function parseMagicMethod(string $className, string $methodName): ?array
    {
        if (isset(self::$magicMethodCache[$className][$methodName]) || \array_key_exists($methodName, self::$magicMethodCache[$className] ?? [])) {
            return self::$magicMethodCache[$className][$methodName];
        }

        if (! class_exists($className, false) && ! class_exists($className) && ! trait_exists($className, false) && ! trait_exists($className) && ! interface_exists($className, false) && ! interface_exists($className) && ! enum_exists($className, false) && ! enum_exists($className)) {
            return self::$magicMethodCache[$className][$methodName] = null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);

            $resolved = self::findMagicMethodDoc($refClass, $methodName);
            if ($resolved === null) {
                return self::$magicMethodCache[$className][$methodName] = null;
            }

            $doc = $resolved['doc'];
            $declaringClass = $resolved['declaringClass'];
            $methodTag = $resolved['methodTag'];

            if (self::shouldIgnoreDoc($doc)) {
                return self::$magicMethodCache[$className][$methodName] = null;
            }

            $aliases = [];
            $classTemplates = [];
            self::parseClassLevelDocs($declaringClass, $classTemplates, $aliases);

            $phpDocNode = DocblockExtractor::parseDocString($doc);
            DocblockExtractor::extractAliases($phpDocNode, $aliases, $declaringClass);

            $resolvedReturn = null;
            if ($methodTag->returnType !== null) {
                $subReturn = self::substituteAliases($methodTag->returnType, $aliases);
                $resolvedReturn = SpecialTypeResolver::resolve($subReturn, $declaringClass);
            }

            $resolvedParams = self::resolveMagicParameters($methodTag, $declaringClass, $aliases);

            return self::$magicMethodCache[$className][$methodName] = [
                'return' => $resolvedReturn,
                'parameters' => $resolvedParams,
                'aliases' => $aliases,
                'templates' => $classTemplates,
            ];
        } catch (\Throwable $e) {
            return self::$magicMethodCache[$className][$methodName] = null;
        }
    }

    /**
     * @param \ReflectionClass<object> $refClass
     *
     * @return array{doc: string, declaringClass: \ReflectionClass<object>, methodTag: MethodTagValueNode}|null
     */
    private static function findMagicMethodDoc(\ReflectionClass $refClass, string $methodName): ?array
    {
        $classHierarchy = HierarchyResolver::getClassHierarchy($refClass);

        foreach ($classHierarchy as $hierClass) {
            $className = $hierClass->getName();
            $fileName = $hierClass->getFileName();
            $stubDoc = StubManager::getClassDoc($className);

            if ($stubDoc === null && $hierClass !== $refClass && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $classDoc = $stubDoc ?? $hierClass->getDocComment();
            if ($classDoc !== false && $classDoc !== null) {
                $tag = DocblockExtractor::extractMagicMethodContract($classDoc, $methodName);
                if ($tag !== null) {
                    return [
                        'doc' => $classDoc,
                        'declaringClass' => $hierClass,
                        'methodTag' => $tag,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $declaringClass
     * @param array<string, TypeNode> $aliases
     *
     * @return array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>
     */
    private static function resolveMagicParameters(
        MethodTagValueNode $methodTag,
        \ReflectionClass $declaringClass,
        array $aliases
    ): array {
        $resolvedParams = [];

        foreach ($methodTag->parameters as $p) {
            $pType = $p->type ?? null;
            if ($pType !== null) {
                $subType = self::substituteAliases($pType, $aliases);
                $pType = SpecialTypeResolver::resolve($subType, $declaringClass);
            }

            $pName = self::extractRawParamName($p);
            $pVars = get_object_vars($p);
            $isOptional = (isset($pVars['isOptional']) && (bool) $pVars['isOptional']) || (($p->defaultValue ?? null) !== null);

            $resolvedParams[] = [
                'name' => $pName,
                'type' => $pType,
                'isVariadic' => $p->isVariadic,
                'isOptional' => $isOptional,
            ];
        }

        return $resolvedParams;
    }

    private static function extractRawParamName(object $paramNode): string
    {
        $rawParamName = '';
        $pVars = get_object_vars($paramNode);

        foreach ($pVars as $key => $val) {
            if (\is_string($val) && str_starts_with($val, '$')) {
                $rawParamName = $val;

                break;
            }
        }

        if ($rawParamName === '') {
            foreach (['parameterName', 'name', 'paramName', 'varName'] as $key) {
                if (isset($pVars[$key]) && \is_string($pVars[$key])) {
                    $rawParamName = $pVars[$key];

                    break;
                }
            }
        }

        return ltrim($rawParamName, '$');
    }

    private static function shouldIgnoreDoc(string $doc): bool
    {
        return Config::isRespectIgnoreTagsEnabled() && \TypePHP\Internal\Util\IgnoreManager::hasIgnoreDocTag($doc);
    }

    private static function hasSensitiveAttribute(\ReflectionParameter $param): bool
    {
        foreach ($param->getAttributes() as $attr) {
            $name = ltrim($attr->getName(), '\\');
            if ($name === 'SensitiveParameter' || str_ends_with($name, '\\SensitiveParameter')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Extracts and returns all class-level type aliases for a given class.
     *
     * @return array<string, TypeNode>
     */
    public static function parseClassAliases(string $className): array
    {
        if (isset(self::$classLevelDocCache[$className])) {
            return self::$classLevelDocCache[$className]['aliases'];
        }

        if (! class_exists($className, false) && ! class_exists($className) && ! interface_exists($className, false) && ! interface_exists($className) && ! trait_exists($className, false) && ! trait_exists($className) && ! enum_exists($className, false) && ! enum_exists($className)) {
            return [];
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);
            $aliases = [];
            $classTemplates = [];
            self::parseClassLevelDocs($refClass, $classTemplates, $aliases);

            return $aliases;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Orchestrates parsing for class methods across the inheritance hierarchy.
     *
     * @param \ReflectionClass<object>|null $targetClass
     *
     * @return FunctionContract
     */
    private static function parseMethod(\ReflectionMethod $ref, ?\ReflectionClass $targetClass = null): array
    {
        /** @var array<string, TypeNode> $types */
        $types = [];
        /** @var array<string, TypeNode> $paramOuts */
        $paramOuts = [];
        /** @var array<string, TemplateTagValueNode> $methodTemplates */
        $methodTemplates = [];
        /** @var array<string, TemplateTagValueNode> $classTemplates */
        $classTemplates = [];
        $returnType = null;
        $selfOut = null;
        /** @var array<string, TypeNode> $aliases */
        $aliases = [];
        /** @var array<string, bool> $sensitiveParams */
        $sensitiveParams = [];

        $targetClass ??= (class_exists($ref->class, false) || class_exists($ref->class) || interface_exists($ref->class) || enum_exists($ref->class) || trait_exists($ref->class))
            ? new \ReflectionClass($ref->class)
            : $ref->getDeclaringClass();

        self::parseClassLevelDocs($targetClass, $classTemplates, $aliases);
        if ($targetClass->getName() !== $ref->getDeclaringClass()->getName()) {
            self::parseClassLevelDocs($ref->getDeclaringClass(), $classTemplates, $aliases);
        }

        self::parseMethodHierarchyDocs($ref, $types, $methodTemplates, $returnType, $aliases, $paramOuts, $selfOut, $classTemplates, $sensitiveParams, $targetClass->getName());

        if ($ref->getName() === '__construct') {
            self::applyConstructorPromotionFallback($ref, $types, $classTemplates, $aliases);
        }

        $allTemplates = [...$classTemplates, ...$methodTemplates];
        $paramsUseGenerics = false;
        if (\count($allTemplates) > 0) {
            foreach ($types as $tNode) {
                if (self::typeReferencesTemplate($tNode, $allTemplates)) {
                    $paramsUseGenerics = true;

                    break;
                }
            }
            if (! $paramsUseGenerics) {
                foreach ($paramOuts as $tNode) {
                    if (self::typeReferencesTemplate($tNode, $allTemplates)) {
                        $paramsUseGenerics = true;

                        break;
                    }
                }
            }
            if (! $paramsUseGenerics && $selfOut !== null) {
                if (self::typeReferencesTemplate($selfOut, $allTemplates)) {
                    $paramsUseGenerics = true;
                }
            }
        }

        $paramsAreDynamic = false;
        foreach ($types as $tNode) {
            $tStr = (string) $tNode;
            if (str_contains($tStr, 'static') || str_contains($tStr, '$this')) {
                $paramsAreDynamic = true;

                break;
            }
        }
        if (! $paramsAreDynamic) {
            foreach ($paramOuts as $tNode) {
                $tStr = (string) $tNode;
                if (str_contains($tStr, 'static') || str_contains($tStr, '$this')) {
                    $paramsAreDynamic = true;

                    break;
                }
            }
        }

        $returnUsesMethodTemplates = false;
        if ($returnType !== null && \count($methodTemplates) > 0) {
            $returnUsesMethodTemplates = self::typeReferencesTemplate($returnType, $methodTemplates);
        }

        $returnUsesGenerics = $returnUsesMethodTemplates;
        if (! $returnUsesGenerics && $returnType !== null && \count($classTemplates) > 0) {
            $returnUsesGenerics = self::typeReferencesTemplate($returnType, $classTemplates);
        }

        $returnIsThis = false;
        $returnIsDynamic = false;
        if ($returnType !== null) {
            $retStr = (string) $returnType;
            $returnIsThis = ($returnType instanceof \PHPStan\PhpDocParser\Ast\Type\ThisTypeNode)
                || ($returnType instanceof IdentifierTypeNode && strtolower($returnType->name) === '$this');
            $returnIsDynamic = $returnIsThis || str_contains($retStr, 'static') || str_contains($retStr, '$this');
        }

        $flags = self::computeContractFlags(
            $types,
            $returnType,
            $paramsUseGenerics,
            $returnUsesGenerics,
            $aliases,
            $methodTemplates,
            $paramsAreDynamic,
            $allTemplates,
            $returnIsThis
        );

        return [
            'types' => $types,
            'constrainedTypes' => $flags['constrainedTypes'],
            'paramConditionals' => $flags['paramConditionals'],
            'paramOuts' => $paramOuts,
            'selfOut' => $selfOut,
            'templates' => $methodTemplates,
            'classTemplates' => $classTemplates,
            'allTemplates' => $allTemplates,
            'return' => $returnType,
            'aliases' => $aliases,
            'sensitiveParams' => $sensitiveParams,
            'hasParamContract' => \count($types) > 0,
            'hasParamOutContract' => \count($paramOuts) > 0,
            'hasSelfOutContract' => $selfOut !== null,
            'hasReturnContract' => $returnType !== null,
            'paramsUseGenerics' => $paramsUseGenerics,
            'paramsAreDynamic' => $paramsAreDynamic,
            'returnUsesGenerics' => $returnUsesGenerics,
            'returnUsesMethodTemplates' => $returnUsesMethodTemplates,
            'returnIsThis' => $returnIsThis,
            'returnIsDynamic' => $returnIsDynamic,
            'allParamsUnconstrained' => $flags['allParamsUnconstrained'],
            'returnUnconstrained' => $flags['returnUnconstrained'],
            'returnHasConditional' => $flags['returnHasConditional'],
            'returnAllowsNull' => $flags['returnAllowsNull'],
            'paramsAreSimple' => $flags['paramsAreSimple'],
            'hasCrossReferencedBounds' => $flags['hasCrossReferencedBounds'],
            'paramsNeedPreInference' => $flags['paramsNeedPreInference'],
            'isSimple' => $flags['isSimple'],
        ];
    }

    /**
     * Orchestrates parsing for standalone global or namespaced functions.
     *
     * @return FunctionContract
     */
    private static function parseFunction(\ReflectionFunction $ref): array
    {
        $types = [];
        $paramOuts = [];
        $templates = [];
        $returnType = null;
        $aliases = [];
        $sensitiveParams = [];

        $funcName = $ref->getName();
        $stubDoc = StubManager::getFunctionDoc($funcName);
        $doc = $stubDoc ?? $ref->getDocComment();

        $baseParams = $ref->getParameters();
        $baseParamNames = [];
        $baseParamVariadic = [];
        $baseParamObjects = [];
        foreach ($baseParams as $idx => $p) {
            $baseParamNames[$idx] = $p->getName();
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
            $baseParamObjects[$p->getName()] = $p;
            if (self::hasSensitiveAttribute($p)) {
                $sensitiveParams[$p->getName()] = true;
            }
        }

        if ($doc === false || self::shouldIgnoreDoc($doc)) {
            return [
                'types' => [],
                'constrainedTypes' => [],
                'paramConditionals' => [],
                'paramOuts' => [],
                'selfOut' => null,
                'templates' => [],
                'classTemplates' => [],
                'allTemplates' => $templates,
                'return' => null,
                'aliases' => [],
                'sensitiveParams' => $sensitiveParams,
                'hasParamContract' => false,
                'hasParamOutContract' => false,
                'hasSelfOutContract' => false,
                'hasReturnContract' => false,
                'paramsUseGenerics' => false,
                'paramsAreDynamic' => false,
                'returnUsesGenerics' => false,
                'returnUsesMethodTemplates' => false,
                'returnIsThis' => false,
                'returnIsDynamic' => false,
                'allParamsUnconstrained' => true,
                'returnUnconstrained' => false,
                'returnHasConditional' => false,
                'returnAllowsNull' => false,
                'paramsAreSimple' => true,
                'hasCrossReferencedBounds' => false,
                'paramsNeedPreInference' => false,
                'isSimple' => true,
            ];
        }

        $phpDocNode = DocblockExtractor::parseDocString($doc);

        DocblockExtractor::extractAliases($phpDocNode, $aliases, $ref);

        foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
            if ($tag->bound !== null || $tag->default !== null) {
                $bound = $tag->bound !== null ? SpecialTypeResolver::resolve(self::substituteAliases($tag->bound, $aliases), $ref) : null;
                $default = $tag->default !== null ? SpecialTypeResolver::resolve(self::substituteAliases($tag->default, $aliases), $ref) : null;
                $tag = new TemplateTagValueNode($tag->name, $bound, $tag->description, $default);
            }
            $templates[$name] = $tag;
        }

        $paramIndex = 0;
        foreach (DocblockExtractor::getParamTags($phpDocNode) as $paramName => $paramTag) {
            if ($paramName === '' && isset($baseParamNames[$paramIndex])) {
                $paramName = $baseParamNames[$paramIndex];
            }
            $paramIndex++;

            $type = $paramTag->type;
            $pObj = $baseParamObjects[$paramName] ?? null;

            $type = self::wrapVariadicParameterType($type, $paramTag->isVariadic, $baseParamVariadic[$paramName] ?? false, $pObj);

            if (
                Config::isRespectNativeNullabilityEnabled()
                && $pObj !== null
                && self::parameterExplicitlyAllowsNull($pObj)
                && ! self::typeContainsNull($type)
            ) {
                $type = new NullableTypeNode($type);
            }

            $substitutedType = self::substituteAliases($type, $aliases);
            $resolvedType = SpecialTypeResolver::resolve($substitutedType, $ref);

            if ($resolvedType instanceof IdentifierTypeNode && strtolower($resolvedType->name) === 'mixed') {
                continue;
            }

            $types[$paramName] = $resolvedType;
        }

        foreach (DocblockExtractor::getParamOutTags($phpDocNode) as $paramName => $paramOutTag) {
            $type = $paramOutTag->type;
            $substitutedType = self::substituteAliases($type, $aliases);
            $resolvedType = SpecialTypeResolver::resolve($substitutedType, $ref);
            $paramOuts[$paramName] = $resolvedType;
        }

        $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
        if ($returnTag !== null) {
            $substitutedReturn = self::substituteAliases($returnTag->type, $aliases);
            $returnType = SpecialTypeResolver::resolve($substitutedReturn, $ref);

            if (
                Config::isRespectNativeNullabilityEnabled()
                && self::returnTypeExplicitlyAllowsNull($ref)
                && ! self::typeContainsNull($returnType)
            ) {
                $returnType = new NullableTypeNode($returnType);
            }
        }

        $paramsUseGenerics = false;
        if (\count($templates) > 0) {
            foreach ($types as $tNode) {
                if (self::typeReferencesTemplate($tNode, $templates)) {
                    $paramsUseGenerics = true;

                    break;
                }
            }
            if (! $paramsUseGenerics) {
                foreach ($paramOuts as $tNode) {
                    if (self::typeReferencesTemplate($tNode, $templates)) {
                        $paramsUseGenerics = true;

                        break;
                    }
                }
            }
        }

        $returnUsesMethodTemplates = false;
        if ($returnType !== null && \count($templates) > 0) {
            $returnUsesMethodTemplates = self::typeReferencesTemplate($returnType, $templates);
        }

        $returnIsThis = false;
        $returnIsDynamic = false;
        if ($returnType !== null) {
            $retStr = (string) $returnType;
            $returnIsThis = ($returnType instanceof \PHPStan\PhpDocParser\Ast\Type\ThisTypeNode)
                || ($returnType instanceof IdentifierTypeNode && strtolower($returnType->name) === '$this');
            $returnIsDynamic = $returnIsThis || str_contains($retStr, 'static') || str_contains($retStr, '$this');
        }

        $flags = self::computeContractFlags(
            $types,
            $returnType,
            $paramsUseGenerics,
            $returnUsesMethodTemplates,
            $aliases,
            $templates,
            false,
            $templates,
            $returnIsThis
        );

        return [
            'types' => $types,
            'constrainedTypes' => $flags['constrainedTypes'],
            'paramConditionals' => $flags['paramConditionals'],
            'paramOuts' => $paramOuts,
            'selfOut' => null,
            'templates' => $templates,
            'classTemplates' => [],
            'allTemplates' => $templates,
            'return' => $returnType,
            'aliases' => $aliases,
            'sensitiveParams' => $sensitiveParams,
            'hasParamContract' => \count($types) > 0,
            'hasParamOutContract' => \count($paramOuts) > 0,
            'hasSelfOutContract' => false,
            'hasReturnContract' => $returnType !== null,
            'paramsUseGenerics' => $paramsUseGenerics,
            'paramsAreDynamic' => false,
            'returnUsesGenerics' => $returnUsesMethodTemplates,
            'returnUsesMethodTemplates' => $returnUsesMethodTemplates,
            'returnIsThis' => $returnIsThis,
            'returnIsDynamic' => $returnIsDynamic,
            'allParamsUnconstrained' => $flags['allParamsUnconstrained'],
            'returnUnconstrained' => $flags['returnUnconstrained'],
            'returnHasConditional' => $flags['returnHasConditional'],
            'returnAllowsNull' => $flags['returnAllowsNull'],
            'paramsAreSimple' => $flags['paramsAreSimple'],
            'hasCrossReferencedBounds' => $flags['hasCrossReferencedBounds'],
            'paramsNeedPreInference' => $flags['paramsNeedPreInference'],
            'isSimple' => $flags['isSimple'],
        ];
    }

    /**
     * Resolves class-level docblocks (templates and aliases) up the class inheritance chain.
     *
     * @param \ReflectionClass<object> $declaringClass
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, TypeNode> $aliases
     */
    private static function parseClassLevelDocs(\ReflectionClass $declaringClass, array &$templates, array &$aliases): void
    {
        $className = $declaringClass->getName();
        if (isset(self::$classLevelDocCache[$className])) {
            $cached = self::$classLevelDocCache[$className];
            foreach ($cached['templates'] as $name => $tag) {
                if (! isset($templates[$name])) {
                    $templates[$name] = $tag;
                }
            }
            foreach ($cached['aliases'] as $name => $alias) {
                if (! isset($aliases[$name])) {
                    $aliases[$name] = $alias;
                }
            }

            return;
        }

        $classHierarchy = HierarchyResolver::getClassHierarchy($declaringClass);
        /** @var array<string, TemplateTagValueNode> $localTemplates */
        $localTemplates = [];
        /** @var array<string, TypeNode> $localAliases */
        $localAliases = [];

        foreach ($classHierarchy as $hierClass) {
            $hierClassName = $hierClass->getName();
            $stubDoc = StubManager::getClassDoc($hierClassName);
            $classDoc = $stubDoc ?? $hierClass->getDocComment();

            if ($classDoc !== false && $classDoc !== null) {
                $classPhpDocNode = DocblockExtractor::parseDocString($classDoc);

                foreach (DocblockExtractor::extractTemplates($classPhpDocNode) as $name => $tag) {
                    if (! isset($localTemplates[$name])) {
                        if ($tag->bound !== null || $tag->default !== null) {
                            $tag = new TemplateTagValueNode(
                                $tag->name,
                                $tag->bound !== null ? SpecialTypeResolver::resolve($tag->bound, $hierClass) : null,
                                $tag->description,
                                $tag->default !== null ? SpecialTypeResolver::resolve($tag->default, $hierClass) : null
                            );
                        }
                        $localTemplates[$name] = $tag;
                    }
                }
                DocblockExtractor::extractAliases($classPhpDocNode, $localAliases, $hierClass);
            }
        }

        self::$classLevelDocCache[$className] = [
            'templates' => $localTemplates,
            'aliases' => $localAliases,
        ];

        foreach ($localTemplates as $name => $tag) {
            if (! isset($templates[$name])) {
                $templates[$name] = $tag;
            }
        }
        foreach ($localAliases as $name => $alias) {
            if (! isset($aliases[$name])) {
                $aliases[$name] = $alias;
            }
        }
    }

    /**
     * Resolves method-level docblocks (@param, @param-out, @return, @template, aliases) up the method hierarchy.
     *
     * @param \ReflectionMethod $ref
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $templates
     * @param TypeNode|null $returnType
     * @param array<string, TypeNode> $aliases
     * @param array<string, TypeNode> $paramOuts
     * @param TypeNode|null $selfOut
     * @param array<string, TemplateTagValueNode> $classTemplates
     * @param array<string, bool> $sensitiveParams
     */
    private static function parseMethodHierarchyDocs(
        \ReflectionMethod $ref,
        array &$types,
        array &$templates,
        ?TypeNode &$returnType,
        array &$aliases,
        array &$paramOuts = [],
        ?TypeNode &$selfOut = null,
        array &$classTemplates = [],
        array &$sensitiveParams = [],
        ?string $targetClassName = null
    ): void {
        $hierarchy = HierarchyResolver::getMethodHierarchy($ref, $targetClassName);
        $baseParams = $ref->getParameters();
        $baseParamNames = [];
        $baseParamSet = [];
        $baseParamVariadic = [];
        $baseParamObjects = [];
        $isConstructor = ($ref->getName() === '__construct');

        foreach ($baseParams as $idx => $p) {
            $baseParamNames[$idx] = $p->getName();
            $baseParamSet[$p->getName()] = $idx;
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
            $baseParamObjects[$p->getName()] = $p;
            if (self::hasSensitiveAttribute($p)) {
                $sensitiveParams[$p->getName()] = true;
            }
        }

        foreach ($hierarchy as $hierRef) {
            $hierDeclaringClass = $hierRef->getDeclaringClass();
            self::parseClassLevelDocs($hierDeclaringClass, $classTemplates, $aliases);

            $isOriginal = ($hierRef === $ref);
            $declaringClass = $hierDeclaringClass->getName();
            $methodName = $hierRef->getName();
            $stubDoc = StubManager::getMethodDoc($declaringClass, $methodName);

            $fileName = $hierRef->getFileName();
            if ($stubDoc === null && ! $isOriginal && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $hierParams = $hierRef->getParameters();
            $hierNameToIndex = [];
            foreach ($hierParams as $idx => $p) {
                $hierNameToIndex[$p->getName()] = $idx;
                if (self::hasSensitiveAttribute($p)) {
                    $targetName = self::resolveTargetParamName(
                        $p->getName(),
                        $baseParamSet,
                        $baseParamNames,
                        $hierNameToIndex,
                        $isConstructor
                    );
                    $sensitiveParams[$targetName ?? $p->getName()] = true;
                }
            }

            $doc = $stubDoc ?? $hierRef->getDocComment();
            if ($doc === false || $doc === null || self::shouldIgnoreDoc($doc)) {
                continue;
            }

            $phpDocNode = DocblockExtractor::parseDocString($doc);

            DocblockExtractor::extractAliases($phpDocNode, $aliases, $hierRef);

            foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
                if (! isset($templates[$name])) {
                    if ($tag->bound !== null || $tag->default !== null) {
                        $bound = $tag->bound !== null ? SpecialTypeResolver::resolve(self::substituteAliases($tag->bound, $aliases), $hierRef) : null;
                        $default = $tag->default !== null ? SpecialTypeResolver::resolve(self::substituteAliases($tag->default, $aliases), $hierRef) : null;
                        $tag = new TemplateTagValueNode($tag->name, $bound, $tag->description, $default);
                    }
                    $templates[$name] = $tag;
                }
            }

            $paramTags = DocblockExtractor::getParamTags($phpDocNode);

            $paramIndex = 0;
            foreach ($paramTags as $paramName => $paramTag) {
                $targetParamName = self::resolveTargetParamName(
                    $paramName,
                    $baseParamSet,
                    $baseParamNames,
                    $hierNameToIndex,
                    $isConstructor
                );

                if ($targetParamName === null && $paramName === '' && isset($baseParamNames[$paramIndex])) {
                    $targetParamName = $baseParamNames[$paramIndex];
                }
                $paramIndex++;

                if ($targetParamName !== null && ! isset($types[$targetParamName])) {
                    $type = $paramTag->type;
                    $pObj = $baseParamObjects[$targetParamName] ?? null;

                    $type = self::wrapVariadicParameterType($type, $paramTag->isVariadic, $baseParamVariadic[$targetParamName] ?? false, $pObj);

                    if (
                        Config::isRespectNativeNullabilityEnabled()
                        && $pObj !== null
                        && self::parameterExplicitlyAllowsNull($pObj)
                        && ! self::typeContainsNull($type)
                    ) {
                        $type = new NullableTypeNode($type);
                    }

                    $substitutedType = self::substituteAliases($type, $aliases);
                    $resolvedType = SpecialTypeResolver::resolve($substitutedType, $hierRef);

                    if ($resolvedType instanceof IdentifierTypeNode && strtolower($resolvedType->name) === 'mixed') {
                        continue;
                    }

                    $types[$targetParamName] = $resolvedType;
                }
            }

            $paramOutTags = DocblockExtractor::getParamOutTags($phpDocNode);
            $paramOutIndex = 0;
            foreach ($paramOutTags as $paramName => $paramOutTag) {
                $targetParamName = self::resolveTargetParamName(
                    $paramName,
                    $baseParamSet,
                    $baseParamNames,
                    $hierNameToIndex,
                    $isConstructor
                );

                if ($targetParamName === null && $paramName === '' && isset($baseParamNames[$paramOutIndex])) {
                    $targetParamName = $baseParamNames[$paramOutIndex];
                }
                $paramOutIndex++;

                if ($targetParamName !== null && ! isset($paramOuts[$targetParamName])) {
                    $type = $paramOutTag->type;
                    $substitutedType = self::substituteAliases($type, $aliases);
                    $resolvedType = SpecialTypeResolver::resolve($substitutedType, $hierRef);
                    $paramOuts[$targetParamName] = $resolvedType;
                }
            }

            $selfOutType = DocblockExtractor::getSelfOutTag($phpDocNode);
            if ($selfOutType !== null && $selfOut === null) {
                $substituted = self::substituteAliases($selfOutType, $aliases);
                $selfOut = SpecialTypeResolver::resolve($substituted, $hierRef);
            }

            if ($returnType === null) {
                $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
                if ($returnTag !== null) {
                    $substitutedReturn = self::substituteAliases($returnTag->type, $aliases);
                    $returnType = SpecialTypeResolver::resolve($substitutedReturn, $hierRef);

                    if (
                        Config::isRespectNativeNullabilityEnabled()
                        && self::returnTypeExplicitlyAllowsNull($ref)
                        && ! self::typeContainsNull($returnType)
                    ) {
                        $returnType = new NullableTypeNode($returnType);
                    }
                }
            }
        }
    }

    /**
     * Disambiguates parameter name vs index position during inheritance.
     *
     * @param array<string, int> $baseParamSet
     * @param array<int, string> $baseParamNames
     * @param array<string, int> $hierNameToIndex
     */
    private static function resolveTargetParamName(
        string $paramName,
        array $baseParamSet,
        array $baseParamNames,
        array $hierNameToIndex,
        bool $isConstructor = false
    ): ?string {
        if (isset($baseParamSet[$paramName])) {
            return $paramName;
        }

        if ($isConstructor) {
            return null;
        }

        $paramIndex = $hierNameToIndex[$paramName] ?? null;
        if ($paramIndex === null || ! isset($baseParamNames[$paramIndex])) {
            return null;
        }

        $candidateName = $baseParamNames[$paramIndex];

        return ! isset($hierNameToIndex[$candidateName]) ? $candidateName : null;
    }

    /**
     * Falls back to property @var docblocks for constructor parameters if un-annotated,
     * ensuring scalar constructor parameters are never overridden by conflicting object property types.
     *
     * @param \ReflectionMethod $ref
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $classTemplates
     * @param array<string, TypeNode> $aliases
     */
    private static function applyConstructorPromotionFallback(
        \ReflectionMethod $ref,
        array &$types,
        array $classTemplates = [],
        array $aliases = []
    ): void {
        $declaringClass = $ref->getDeclaringClass();

        foreach ($ref->getParameters() as $p) {
            $paramName = $p->getName();

            if (! isset($types[$paramName]) && $declaringClass->hasProperty($paramName)) {
                $propertyRef = $declaringClass->getProperty($paramName);

                if (! self::areConstructorParamAndPropertyCompatible($p, $propertyRef)) {
                    continue;
                }

                $className = $declaringClass->getName();
                $stubDoc = StubManager::getPropertyDoc($className, $paramName);
                $propDoc = $stubDoc ?? $propertyRef->getDocComment();

                if ($propDoc !== false) {
                    $propType = DocblockExtractor::extractTypeFromPropertyDoc($propDoc, $paramName);
                    if ($propType !== null) {
                        $substitutedProp = self::substituteAliases($propType, $aliases);
                        $resolvedProp = SpecialTypeResolver::resolve($substitutedProp, $ref);

                        if ($p->hasType()) {
                            $nativeType = $p->getType();
                            if ($nativeType instanceof \ReflectionNamedType) {
                                $nativeName = strtolower($nativeType->getName());
                                $propTypeStr = strtolower((string) $resolvedProp);

                                if (
                                    $nativeType->isBuiltin()
                                    && ! \in_array($nativeName, ['array', 'iterable', 'mixed'], true)
                                    && ! \in_array($propTypeStr, [$nativeName, 'mixed'], true)
                                    && ! self::isRefinementOfBuiltin($propTypeStr, $nativeName)
                                ) {
                                    continue;
                                }
                            }
                        }

                        $resolvedProp = self::wrapVariadicParameterType($resolvedProp, false, $p->isVariadic(), $p);

                        if (
                            Config::isRespectNativeNullabilityEnabled()
                            && self::parameterExplicitlyAllowsNull($p)
                            && ! self::typeContainsNull($resolvedProp)
                        ) {
                            $resolvedProp = new NullableTypeNode($resolvedProp);
                        }

                        if ($resolvedProp instanceof IdentifierTypeNode && strtolower($resolvedProp->name) === 'mixed') {
                            continue;
                        }

                        $types[$paramName] = $resolvedProp;
                    }
                }
            }
        }
    }

    /**
     * Checks whether an un-promoted constructor parameter is compatible with a class property.
     */
    private static function areConstructorParamAndPropertyCompatible(
        \ReflectionParameter $p,
        \ReflectionProperty $propertyRef
    ): bool {
        if ($p->isPromoted()) {
            return true;
        }

        if ($p->hasType() && $propertyRef->hasType()) {
            $pType = (string) $p->getType();
            $propType = (string) $propertyRef->getType();

            $normP = strtolower(ltrim($pType, '?'));
            $normProp = strtolower(ltrim($propType, '?'));

            if ($normP !== $normProp) {
                return false;
            }
        }

        return true;
    }

    /**
     * Wraps a variadic parameter type into an ArrayTypeNode, accounting for variadic arrays/iterables.
     */
    private static function wrapVariadicParameterType(
        TypeNode $type,
        bool $tagIsVariadic,
        bool $nativeIsVariadic,
        ?\ReflectionParameter $reflectionParam = null
    ): TypeNode {
        if (! $tagIsVariadic && ! $nativeIsVariadic) {
            return $type;
        }

        $isNativeArrayOrIterable = false;
        if ($reflectionParam !== null && $reflectionParam->hasType()) {
            $nativeType = $reflectionParam->getType();
            if ($nativeType instanceof \ReflectionNamedType) {
                $nativeName = strtolower($nativeType->getName());
                $isNativeArrayOrIterable = \in_array($nativeName, ['array', 'iterable'], true);
            }
        }

        if ($isNativeArrayOrIterable) {
            return new ArrayTypeNode($type);
        }

        if (
            ! ($type instanceof ArrayTypeNode)
            && ! ($type instanceof GenericTypeNode && \in_array(strtolower((string) $type->type), ['array', 'list', 'iterable', 'traversable', 'non-empty-array', 'non-empty-list'], true))
        ) {
            return new ArrayTypeNode($type);
        }

        return $type;
    }

    /**
     * Checks if a scalar refinement type is compatible with a native PHP builtin type.
     */
    private static function isRefinementOfBuiltin(string $refinement, string $builtin): bool
    {
        if (isset(self::BUILTIN_REFINEMENTS[$builtin][$refinement])) {
            return true;
        }

        if (($builtin === 'int' || $builtin === 'integer') && str_starts_with($refinement, 'int<')) {
            return true;
        }

        if ($builtin === 'string' && str_starts_with($refinement, 'class-string<')) {
            return true;
        }

        return false;
    }

    /**
     * Checks if a reflection parameter explicitly declares a nullable native typehint or default null value (excluding mixed).
     */
    private static function parameterExplicitlyAllowsNull(\ReflectionParameter $p): bool
    {
        if (! $p->hasType()) {
            return $p->isDefaultValueAvailable() && $p->getDefaultValue() === null;
        }

        $type = $p->getType();
        if ($type instanceof \ReflectionNamedType) {
            $name = strtolower($type->getName());
            if ($name === 'mixed') {
                return false;
            }

            return $type->allowsNull();
        }

        if ($type instanceof \ReflectionUnionType) {
            return $type->allowsNull();
        }

        return false;
    }

    /**
     * Checks if a reflection function or method explicitly declares a nullable native return type (excluding void, mixed, and never).
     */
    private static function returnTypeExplicitlyAllowsNull(\ReflectionFunctionAbstract $ref): bool
    {
        if (! $ref->hasReturnType()) {
            return false;
        }

        $type = $ref->getReturnType();
        if ($type instanceof \ReflectionNamedType) {
            $name = strtolower($type->getName());
            if ($name === 'mixed' || $name === 'void' || $name === 'never') {
                return false;
            }

            return $type->allowsNull();
        }

        if ($type instanceof \ReflectionUnionType) {
            return $type->allowsNull();
        }

        return false;
    }

    /**
     * Checks if a TypeNode already represents or contains null.
     */
    private static function typeContainsNull(TypeNode $node): bool
    {
        if ($node instanceof NullableTypeNode) {
            return true;
        }

        if ($node instanceof IdentifierTypeNode && (\in_array(strtolower($node->name), ['null', 'mixed'], true))) {
            return true;
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $t) {
                if (self::typeContainsNull($t)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Recursively substitutes all type aliases inside a TypeNode AST and simplifies unions/intersections containing `mixed`.
     * Threads $visited across recursive calls to break self-referential and mutually recursive cycles.
     *
     * @param array<string, TypeNode> $aliases
     * @param array<string, true> $visited
     */
    public static function substituteAliases(TypeNode $node, array $aliases, array $visited = []): TypeNode
    {
        if ($aliases === []) {
            return $node;
        }

        $hasRecursivePlaceholder = false;
        $result = self::substituteAliasesInternal($node, $aliases, $visited, $hasRecursivePlaceholder);

        if ($hasRecursivePlaceholder && $visited === []) {
            return self::bindRecursiveAliases($result, $aliases);
        }

        return $result;
    }

    /**
     * Links any recursive alias placeholders (`typephp_recursive_alias_name`) inside an AST
     * to their resolved target TypeNodes from `$aliases`.
     *
     * @param array<string, TypeNode> $aliases
     */
    public static function bindRecursiveAliases(TypeNode $node, array $aliases): TypeNode
    {
        if ($aliases === []) {
            return $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            $aliasName = $node->getAttribute('typephp_recursive_alias_name');
            if (\is_string($aliasName) && isset($aliases[$aliasName])) {
                $target = $aliases[$aliasName];
                if ($target !== $node) {
                    $node->setAttribute('typephp_recursive_alias', $target);
                }
            }

            return $node;
        }

        if ($node instanceof CallableTypeNode) {
            foreach ($node->parameters as $param) {
                self::bindRecursiveAliases($param->type, $aliases);
            }
            self::bindRecursiveAliases($node->returnType, $aliases);

            return $node;
        }

        if ($node instanceof OffsetAccessTypeNode) {
            self::bindRecursiveAliases($node->type, $aliases);
            self::bindRecursiveAliases($node->offset, $aliases);

            return $node;
        }

        if ($node instanceof ArrayTypeNode || $node instanceof NullableTypeNode) {
            self::bindRecursiveAliases($node->type, $aliases);

            return $node;
        }

        if ($node instanceof GenericTypeNode) {
            self::bindRecursiveAliases($node->type, $aliases);
            foreach ($node->genericTypes as $gt) {
                self::bindRecursiveAliases($gt, $aliases);
            }

            return $node;
        }

        if ($node instanceof UnionTypeNode || $node instanceof IntersectionTypeNode) {
            foreach ($node->types as $t) {
                self::bindRecursiveAliases($t, $aliases);
            }

            return $node;
        }

        if ($node instanceof ArrayShapeNode) {
            foreach ($node->items as $item) {
                self::bindRecursiveAliases($item->valueType, $aliases);
            }
            if ($node->unsealedType !== null) {
                if ($node->unsealedType->keyType !== null) {
                    self::bindRecursiveAliases($node->unsealedType->keyType, $aliases);
                }
                self::bindRecursiveAliases($node->unsealedType->valueType, $aliases);
            }

            return $node;
        }

        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                self::bindRecursiveAliases($item->valueType, $aliases);
            }

            return $node;
        }

        if ($node instanceof ConditionalTypeNode) {
            self::bindRecursiveAliases($node->subjectType, $aliases);
            self::bindRecursiveAliases($node->targetType, $aliases);
            self::bindRecursiveAliases($node->if, $aliases);
            self::bindRecursiveAliases($node->else, $aliases);

            return $node;
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            self::bindRecursiveAliases($node->targetType, $aliases);
            self::bindRecursiveAliases($node->if, $aliases);
            self::bindRecursiveAliases($node->else, $aliases);

            return $node;
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $aliases
     * @param array<string, true> $visited
     */
    private static function substituteAliasesInternal(
        TypeNode $node,
        array $aliases,
        array $visited,
        bool &$hasRecursivePlaceholder
    ): TypeNode {
        if ($node instanceof IdentifierTypeNode) {
            if (! isset($aliases[$node->name])) {
                return $node;
            }

            if (isset($visited[$node->name])) {
                if ($node->getAttribute('typephp_recursive_alias') instanceof TypeNode) {
                    return $node;
                }

                $hasRecursivePlaceholder = true;

                return self::createRecursiveAliasPlaceholder($node->name);
            }

            $currentVisited = $visited;
            $current = $node;

            while (isset($aliases[$current->name])) {
                if (isset($currentVisited[$current->name])) {
                    if ($current->getAttribute('typephp_recursive_alias') instanceof TypeNode) {
                        return $current;
                    }

                    $hasRecursivePlaceholder = true;

                    return self::createRecursiveAliasPlaceholder($current->name);
                }

                $currentVisited[$current->name] = true;
                $target = $aliases[$current->name];

                if ($target instanceof IdentifierTypeNode) {
                    $current = $target;
                } else {
                    return self::substituteAliasesInternal($target, $aliases, $currentVisited, $hasRecursivePlaceholder);
                }
            }

            return $current;
        }

        if ($node instanceof CallableTypeNode) {
            $parameters = [];
            foreach ($node->parameters as $param) {
                $parameters[] = new CallableTypeParameterNode(
                    self::substituteAliasesInternal($param->type, $aliases, $visited, $hasRecursivePlaceholder),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                );
            }

            $returnType = self::substituteAliasesInternal($node->returnType, $aliases, $visited, $hasRecursivePlaceholder);

            return new CallableTypeNode(
                $node->identifier,
                $parameters,
                $returnType,
                $node->templateTypes
            );
        }

        if ($node instanceof OffsetAccessTypeNode) {
            return new OffsetAccessTypeNode(
                self::substituteAliasesInternal($node->type, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->offset, $aliases, $visited, $hasRecursivePlaceholder)
            );
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::substituteAliasesInternal($node->type, $aliases, $visited, $hasRecursivePlaceholder));
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::substituteAliasesInternal($node->type, $aliases, $visited, $hasRecursivePlaceholder);
            $genericTypes = [];
            foreach ($node->genericTypes as $gt) {
                $genericTypes[] = self::substituteAliasesInternal($gt, $aliases, $visited, $hasRecursivePlaceholder);
            }

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $genericTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::substituteAliasesInternal($node->type, $aliases, $visited, $hasRecursivePlaceholder));
        }

        if ($node instanceof UnionTypeNode) {
            $types = [];
            foreach ($node->types as $t) {
                $types[] = self::substituteAliasesInternal($t, $aliases, $visited, $hasRecursivePlaceholder);
            }

            foreach ($types as $t) {
                if ($t instanceof IdentifierTypeNode && strtolower($t->name) === 'mixed') {
                    return new IdentifierTypeNode('mixed');
                }
            }

            return new UnionTypeNode($types);
        }

        if ($node instanceof IntersectionTypeNode) {
            $types = [];
            foreach ($node->types as $t) {
                $types[] = self::substituteAliasesInternal($t, $aliases, $visited, $hasRecursivePlaceholder);
            }

            $filtered = array_values(array_filter($types, function ($t) {
                return ! ($t instanceof IdentifierTypeNode && strtolower($t->name) === 'mixed');
            }));

            if (\count($filtered) === 0) {
                return new IdentifierTypeNode('mixed');
            }

            if (\count($filtered) === 1) {
                return $filtered[0];
            }

            return new IntersectionTypeNode($filtered);
        }

        if ($node instanceof ArrayShapeNode) {
            $newItems = [];
            foreach ($node->items as $item) {
                $newItems[] = new ArrayShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::substituteAliasesInternal($item->valueType, $aliases, $visited, $hasRecursivePlaceholder)
                );
            }

            $newUnsealed = null;
            if ($node->unsealedType !== null) {
                $unsealedKey = $node->unsealedType->keyType !== null
                    ? self::substituteAliasesInternal($node->unsealedType->keyType, $aliases, $visited, $hasRecursivePlaceholder)
                    : null;
                $unsealedVal = self::substituteAliasesInternal($node->unsealedType->valueType, $aliases, $visited, $hasRecursivePlaceholder);
                $newUnsealed = new ArrayShapeUnsealedTypeNode($unsealedVal, $unsealedKey);
            }

            if ($node->sealed) {
                return ArrayShapeNode::createSealed($newItems, $node->kind);
            }

            return ArrayShapeNode::createUnsealed($newItems, $newUnsealed, $node->kind);
        }

        if ($node instanceof ObjectShapeNode) {
            $newItems = [];
            foreach ($node->items as $item) {
                $newItems[] = new ObjectShapeItemNode(
                    $item->keyName,
                    $item->optional,
                    self::substituteAliasesInternal($item->valueType, $aliases, $visited, $hasRecursivePlaceholder)
                );
            }

            return new ObjectShapeNode($newItems);
        }

        if ($node instanceof ConditionalTypeNode) {
            return new ConditionalTypeNode(
                self::substituteAliasesInternal($node->subjectType, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->targetType, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->if, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->else, $aliases, $visited, $hasRecursivePlaceholder),
                $node->negated
            );
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return new ConditionalTypeForParameterNode(
                $node->parameterName,
                self::substituteAliasesInternal($node->targetType, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->if, $aliases, $visited, $hasRecursivePlaceholder),
                self::substituteAliasesInternal($node->else, $aliases, $visited, $hasRecursivePlaceholder),
                $node->negated
            );
        }

        return $node;
    }

    private static function createRecursiveAliasPlaceholder(string $name): IdentifierTypeNode
    {
        $placeholder = new IdentifierTypeNode($name);
        $placeholder->setAttribute('typephp_recursive_alias_name', $name);
        $placeholder->setAttribute('typephp_resolved', true);

        return $placeholder;
    }
}
