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
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use TypePHP\Contract\HierarchyResolver;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use WeakMap;

/**
 * @internal Manages generic template bindings for object instances (via WeakMap) and static call stack frames.
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
     * Temporary storage for an original object instance being cloned.
     */
    public static ?object $pendingCloneSource = null;

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
        if ($thisObj !== null) {
            if (self::$pendingCloneSource !== null && ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::copyInstanceBindings(self::$pendingCloneSource, $thisObj);
            }

            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            if (isset(self::$instanceTemplateBindings[$thisObj])) {
                return self::$instanceTemplateBindings[$thisObj];
            }
        }

        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);

            return $topFrame !== false ? $topFrame : [];
        }

        return [];
    }

    /**
     * Retrieves all bound template TypeNodes for a specific object instance.
     *
     * @return array<string, TypeNode>
     */
    public static function getBoundTemplatesForInstance(object $instance): array
    {
        if (self::$pendingCloneSource !== null && ! isset(self::$instanceTemplateBindings[$instance])) {
            self::copyInstanceBindings(self::$pendingCloneSource, $instance);
        }

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
            $ref = new \ReflectionClass($className);
            $classDoc = $ref->getDocComment();

            if ($classDoc !== false) {
                [$phpDocParser, $lexer] = self::getPhpDocParserComponents();

                $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                $classPhpDocNode = $phpDocParser->parse($classTokens);

                $variances = [];
                foreach ($classPhpDocNode->getTags() as $tagNode) {
                    if ($tagNode->value instanceof TemplateTagValueNode) {
                        $tagName = strtolower($tagNode->name);

                        if (str_contains($tagName, 'covariant')) {
                            $variances[$tagNode->value->name] = 'covariant';
                        } elseif (str_contains($tagName, 'contravariant')) {
                            $variances[$tagNode->value->name] = 'contravariant';
                        } else {
                            $variances[$tagNode->value->name] = 'invariant';
                        }
                    }
                }

                return $variances;
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
        if ($thisObj !== null) {
            if (self::$pendingCloneSource !== null && ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::copyInstanceBindings(self::$pendingCloneSource, $thisObj);
            }

            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            return isset(self::$instanceTemplateBindings[$thisObj][$templateName]);
        }

        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);

            return isset($topFrame[$templateName]);
        }

        return false;
    }

    /**
     * Retrieves the bound TypeNode for a template name from instance or call stack context.
     */
    public static function getBoundType(string $function, ?object $thisObj, string $templateName): ?TypeNode
    {
        if ($thisObj !== null) {
            if (self::$pendingCloneSource !== null && ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::copyInstanceBindings(self::$pendingCloneSource, $thisObj);
            }

            if (self::$instanceTemplateBindings === null || ! isset(self::$instanceTemplateBindings[$thisObj])) {
                self::resolveInheritedTemplates($thisObj, \get_class($thisObj));
            }

            return self::$instanceTemplateBindings[$thisObj][$templateName] ?? null;
        }

        if (self::hasCallFrame($function)) {
            $topFrame = end(self::$callStackBindings[$function]);

            return $topFrame[$templateName] ?? null;
        }

        return null;
    }

    /**
     * Binds an inferred TypeNode to a template parameter for an instance or call stack frame.
     */
    public static function bindTemplate(string $function, ?object $thisObj, string $templateName, TypeNode $inferredType): void
    {
        if ($thisObj !== null) {
            if (self::$instanceTemplateBindings === null) {
                self::$instanceTemplateBindings = new WeakMap();
            }
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

        if (! is_a($instance, $className)) {
            return null;
        }

        if (! ClassNameValidator::isValid($className) || (! class_exists($className) && ! interface_exists($className) && ! trait_exists($className))) {
            return null;
        }

        self::resolveInheritedTemplates($instance, $className);

        try {
            $ref = new \ReflectionClass($className);
            $classHierarchy = HierarchyResolver::getClassHierarchy($ref);

            [$phpDocParser, $lexer] = self::getPhpDocParserComponents();

            $templates = [];
            $classVariances = [];

            // Collect template parameters across the entire class/interface hierarchy!
            foreach ($classHierarchy as $hierClass) {
                $classDoc = $hierClass->getDocComment();
                if ($classDoc !== false) {
                    $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                    $classPhpDocNode = $phpDocParser->parse($classTokens);

                    foreach ($classPhpDocNode->getTags() as $tagNode) {
                        if ($tagNode->value instanceof TemplateTagValueNode) {
                            $tName = $tagNode->value->name;
                            if (! isset($templates[$tName])) {
                                $templates[$tName] = $tagNode->value;
                                $tagName = strtolower($tagNode->name);

                                if (str_contains($tagName, 'covariant')) {
                                    $classVariances[$tName] = GenericTypeNode::VARIANCE_COVARIANT;
                                } elseif (str_contains($tagName, 'contravariant')) {
                                    $classVariances[$tName] = GenericTypeNode::VARIANCE_CONTRAVARIANT;
                                } else {
                                    $classVariances[$tName] = GenericTypeNode::VARIANCE_INVARIANT;
                                }
                            }
                        }
                    }
                }
            }

            if (self::$instanceTemplateBindings === null) {
                self::$instanceTemplateBindings = new WeakMap();
            }

            $templateList = array_values($templates);

            foreach ($templateList as $index => $templateTag) {
                if (isset($typeNode->genericTypes[$index])) {
                    $expectedTypeNode = $typeNode->genericTypes[$index];

                    $usageVariance = $typeNode->variances[$index] ?? GenericTypeNode::VARIANCE_INVARIANT;
                    $declaredVariance = $classVariances[$templateTag->name] ?? GenericTypeNode::VARIANCE_INVARIANT;

                    $variance = ($usageVariance !== GenericTypeNode::VARIANCE_INVARIANT)
                        ? $usageVariance
                        : $declaredVariance;

                    $templateName = $templateTag->name;
                    $existingBindings = self::$instanceTemplateBindings[$instance] ?? [];

                    if (isset($existingBindings[$templateName])) {
                        $existingTypeNode = $existingBindings[$templateName];

                        $valid = self::checkVariance($existingTypeNode, $expectedTypeNode, $variance);

                        if (! $valid) {
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
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection or parsing errors
        }

        return null;
    }

    /**
     * Resolves and binds parent class (@extends) and interface (@implements) template mappings.
     */
    public static function resolveInheritedTemplates(object $instance, string $targetClassName): void
    {
        $actualClassName = \get_class($instance);

        try {
            $ref = new \ReflectionClass($actualClassName);
            $classHierarchy = HierarchyResolver::getClassHierarchy($ref);

            [$phpDocParser, $lexer] = self::getPhpDocParserComponents();

            foreach ($classHierarchy as $hierClass) {
                $classDoc = $hierClass->getDocComment();

                if ($classDoc !== false) {
                    $classTokens = new TokenIterator($lexer->tokenize($classDoc));
                    $classPhpDocNode = $phpDocParser->parse($classTokens);

                    $declaredTemplateNames = [];
                    foreach ($classPhpDocNode->getTags() as $tag) {
                        if ($tag->value instanceof TemplateTagValueNode) {
                            $declaredTemplateNames[$tag->value->name] = true;
                        }
                    }

                    $inheritedTags = array_merge(
                        $classPhpDocNode->getExtendsTagValues(),
                        $classPhpDocNode->getImplementsTagValues()
                    );

                    foreach ($inheritedTags as $inheritedTag) {
                        $genericTypeNode = $inheritedTag->type;
                        if ($genericTypeNode instanceof GenericTypeNode) {
                            $parentName = SpecialTypeResolver::resolveFqcn($genericTypeNode->type->name, $hierClass);

                            if (ClassNameValidator::isValid($parentName) && is_a($actualClassName, $parentName, true)) {
                                if (! class_exists($parentName) && ! interface_exists($parentName)) {
                                    continue;
                                }

                                $parentRef = new \ReflectionClass($parentName);
                                $parentDoc = $parentRef->getDocComment();

                                if ($parentDoc !== false) {
                                    $parentTokens = new TokenIterator($lexer->tokenize($parentDoc));
                                    $parentPhpDocNode = $phpDocParser->parse($parentTokens);

                                    $parentTemplateNames = [];
                                    foreach ($parentPhpDocNode->getTags() as $tag) {
                                        if ($tag->value instanceof TemplateTagValueNode) {
                                            $parentTemplateNames[] = $tag->value->name;
                                        }
                                    }

                                    $bindings = self::$instanceTemplateBindings[$instance] ?? [];
                                    foreach ($parentTemplateNames as $idx => $templateName) {
                                        if (isset($genericTypeNode->genericTypes[$idx])) {
                                            $resolved = self::resolveTypeNodeAst($genericTypeNode->genericTypes[$idx], $hierClass);

                                            if ($resolved instanceof IdentifierTypeNode && isset($declaredTemplateNames[$resolved->name])) {
                                                continue;
                                            }

                                            $bindings[$templateName] = $resolved;
                                        }
                                    }

                                    if (self::$instanceTemplateBindings === null) {
                                        self::$instanceTemplateBindings = new WeakMap();
                                    }
                                    self::$instanceTemplateBindings[$instance] = $bindings;
                                }
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Silently ignore reflection or parsing errors
        }
    }

    /**
     * Recursively checks if an existing type node satisfies an expected type node under a given variance modifier.
     */
    public static function checkVariance(TypeNode $existing, TypeNode $expected, string $variance): bool
    {
        $existingStr = (string) $existing;
        $expectedStr = (string) $expected;

        if ($existingStr === $expectedStr) {
            return true;
        }

        if ($variance === GenericTypeNode::VARIANCE_BIVARIANT || $expectedStr === 'mixed') {
            return true;
        }

        if ($expected instanceof UnionTypeNode) {
            if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
                foreach ($expected->types as $unionVariant) {
                    if (self::checkVariance($existing, $unionVariant, $variance)) {
                        return true;
                    }
                }

                return false;
            }

            if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
                foreach ($expected->types as $unionVariant) {
                    if (! self::checkVariance($existing, $unionVariant, $variance)) {
                        return false;
                    }
                }

                return true;
            }
        }

        if ($existing instanceof UnionTypeNode) {
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
        }

        if ($expected instanceof IntersectionTypeNode) {
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

                return false;
            }
        }

        if ($existing instanceof IntersectionTypeNode) {
            if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
                foreach ($existing->types as $existingMember) {
                    if (self::checkVariance($existingMember, $expected, $variance)) {
                        return true;
                    }
                }

                return false;
            }

            if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
                foreach ($existing->types as $existingMember) {
                    if (! self::checkVariance($existingMember, $expected, $variance)) {
                        return false;
                    }
                }

                return true;
            }
        }

        if ($existing instanceof GenericTypeNode && $expected instanceof GenericTypeNode) {
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

        $isSubclass = function (string $sub, string $super): bool {
            if (ClassNameValidator::isValid($sub) && ClassNameValidator::isValid($super) && (class_exists($sub) || interface_exists($sub)) && (class_exists($super) || interface_exists($super))) {
                return is_a($sub, $super, true);
            }

            return false;
        };

        if ($variance === GenericTypeNode::VARIANCE_COVARIANT) {
            return $isSubclass($existingStr, $expectedStr);
        }

        if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
            return $isSubclass($expectedStr, $existingStr);
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
     * Returns shared static instances of PHPStan's PhpDocParser and Lexer.
     *
     * @return array{PhpDocParser, Lexer}
     */
    private static function getPhpDocParserComponents(): array
    {
        /** @var PhpDocParser|null $phpDocParser */
        static $phpDocParser = null;
        /** @var Lexer|null $lexer */
        static $lexer = null;

        if ($phpDocParser === null || $lexer === null) {
            $config = new ParserConfig(usedAttributes: []);
            $lexer = new Lexer($config);
            $constExprParser = new ConstExprParser($config);
            $typeParser = new TypeParser($config, $constExprParser);
            $phpDocParser = new PhpDocParser($config, $typeParser, $constExprParser);
        }

        return [$phpDocParser, $lexer];
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
