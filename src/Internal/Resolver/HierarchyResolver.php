<?php

declare(strict_types=1);

namespace TypePHP\Internal\Resolver;

use ReflectionClass;
use ReflectionMethod;

/**
 * @internal Resolves class, interface, trait, and method inheritance hierarchies from child to root.
 */
final class HierarchyResolver
{
    /**
     * In-memory cache for resolved ReflectionMethod hierarchy arrays.
     *
     * @var array<string, array<int, ReflectionMethod>>
     */
    private static array $methodHierarchyCache = [];

    /**
     * In-memory cache for resolved ReflectionClass hierarchy arrays.
     *
     * @var array<string, array<int, ReflectionClass<object>>>
     */
    private static array $classHierarchyCache = [];

    /**
     * In-memory cache for class trait aliases.
     *
     * @var array<string, array<string, string>>
     */
    private static array $traitAliasCache = [];

    /**
     * Boolean fast-path cache: true if class has trait aliases, false if not.
     * Avoids array allocation and comparison for the common "no traits" case.
     *
     * @var array<string, bool>
     */
    private static array $hasTraitAliasesCache = [];

    /**
     * Cache for 4-way class/interface/trait/enum existence checks.
     * Avoids repeated existence checks for the same class name.
     *
     * @var array<string, bool>
     */
    private static array $classExistsCache = [];

    /**
     * Resets the hierarchy cache. Useful for test isolation.
     */
    public static function reset(): void
    {
        self::$methodHierarchyCache = [];
        self::$classHierarchyCache = [];
        self::$traitAliasCache = [];
        self::$hasTraitAliasesCache = [];
        self::$classExistsCache = [];
    }

    /**
     * Checks whether a class, interface, trait, or enum exists.
     * Caches the result to avoid repeated 4-way existence checks.
     */
    private static function typeExists(string $className): bool
    {
        if (isset(self::$classExistsCache[$className])) {
            return self::$classExistsCache[$className];
        }

        $exists = class_exists($className)
            || interface_exists($className)
            || trait_exists($className)
            || enum_exists($className);

        return self::$classExistsCache[$className] = $exists;
    }

    /**
     * Returns cached trait aliases for a given class.
     *
     * Optimized with boolean fast-path cache to avoid reflection
     * for classes known to not use traits.
     *
     * @return array<string, string>
     */
    public static function getTraitAliases(string $className): array
    {
        if (isset(self::$hasTraitAliasesCache[$className])) {
            if (! self::$hasTraitAliasesCache[$className]) {
                return [];
            }

            return self::$traitAliasCache[$className];
        }

        if (isset(self::$traitAliasCache[$className])) {
            return self::$traitAliasCache[$className];
        }

        if (! self::typeExists($className)) {
            self::$hasTraitAliasesCache[$className] = false;

            return self::$traitAliasCache[$className] = [];
        }

        try {
            /** @var class-string<object> $className */
            $ref = new ReflectionClass($className);
            $aliases = $ref->getTraitAliases();

            self::$hasTraitAliasesCache[$className] = ($aliases !== []);

            return self::$traitAliasCache[$className] = $aliases;
        } catch (\Throwable $e) {
            self::$hasTraitAliasesCache[$className] = false;

            return self::$traitAliasCache[$className] = [];
        }
    }

    /**
     * Builds an array of ReflectionMethods representing the inheritance hierarchy from child to root.
     *
     * @return array<int, ReflectionMethod>
     */
    public static function getMethodHierarchy(ReflectionMethod $ref): array
    {
        $cacheKey = $ref->class . '::' . $ref->getName();
        if (isset(self::$methodHierarchyCache[$cacheKey])) {
            return self::$methodHierarchyCache[$cacheKey];
        }

        $hierarchy = [$ref];
        $methodName = $ref->getName();
        $targetClassName = $ref->class;

        $targetClass = new ReflectionClass($targetClassName);

        $traitAliases = self::getTraitAliases($targetClassName);
        if (isset($traitAliases[$methodName])) {
            [$traitName, $originalMethodName] = explode('::', $traitAliases[$methodName], 2);
            if (trait_exists($traitName)) {
                $traitRef = new ReflectionClass($traitName);
                if ($traitRef->hasMethod($originalMethodName)) {
                    $hierarchy[] = $traitRef->getMethod($originalMethodName);
                }
            }
        }

        $parent = $targetClass->getParentClass();
        while ($parent !== false) {
            if ($parent->hasMethod($methodName)) {
                $hierarchy[] = $parent->getMethod($methodName);
            }
            $parent = $parent->getParentClass();
        }

        foreach ($targetClass->getInterfaces() as $interface) {
            if ($interface->hasMethod($methodName)) {
                $hierarchy[] = $interface->getMethod($methodName);
            }
        }

        foreach ($targetClass->getTraits() as $trait) {
            if ($trait->hasMethod($methodName)) {
                $hierarchy[] = $trait->getMethod($methodName);
            }
        }

        return self::$methodHierarchyCache[$cacheKey] = $hierarchy;
    }

    /**
     * Builds an array of ReflectionClasses representing the complete inheritance hierarchy from child to root.
     * Recursively traverses parent classes, implemented interfaces, and used traits across all levels.
     *
     * @param ReflectionClass<object> $ref
     *
     * @return array<int, ReflectionClass<object>>
     */
    public static function getClassHierarchy(ReflectionClass $ref): array
    {
        $cacheKey = $ref->getName();
        if (isset(self::$classHierarchyCache[$cacheKey])) {
            return self::$classHierarchyCache[$cacheKey];
        }

        $hierarchy = [];
        $visited = [];

        $collect = function (ReflectionClass $class) use (&$collect, &$hierarchy, &$visited): void {
            $name = $class->getName();
            if (isset($visited[$name])) {
                return;
            }
            $visited[$name] = true;
            $hierarchy[] = $class;

            $parent = $class->getParentClass();
            if ($parent !== false) {
                $collect($parent);
            }

            foreach ($class->getInterfaces() as $interface) {
                $collect($interface);
            }

            foreach ($class->getTraits() as $trait) {
                $collect($trait);
            }
        };

        $collect($ref);

        return self::$classHierarchyCache[$cacheKey] = $hierarchy;
    }
}
