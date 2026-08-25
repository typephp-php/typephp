<?php

declare(strict_types=1);

namespace TypePHP\Contract;

use PHPStan\PhpDocParser\Ast\PhpDoc\MethodTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use TypePHP\Internal\Config;
use TypePHP\Internal\StubManager;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Main orchestrator parsing and caching PHPDoc contracts (@param, @return, @template, @phpstan-type, @var, stubs).
 */
final class ContractParser
{
    /**
     * Cache for resolved contract metadata.
     *
     * @var array<string, array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, classTemplates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>, hasParamContract: bool, hasReturnContract: bool}>
     */
    private static array $cache = [];

    /**
     * Cache for resolved class property types.
     *
     * @var array<string, ?TypeNode>
     */
    private static array $propertyCache = [];

    /**
     * Cache for resolved magic method contracts.
     *
     * @var array<string, ?array{return: ?TypeNode, parameters: array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>, aliases: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>}>
     */
    private static array $magicMethodCache = [];

    /**
     * In-memory cache for class-level templates and aliases per class name.
     *
     * @var array<string, array{templates: array<string, TemplateTagValueNode>, aliases: array<string, TypeNode>}>
     */
    private static array $classLevelDocCache = [];

    /**
     * Resets the contract, property, and class-level docblock caches.
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$propertyCache = [];
        self::$magicMethodCache = [];
        self::$classLevelDocCache = [];
        DocblockExtractor::reset();
        FileFilter::reset();
        TypeValidatorRegistry::reset();
        StubManager::reset();
        SpecialTypeResolver::reset();
    }

    /**
     * Parses PHPDoc contracts for a function or class method.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, classTemplates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>, hasParamContract: bool, hasReturnContract: bool}
     */
    public static function parse(string $function): array
    {
        if (isset(self::$cache[$function])) {
            return self::$cache[$function];
        }

        try {
            if (str_contains($function, '::')) {
                [$className, $methodName] = explode('::', $function, 2);

                if (class_exists($className) || interface_exists($className) || trait_exists($className) || enum_exists($className)) {
                    /** @var class-string<object> $className */
                    $refClass = new \ReflectionClass($className);
                    if ($refClass->hasMethod($methodName)) {
                        $ref = $refClass->getMethod($methodName);
                        $contract = self::parseMethod($ref);
                    } else {
                        $classTemplates = [];
                        $aliases = [];
                        self::parseClassLevelDocs($refClass, $classTemplates, $aliases);
                        $contract = [
                            'types' => [],
                            'templates' => [],
                            'classTemplates' => $classTemplates,
                            'return' => null,
                            'aliases' => $aliases,
                            'hasParamContract' => false,
                            'hasReturnContract' => false,
                        ];
                    }
                } else {
                    $contract = [
                        'types' => [],
                        'templates' => [],
                        'classTemplates' => [],
                        'return' => null,
                        'aliases' => [],
                        'hasParamContract' => false,
                        'hasReturnContract' => false,
                    ];
                }
            } else {
                $ref = new \ReflectionFunction($function);
                $contract = self::parseFunction($ref);
            }
        } catch (\ReflectionException $e) {
            $contract = [
                'types' => [],
                'templates' => [],
                'classTemplates' => [],
                'return' => null,
                'aliases' => [],
                'hasParamContract' => false,
                'hasReturnContract' => false,
            ];
        }

        return self::$cache[$function] = $contract;
    }

    /**
     * Parses and resolves the @var or @property docblock for a given class property.
     */
    public static function parseProperty(string $className, string $propertyName): ?TypeNode
    {
        $cacheKey = $className . '::$' . $propertyName;
        if (\array_key_exists($cacheKey, self::$propertyCache)) {
            return self::$propertyCache[$cacheKey];
        }

        if (! class_exists($className) && ! trait_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return self::$propertyCache[$cacheKey] = null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);

            $resolved = self::findDeclaredPropertyDoc($refClass, $propertyName);
            $doc = $resolved['doc'] ?? false;
            $declaringClass = $resolved['declaringClass'] ?? null;
            $typeNode = null;
            $isMagicProperty = false;

            if ($doc === false && (bool) (Config::get()['magic_properties'] ?? true)) {
                $magicResolved = self::findMagicPropertyDoc($refClass, $propertyName);
                if ($magicResolved !== null) {
                    $doc = $magicResolved['doc'];
                    $declaringClass = $magicResolved['declaringClass'];
                    $typeNode = $magicResolved['typeNode'];
                    $isMagicProperty = true;
                }
            }

            if ($doc === false || $declaringClass === null || self::shouldIgnoreDoc($doc)) {
                return self::$propertyCache[$cacheKey] = null;
            }

            if (! $isMagicProperty) {
                $phpDocNode = DocblockExtractor::parseDocString($doc);
                $varTags = DocblockExtractor::getVarTags($phpDocNode);

                if (\count($varTags) === 0) {
                    return self::$propertyCache[$cacheKey] = null;
                }

                $typeNode = $varTags[0]->type;
            }

            if ($typeNode === null) {
                return self::$propertyCache[$cacheKey] = null;
            }

            $aliases = [];
            $classTemplates = [];
            self::parseClassLevelDocs($declaringClass, $classTemplates, $aliases);

            if (! $isMagicProperty) {
                $phpDocNode = DocblockExtractor::parseDocString($doc);
                DocblockExtractor::extractAliases($phpDocNode, $aliases, $declaringClass);
            }

            $typeNode = self::substituteAliases($typeNode, $aliases);
            $resolvedNode = SpecialTypeResolver::resolve($typeNode, $declaringClass);

            return self::$propertyCache[$cacheKey] = $resolvedNode;
        } catch (\Throwable $e) {
            return self::$propertyCache[$cacheKey] = null;
        }
    }

    /**
     * @param \ReflectionClass<object> $refClass
     *
     * @return array{doc: string, declaringClass: \ReflectionClass<object>}|null
     */
    private static function findDeclaredPropertyDoc(\ReflectionClass $refClass, string $propertyName): ?array
    {
        $current = $refClass;
        while ($current !== false) {
            $className = $current->getName();
            $stubDoc = StubManager::getPropertyDoc($className, $propertyName);
            if ($stubDoc !== null) {
                return ['doc' => $stubDoc, 'declaringClass' => $current];
            }

            if ($current->hasProperty($propertyName)) {
                $refProp = $current->getProperty($propertyName);
                $doc = $refProp->getDocComment();
                if ($doc !== false) {
                    return ['doc' => $doc, 'declaringClass' => $current];
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
                    return ['doc' => $doc, 'declaringClass' => $interface];
                }
            }
        }

        return null;
    }

    /**
     * @param \ReflectionClass<object> $refClass
     *
     * @return array{doc: string, declaringClass: \ReflectionClass<object>, typeNode: TypeNode}|null
     */
    private static function findMagicPropertyDoc(\ReflectionClass $refClass, string $propertyName): ?array
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
                $extractedType = DocblockExtractor::extractTypeFromClassPropertyDoc($classDoc, $propertyName);
                if ($extractedType !== null) {
                    return [
                        'doc' => $classDoc,
                        'declaringClass' => $hierClass,
                        'typeNode' => $extractedType,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Parses and resolves a class-level @method docblock for __call / __callStatic.
     *
     * @return array{return: ?TypeNode, parameters: array<int, array{name: string, type: ?TypeNode, isVariadic: bool, isOptional: bool}>, aliases: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>}|null
     */
    public static function parseMagicMethod(string $className, string $methodName): ?array
    {
        $cacheKey = $className . '::' . $methodName;
        if (\array_key_exists($cacheKey, self::$magicMethodCache)) {
            return self::$magicMethodCache[$cacheKey];
        }

        if (! class_exists($className) && ! trait_exists($className) && ! interface_exists($className) && ! enum_exists($className)) {
            return self::$magicMethodCache[$cacheKey] = null;
        }

        try {
            /** @var class-string<object> $className */
            $refClass = new \ReflectionClass($className);

            $resolved = self::findMagicMethodDoc($refClass, $methodName);
            if ($resolved === null) {
                return self::$magicMethodCache[$cacheKey] = null;
            }

            $doc = $resolved['doc'];
            $declaringClass = $resolved['declaringClass'];
            $methodTag = $resolved['methodTag'];

            if (self::shouldIgnoreDoc($doc)) {
                return self::$magicMethodCache[$cacheKey] = null;
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

            return self::$magicMethodCache[$cacheKey] = [
                'return' => $resolvedReturn,
                'parameters' => $resolvedParams,
                'aliases' => $aliases,
                'templates' => $classTemplates,
            ];
        } catch (\Throwable $e) {
            return self::$magicMethodCache[$cacheKey] = null;
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
        $shouldRespectIgnore = (bool) (Config::get()['respect_ignore_tags'] ?? true);

        return $shouldRespectIgnore && (str_contains($doc, '@typephp-ignore') || str_contains($doc, '@typephp-disable'));
    }

    /**
     * Extracts and returns all class-level type aliases for a given class.
     *
     * @return array<string, TypeNode>
     */
    public static function parseClassAliases(string $className): array
    {
        if (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className) && ! enum_exists($className)) {
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
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, classTemplates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>, hasParamContract: bool, hasReturnContract: bool}
     */
    private static function parseMethod(\ReflectionMethod $ref): array
    {
        $types = [];
        $methodTemplates = [];
        $classTemplates = [];
        $returnType = null;
        $aliases = [];

        self::parseClassLevelDocs($ref->getDeclaringClass(), $classTemplates, $aliases);
        self::parseMethodHierarchyDocs($ref, $types, $methodTemplates, $returnType, $aliases);

        if ($ref->getName() === '__construct') {
            self::applyConstructorPromotionFallback($ref, $types);
        }

        return [
            'types' => $types,
            'templates' => $methodTemplates,
            'classTemplates' => $classTemplates,
            'return' => $returnType,
            'aliases' => $aliases,
            'hasParamContract' => \count($types) > 0,
            'hasReturnContract' => $returnType !== null,
        ];
    }

    /**
     * Orchestrates parsing for standalone global or namespaced functions.
     *
     * @return array{types: array<string, TypeNode>, templates: array<string, TemplateTagValueNode>, classTemplates: array<string, TemplateTagValueNode>, return: ?TypeNode, aliases: array<string, TypeNode>, hasParamContract: bool, hasReturnContract: bool}
     */
    private static function parseFunction(\ReflectionFunction $ref): array
    {
        $types = [];
        $templates = [];
        $returnType = null;
        $aliases = [];

        $funcName = $ref->getName();
        $stubDoc = StubManager::getFunctionDoc($funcName);
        $doc = $stubDoc ?? $ref->getDocComment();

        if ($doc === false || $doc === null) {
            return [
                'types' => [],
                'templates' => [],
                'classTemplates' => [],
                'return' => null,
                'aliases' => [],
                'hasParamContract' => false,
                'hasReturnContract' => false,
            ];
        }

        $phpDocNode = DocblockExtractor::parseDocString($doc);

        foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
            $templates[$name] = $tag;
        }
        DocblockExtractor::extractAliases($phpDocNode, $aliases, $ref);

        $baseParams = $ref->getParameters();
        $baseParamVariadic = [];
        foreach ($baseParams as $p) {
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
        }

        foreach (DocblockExtractor::getParamTags($phpDocNode) as $paramName => $paramTag) {
            $type = $paramTag->type;
            $isVariadic = $paramTag->isVariadic || ($baseParamVariadic[$paramName] ?? false);
            if (
                $isVariadic
                && ! ($type instanceof ArrayTypeNode)
                && ! ($type instanceof GenericTypeNode && \in_array(strtolower($type->type->name), ['array', 'list', 'iterable', 'traversable', 'non-empty-array', 'non-empty-list'], true))
            ) {
                $type = new ArrayTypeNode($type);
            }
            $substitutedType = self::substituteAliases($type, $aliases);
            $resolvedType = SpecialTypeResolver::resolve($substitutedType, $ref);

            if ($resolvedType instanceof IdentifierTypeNode && strtolower($resolvedType->name) === 'mixed') {
                continue;
            }

            $types[$paramName] = $resolvedType;
        }

        $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
        if ($returnTag !== null) {
            $substitutedReturn = self::substituteAliases($returnTag->type, $aliases);
            $returnType = SpecialTypeResolver::resolve($substitutedReturn, $ref);
        }

        return [
            'types' => $types,
            'templates' => $templates,
            'classTemplates' => [],
            'return' => $returnType,
            'aliases' => $aliases,
            'hasParamContract' => \count($types) > 0,
            'hasReturnContract' => $returnType !== null,
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
            $templates = $cached['templates'];
            $aliases = $cached['aliases'];

            return;
        }

        $classHierarchy = HierarchyResolver::getClassHierarchy($declaringClass);

        foreach ($classHierarchy as $hierClass) {
            $hierClassName = $hierClass->getName();
            $fileName = $hierClass->getFileName();
            $stubDoc = StubManager::getClassDoc($hierClassName);

            if ($stubDoc === null && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $classDoc = $stubDoc ?? $hierClass->getDocComment();
            if ($classDoc !== false && $classDoc !== null) {
                $classPhpDocNode = DocblockExtractor::parseDocString($classDoc);

                foreach (DocblockExtractor::extractTemplates($classPhpDocNode) as $name => $tag) {
                    if (! isset($templates[$name])) {
                        $templates[$name] = $tag;
                    }
                }
                DocblockExtractor::extractAliases($classPhpDocNode, $aliases, $hierClass);
            }
        }

        self::$classLevelDocCache[$className] = [
            'templates' => $templates,
            'aliases' => $aliases,
        ];
    }

    /**
     * Resolves method-level docblocks (@param, @return, @template, aliases) up the method hierarchy.
     *
     * @param \ReflectionMethod $ref
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $templates
     * @param TypeNode|null $returnType
     * @param array<string, TypeNode> $aliases
     */
    private static function parseMethodHierarchyDocs(
        \ReflectionMethod $ref,
        array &$types,
        array &$templates,
        ?TypeNode &$returnType,
        array &$aliases
    ): void {
        $hierarchy = HierarchyResolver::getMethodHierarchy($ref);
        $baseParams = $ref->getParameters();
        $baseParamNames = [];
        $baseParamSet = [];
        $baseParamVariadic = [];
        $isConstructor = ($ref->getName() === '__construct');

        foreach ($baseParams as $idx => $p) {
            $baseParamNames[$idx] = $p->getName();
            $baseParamSet[$p->getName()] = $idx;
            $baseParamVariadic[$p->getName()] = $p->isVariadic();
        }

        foreach ($hierarchy as $hierRef) {
            $isOriginal = ($hierRef === $ref);
            $declaringClass = $hierRef->getDeclaringClass()->getName();
            $methodName = $hierRef->getName();
            $stubDoc = StubManager::getMethodDoc($declaringClass, $methodName);

            $fileName = $hierRef->getFileName();
            if ($stubDoc === null && ! $isOriginal && FileFilter::isFileExcluded($fileName !== false ? $fileName : null)) {
                continue;
            }

            $doc = $stubDoc ?? $hierRef->getDocComment();
            if ($doc === false || $doc === null) {
                continue;
            }

            $phpDocNode = DocblockExtractor::parseDocString($doc);

            foreach (DocblockExtractor::extractTemplates($phpDocNode) as $name => $tag) {
                if (! isset($templates[$name])) {
                    $templates[$name] = $tag;
                }
            }
            DocblockExtractor::extractAliases($phpDocNode, $aliases, $hierRef);

            $hierParams = $hierRef->getParameters();
            $hierNameToIndex = [];
            foreach ($hierParams as $idx => $p) {
                $hierNameToIndex[$p->getName()] = $idx;
            }

            $paramTags = DocblockExtractor::getParamTags($phpDocNode);

            foreach ($paramTags as $paramName => $paramTag) {
                $targetParamName = self::resolveTargetParamName(
                    $paramName,
                    $baseParamSet,
                    $baseParamNames,
                    $hierNameToIndex,
                    $isConstructor
                );

                if ($targetParamName !== null && ! isset($types[$targetParamName])) {
                    $type = $paramTag->type;
                    $isVariadic = $paramTag->isVariadic || ($baseParamVariadic[$targetParamName] ?? false);
                    if (
                        $isVariadic
                        && ! ($type instanceof ArrayTypeNode)
                        && ! ($type instanceof GenericTypeNode && \in_array(strtolower($type->type->name), ['array', 'list', 'iterable', 'traversable', 'non-empty-array', 'non-empty-list'], true))
                    ) {
                        $type = new ArrayTypeNode($type);
                    }
                    $substitutedType = self::substituteAliases($type, $aliases);
                    $resolvedType = SpecialTypeResolver::resolve($substitutedType, $hierRef);

                    if ($resolvedType instanceof IdentifierTypeNode && strtolower($resolvedType->name) === 'mixed') {
                        continue;
                    }

                    $types[$targetParamName] = $resolvedType;
                }
            }

            if ($returnType === null) {
                $returnTag = DocblockExtractor::getReturnTag($phpDocNode);
                if ($returnTag !== null) {
                    $substitutedReturn = self::substituteAliases($returnTag->type, $aliases);
                    $returnType = SpecialTypeResolver::resolve($substitutedReturn, $hierRef);
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
     */
    private static function applyConstructorPromotionFallback(\ReflectionMethod $ref, array &$types): void
    {
        $declaringClass = $ref->getDeclaringClass();

        foreach ($ref->getParameters() as $p) {
            $paramName = $p->getName();

            if (! isset($types[$paramName]) && $declaringClass->hasProperty($paramName)) {
                $className = $declaringClass->getName();
                $stubDoc = StubManager::getPropertyDoc($className, $paramName);

                $propertyRef = $declaringClass->getProperty($paramName);
                $propDoc = $stubDoc ?? $propertyRef->getDocComment();

                if ($propDoc !== false && $propDoc !== null) {
                    $propType = DocblockExtractor::extractTypeFromPropertyDoc($propDoc, $paramName);
                    if ($propType !== null) {
                        if ($p->hasType()) {
                            $nativeType = $p->getType();
                            if ($nativeType instanceof \ReflectionNamedType) {
                                $nativeName = strtolower($nativeType->getName());
                                $propTypeStr = strtolower((string) $propType);

                                if (
                                    $nativeType->isBuiltin()
                                    && ! \in_array($nativeName, ['array', 'iterable', 'mixed'], true)
                                    && ! \in_array($propTypeStr, [$nativeName, 'mixed'], true)
                                ) {
                                    continue;
                                }
                            }
                        }

                        $isVariadic = $p->isVariadic();
                        if (
                            $isVariadic
                            && ! ($propType instanceof ArrayTypeNode)
                            && ! ($propType instanceof GenericTypeNode && \in_array(strtolower($propType->type->name), ['array', 'list', 'iterable', 'traversable', 'non-empty-array', 'non-empty-list'], true))
                        ) {
                            $propType = new ArrayTypeNode($propType);
                        }
                        $substitutedProp = self::substituteAliases($propType, []);
                        $resolvedProp = SpecialTypeResolver::resolve($substitutedProp, $ref);

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
     * Recursively substitutes all type aliases inside a TypeNode AST and simplifies unions/intersections containing `mixed`.
     *
     * @param array<string, TypeNode> $aliases
     */
    public static function substituteAliases(TypeNode $node, array $aliases): TypeNode
    {
        if ($node instanceof IdentifierTypeNode) {
            if (isset($aliases[$node->name])) {
                return self::substituteAliases($aliases[$node->name], $aliases);
            }

            return $node;
        }

        if ($node instanceof CallableTypeNode) {
            $parameters = array_map(
                fn (CallableTypeParameterNode $param) => new CallableTypeParameterNode(
                    self::substituteAliases($param->type, $aliases),
                    $param->isReference,
                    $param->isVariadic,
                    $param->parameterName,
                    $param->isOptional
                ),
                $node->parameters
            );

            $returnType = self::substituteAliases($node->returnType, $aliases);

            return new CallableTypeNode(
                $node->identifier,
                $parameters,
                $returnType,
                $node->templateTypes
            );
        }

        if ($node instanceof OffsetAccessTypeNode) {
            return new OffsetAccessTypeNode(
                self::substituteAliases($node->type, $aliases),
                self::substituteAliases($node->offset, $aliases)
            );
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::substituteAliases($node->type, $aliases));
        }

        if ($node instanceof GenericTypeNode) {
            $genericType = self::substituteAliases($node->type, $aliases);
            $genericTypes = array_map(
                fn ($t) => self::substituteAliases($t, $aliases),
                $node->genericTypes
            );

            return new GenericTypeNode(
                $genericType instanceof IdentifierTypeNode ? $genericType : $node->type,
                $genericTypes,
                $node->variances
            );
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::substituteAliases($node->type, $aliases));
        }

        if ($node instanceof UnionTypeNode) {
            $types = array_map(
                fn ($t) => self::substituteAliases($t, $aliases),
                $node->types
            );

            foreach ($types as $t) {
                if ($t instanceof IdentifierTypeNode && strtolower($t->name) === 'mixed') {
                    return new IdentifierTypeNode('mixed');
                }
            }

            return new UnionTypeNode($types);
        }

        if ($node instanceof IntersectionTypeNode) {
            $types = array_map(
                fn ($t) => self::substituteAliases($t, $aliases),
                $node->types
            );

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
            foreach ($node->items as $item) {
                $item->valueType = self::substituteAliases($item->valueType, $aliases);
            }
            if ($node->unsealedType !== null) {
                if ($node->unsealedType->keyType !== null) {
                    $node->unsealedType->keyType = self::substituteAliases($node->unsealedType->keyType, $aliases);
                }
                $node->unsealedType->valueType = self::substituteAliases($node->unsealedType->valueType, $aliases);
            }

            return $node;
        }

        if ($node instanceof ObjectShapeNode) {
            foreach ($node->items as $item) {
                $item->valueType = self::substituteAliases($item->valueType, $aliases);
            }

            return $node;
        }

        return $node;
    }
}