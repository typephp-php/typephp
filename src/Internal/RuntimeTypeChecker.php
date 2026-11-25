<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use Traversable;
use TypePHP\Internal\Ast\ScopeCleaner;
use TypePHP\Internal\Checker\GeneratorChecker;
use TypePHP\Internal\Checker\InlineChecker;
use TypePHP\Internal\Checker\MemberAssignChecker;
use TypePHP\Internal\Checker\ParamChecker;
use TypePHP\Internal\Checker\ParamOutChecker;
use TypePHP\Internal\Checker\ReturnChecker;
use TypePHP\Internal\Checker\SelfOutChecker;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockExtractor;
use TypePHP\Internal\Docblock\DocblockNormalizer;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Reporting\ViolationCollector;
use TypePHP\Internal\Resolver\CallerBoundaryResolver;
use TypePHP\Internal\Resolver\HierarchyResolver;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Util\IgnoreManager;
use TypePHP\Internal\Validator\TypeValidatorRegistry;
use TypePHP\Internal\Wrapper\CallableWrapper;
use TypePHP\Internal\Wrapper\IterableWrapper;

/**
 * Core runtime type checking engine facade for parameter validation, return type enforcement, and variable tracking.
 */
final class RuntimeTypeChecker
{
    private const PARAM_CHECK_FALLBACK = 0;

    private const PARAM_CHECK_INT = 1;

    private const PARAM_CHECK_STRING = 2;

    private const PARAM_CHECK_POSITIVE_INT = 3;

    private const PARAM_CHECK_NON_EMPTY_STRING = 4;

    private const PARAM_CHECK_BOOL = 5;

    private const PARAM_CHECK_FLOAT = 6;

    private const PARAM_CHECK_ARRAY_KEY = 7;

    private const PARAM_CHECK_ARRAY = 8;

    private const PARAM_CHECK_LIST = 9;

    private const PARAM_CHECK_NON_NEGATIVE_INT = 10;

    private const PARAM_CHECK_NEGATIVE_INT = 11;

    private const PARAM_CHECK_NON_POSITIVE_INT = 12;

    private const PARAM_CHECK_NON_ZERO_INT = 13;

    private const PARAM_CHECK_MIXED = 14;

    private const PARAM_CHECK_NULL = 15;

    private const PARAM_CHECK_TRUE = 16;

    private const PARAM_CHECK_FALSE = 17;

    private const PARAM_CHECK_SCALAR = 18;

    private const PARAM_CHECK_OBJECT = 19;

    private const PARAM_CHECK_CLASS = 20;

    private const PARAM_CHECK_NULL_OR_INT = 21;

    private const PARAM_CHECK_NULL_OR_STRING = 22;

    private const PARAM_CHECK_NULL_OR_BOOL = 23;

    private const PARAM_CHECK_REQUIRE_NULL = 24;

    private const PARAM_CHECK_BIND_TEMPLATE = 25;

    private const PARAM_CHECK_INT_OR_FLOAT = 26;

    public static bool $isEnabled = true;

    public static bool $paramsEnabled = true;

    public static bool $returnsEnabled = true;

    public static bool $paramsOutEnabled = true;

    public static bool $selfOutEnabled = true;

    public static bool $inlinePropertiesEnabled = true;

    public static bool $arrayValidationHybrid = false;

    private static ?TypeValidatorRegistry $registry = null;

    /**
     * @var array<string, array{class: ?string, requireObject: bool, function: string, items: list<array{0: string, 1: int, 2: TypeNode, 3: bool, 4: ?string, 5: bool}>}>
     */
    private static array $simpleScopePlanCache = [];

    /**
     * @var array<string, array{class: string, items: list<array{0: string, 1: string, 2: int}>}>
     */
    private static array $simpleCtorTemplatePlanCache = [];

    /**
     * @var array<string, array{class: ?string, type: TypeNode|false, op: int, extra: ?string}>
     */
    private static array $simpleReturnPlanCache = [];

    /**
     * @var array<string, bool>
     */
    public static array $hasMethodTemplatesCache = [];

    /**
     * 2D Cache for checked static properties: [$className][$propName] => true.
     *
     * @var array<string, array<string, true>>
     */
    private static array $checkedStaticProperties = [];

    /**
     * @var array<string, array{class: string, templateName: string}>
     */
    private static array $genericReturnPlanCache = [];

    /**
     * @var array<string, array<string, array{class: ?string, requireObject: bool, function: string, items: list<array{0: string, 1: int, 2: TypeNode, 3: bool, 4: ?string, 5: bool}>}>>
     */
    private static array $traitScopePlanCache = [];

    /**
     * @var array<string, array<string, array{function: string, type: TypeNode|false, op: int, extra: ?string}>>
     */
    private static array $traitReturnPlanCache = [];

    /**
     * @var array<string, true>
     */
    private static array $nullableReturnCache = [];

    /**
     * @var array<string, array<string, true>>
     */
    private static array $traitNullableReturnCache = [];

    /**
     * Compiled fast-path for methods with a single class-string<T> method template (bound null or object)
     * and optional simple scalar/bool parameters (e.g. HasAttributes::getAttribute/getAttributes).
     *
     * @var array<string, array<string, array{effective: string, paramName: string, templateName: string, otherParams: list<array{0: string, 1: int, 2: TypeNode, 3: bool}>}>>
     */
    private static array $simpleClassStringTemplatePlanCache = [];

    /**
     * @var array<string, true>
     */
    private static array $identitySelfReturnCache = [];

    /**
     * @var array<string, array<string, true>>
     */
    private static array $traitIdentitySelfReturnCache = [];

    /**
     * Compiled fast-path for standalone generic collection functions (e.g. Arr\to_array, Arr\values, Arr\keys, Arr\first).
     *
     * @var array<string, array{
     *     primaryParam: string,
     *     keyTName: ?string,
     *     valTName: ?string,
     *     needsKey: bool,
     *     needsVal: bool,
     *     otherParams: list<array{0: string, 1: int, 2: ?string}>
     * }>
     */
    private static array $simpleGenericIterablePlanCache = [];

    private static ?IdentifierTypeNode $arrayKeyNode = null;

    /**
     * Resets runtime caches.
     */
    public static function reset(): void
    {
        self::$traitScopePlanCache = [];
        self::$traitReturnPlanCache = [];
        self::$nullableReturnCache = [];
        self::$traitNullableReturnCache = [];
        self::$simpleClassStringTemplatePlanCache = [];
        self::$identitySelfReturnCache = [];
        self::$traitIdentitySelfReturnCache = [];
        self::$simpleGenericIterablePlanCache = [];
        self::$simpleScopePlanCache = [];
        self::$simpleCtorTemplatePlanCache = [];
        self::$simpleReturnPlanCache = [];
        self::$genericReturnPlanCache = [];
        self::$hasMethodTemplatesCache = [];
        self::$checkedStaticProperties = [];
        IgnoreManager::reset();
        CallerBoundaryResolver::reset();
        ParamOutChecker::reset();
        SelfOutChecker::reset();
        GeneratorChecker::reset();
        ViolationCollector::reset();
        CallableWrapper::reset();
        IterableWrapper::reset();
    }

    /**
     * Returns whether TypePHP is globally enabled in configuration.
     */
    public static function isEnabled(): bool
    {
        return Config::isEnabled();
    }

    /**
     * Validates non-public (protected/private) class constants via reflection without triggering visibility errors.
     *
     * @param class-string $className
     */
    public static function checkClassConstant(
        string $className,
        string $constName,
        string $typeString,
        string $file = '',
        ?int $line = null
    ): mixed {
        if (! self::$isEnabled || ! class_exists($className, false)) {
            return null;
        }

        try {
            $refConst = new \ReflectionClassConstant($className, $constName);
            $value = $refConst->getValue();
        } catch (\Throwable) {
            return null;
        }

        return self::checkConstant($className . '::' . $constName, $value, $typeString, $file, $line);
    }

    /**
     * Validates class-level and global constants against declared @var contracts upon declaration/load.
     */
    public static function checkConstant(
        string $constantName,
        mixed $value,
        string $typeString,
        string $file = '',
        ?int $line = null
    ): mixed {
        if (! self::$isEnabled) {
            return $value;
        }

        try {
            $typeNode = self::resolveConstantTypeNode($typeString, $constantName, $file);
        } catch (\Throwable $e) {
            return $value;
        }

        $context = "Constant {$constantName}";

        $err = self::getRegistry()->validate($value, $typeNode, $context);
        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            $target = str_contains($constantName, '::')
                ? explode('::', $constantName, 2)[1]
                : $constantName;
            $declaredIn = ($file !== '' && $line !== null) ? "{$file}:{$line}" : null;

            return ViolationCollector::handle($err, 'constant', $value, $file, $line, $constantName, $target, $declaredIn);
        }

        return $value;
    }

    /**
     * Validates an argument passed into a constant closure against its parameter contract.
     */
    public static function checkConstantClosureParam(
        string $caller,
        string $paramName,
        mixed $value,
        string $typeString,
        string $file = '',
        ?int $line = null
    ): mixed {
        if (! self::$isEnabled || ! self::$paramsEnabled) {
            return $value;
        }

        try {
            $typeNode = self::resolveConstantTypeNode($typeString, $caller, $file);
        } catch (\Throwable $e) {
            return $value;
        }

        $context = "{$caller}(): Argument \${$paramName}";

        $err = self::getRegistry()->validate($value, $typeNode, $context);
        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            $declaredIn = ($file !== '' && $line !== null) ? "{$file}:{$line}" : null;

            return ViolationCollector::handle($err, 'parameter', $value, null, null, $caller, '$' . $paramName, $declaredIn);
        }

        return $value;
    }

    /**
     * Validates a return value from a constant closure against its return type contract.
     */
    public static function checkConstantClosureReturn(
        string $caller,
        mixed $value,
        string $typeString,
        string $file = '',
        ?int $line = null
    ): mixed {
        if (! self::$isEnabled || ! self::$returnsEnabled) {
            return $value;
        }

        try {
            $typeNode = self::resolveConstantTypeNode($typeString, $caller, $file);
        } catch (\Throwable $e) {
            return $value;
        }

        $context = "{$caller}(): Return value";

        $err = self::getRegistry()->validate($value, $typeNode, $context);
        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            $declaredIn = ($file !== '' && $line !== null) ? "{$file}:{$line}" : null;

            return ViolationCollector::handle($err, 'return', $value, $file, $line, $caller, 'return', $declaredIn);
        }

        return $value;
    }

    /**
     * Parses a constant's type string, expanding class-level aliases and resolving self/static/parent
     * when the target denotes a class constant, or falling back to file imports for global constants.
     */
    private static function resolveConstantTypeNode(string $typeString, string $targetName, string $file): TypeNode
    {
        $normalized = DocblockNormalizer::normalize($typeString);
        [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
        $tokens = new TokenIterator($lexer->tokenize($normalized));
        $typeNode = $typeParser->parse($tokens);

        if (str_contains($targetName, '::')) {
            $declaringClass = explode('::', $targetName, 2)[0];
            if (
                $declaringClass !== ''
                && ClassNameValidator::isValid($declaringClass)
                && (
                    class_exists($declaringClass, false)
                    || interface_exists($declaringClass, false)
                    || trait_exists($declaringClass, false)
                    || enum_exists($declaringClass, false)
                    || class_exists($declaringClass)
                    || interface_exists($declaringClass)
                    || trait_exists($declaringClass)
                    || enum_exists($declaringClass)
                )
            ) {
                $aliases = DocblockParser::parseClassAliases($declaringClass);
                if ($aliases !== []) {
                    $typeNode = DocblockParser::substituteAliases($typeNode, $aliases);
                }

                return SpecialTypeResolver::resolve($typeNode, $declaringClass);
            }
        }

        if ($file !== '') {
            $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
        }

        return $typeNode;
    }

    /**
     * Evaluates member/dimension mutation assignments on an object or static property
     * ($obj->prop[$key] = $val or Class::$prop[$key] = $val) atomically before writing.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function checkPropertyMemberAssign(
        mixed $objectOrClass,
        string $propName,
        array $chain,
        mixed $value,
        string $file
    ): mixed {
        if (! self::$isEnabled || ! self::$inlinePropertiesEnabled) {
            return $value;
        }

        $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');
        if ($className !== '' && isset(InlineChecker::$nullPropertyCache[$className][$propName])) {
            return $value;
        }

        if (
            self::$arrayValidationHybrid
            && \is_object($objectOrClass)
            && MemberAssignChecker::$fastObjectContractCache !== null
            && isset($chain[0]) && ! isset($chain[1]) && $chain[0][0] === 'dim'
        ) {
            $entry = MemberAssignChecker::$fastObjectContractCache[$objectOrClass] ?? null;
            if ($entry !== null && isset($entry[1][$propName])) {
                $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                    ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                    : null;

                if ($entry[0] === $rawBindings) {
                    $c = $entry[1][$propName]; // [0 => isList, 1 => keyOp, 2 => keyExtra, 3 => valOp, 4 => valExtra]
                    $key = $chain[0][1];

                    if (! $c[0] || $key === null) {
                        $keyOk = true;
                        if (! $c[0] && $key !== null && $c[1] !== MemberAssignChecker::OP_NONE) {
                            $kExtra = $c[2];
                            $keyOk = match ($c[1]) {
                                MemberAssignChecker::OP_INT => \is_int($key),
                                MemberAssignChecker::OP_STRING => \is_string($key),
                                MemberAssignChecker::OP_ARRAY_KEY => \is_int($key) || \is_string($key),
                                MemberAssignChecker::OP_NON_EMPTY_STRING => \is_string($key) && $key !== '',
                                MemberAssignChecker::OP_POSITIVE_INT => \is_int($key) && $key > 0,
                                MemberAssignChecker::OP_MASK => \is_int($kExtra) && (
                                    (\is_string($key) && ($kExtra & MemberAssignChecker::BIT_STRING) !== 0)
                                    || (\is_int($key) && ($kExtra & (MemberAssignChecker::BIT_INT | MemberAssignChecker::BIT_FLOAT)) !== 0)
                                    || (\is_object($key) && ($kExtra & MemberAssignChecker::BIT_OBJECT) !== 0)
                                    || (\is_bool($key) && ($kExtra & MemberAssignChecker::BIT_BOOL) !== 0)
                                    || (\is_float($key) && ($kExtra & MemberAssignChecker::BIT_FLOAT) !== 0)
                                    || (\is_array($key) && ($kExtra & MemberAssignChecker::BIT_ARRAY) !== 0)
                                ),
                                MemberAssignChecker::OP_OBJECT => \is_object($key),
                                MemberAssignChecker::OP_CLASS => \is_object($key) && \is_string($kExtra) && ($key instanceof $kExtra),
                                default => false,
                            };
                        }

                        if ($keyOk) {
                            $vOp = $c[3];
                            if ($vOp === MemberAssignChecker::OP_NONE) {
                                return $value;
                            }

                            $vExtra = $c[4];
                            $valOk = match ($vOp) {
                                MemberAssignChecker::OP_INT => \is_int($value),
                                MemberAssignChecker::OP_STRING => \is_string($value),
                                MemberAssignChecker::OP_BOOL => \is_bool($value),
                                MemberAssignChecker::OP_FLOAT => \is_float($value) || \is_int($value),
                                MemberAssignChecker::OP_POSITIVE_INT => \is_int($value) && $value > 0,
                                MemberAssignChecker::OP_NON_EMPTY_STRING => \is_string($value) && $value !== '',
                                MemberAssignChecker::OP_ARRAY_KEY => \is_int($value) || \is_string($value),
                                MemberAssignChecker::OP_ARRAY => \is_array($value),
                                MemberAssignChecker::OP_OBJECT => \is_object($value),
                                MemberAssignChecker::OP_CLASS => \is_object($value) && \is_string($vExtra) && ($value instanceof $vExtra),
                                MemberAssignChecker::OP_MASK => \is_int($vExtra) && (
                                    (\is_string($value) && ($vExtra & MemberAssignChecker::BIT_STRING) !== 0)
                                    || (\is_int($value) && ($vExtra & (MemberAssignChecker::BIT_INT | MemberAssignChecker::BIT_FLOAT)) !== 0)
                                    || (\is_object($value) && ($vExtra & MemberAssignChecker::BIT_OBJECT) !== 0)
                                    || (\is_bool($value) && ($vExtra & MemberAssignChecker::BIT_BOOL) !== 0)
                                    || (\is_float($value) && ($vExtra & MemberAssignChecker::BIT_FLOAT) !== 0)
                                    || ($value === null && ($vExtra & MemberAssignChecker::BIT_NULL) !== 0)
                                    || (\is_array($value) && ($vExtra & MemberAssignChecker::BIT_ARRAY) !== 0)
                                ),
                                default => false,
                            };

                            if ($valOk) {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        $res = MemberAssignChecker::checkProperty(
            $objectOrClass,
            $propName,
            $chain,
            $value,
            $file,
            self::$registry
        );

        if ($res instanceof ErrorMessage) {
            $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($className . '::$' . $propName)) {
                return $value;
            }

            return ViolationCollector::handle($res, 'property', $value, $file, null, $className . '::$' . $propName, '$' . $propName);
        }

        return $res;
    }

    /**
     * Evaluates member mutation assignments ($arr[$key] = $val or $obj->prop = $val)
     * against the root variable's type contract atomically before writing.
     *
     * @param list<array{0: 'dim'|'prop', 1: mixed}> $chain
     */
    public static function checkMemberAssign(
        mixed $root,
        array $chain,
        mixed $value,
        string $typeString,
        string $varName,
        string $file,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): mixed {
        if (! self::$isEnabled) {
            return $value;
        }

        $res = MemberAssignChecker::check(
            $root,
            $chain,
            $value,
            $typeString,
            $varName,
            $file,
            self::getRegistry(),
            $caller,
            $thisOrClass
        );

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($caller ?? '')) {
                return $value;
            }

            return ViolationCollector::handle($res, 'variable', $value, $file, null, $caller, '$' . $varName);
        }

        return $res;
    }

    /**
     * Evaluates member unset mutations (unset($arr[$key]) or unset($obj->prop))
     * against the root variable's type contract atomically before unsetting.
     *
     * @param list<list<array{0: 'dim'|'prop', 1: mixed}>> $chains
     */
    public static function checkMemberUnset(
        mixed $root,
        array $chains,
        string $typeString,
        string $varName,
        string $file,
        int $line = -1,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): ?ErrorMessage {
        if (! self::$isEnabled) {
            return null;
        }

        $res = MemberAssignChecker::checkUnset(
            $root,
            $chains,
            $typeString,
            $varName,
            $file,
            self::getRegistry(),
            $caller,
            $thisOrClass
        );

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($caller ?? '')) {
                return null;
            }

            /** @var ErrorMessage|null $handled */
            $handled = ViolationCollector::handle($res, 'variable', null, $file, $line > 0 ? $line : null, $caller, '$' . $varName);

            return $handled;
        }

        return null;
    }

    /**
     * Evaluates member unset mutations on an object or static property
     * (unset($obj->prop[$key]) or unset(Class::$prop[$key])) atomically before unsetting.
     *
     * @param list<list<array{0: 'dim'|'prop', 1: mixed}>> $chains
     */
    public static function checkPropertyMemberUnset(
        mixed $objectOrClass,
        string $propName,
        array $chains,
        string $file,
        int $line = -1
    ): ?ErrorMessage {
        if (! self::$isEnabled || ! self::$inlinePropertiesEnabled) {
            return null;
        }

        $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');
        if ($className !== '' && isset(InlineChecker::$nullPropertyCache[$className][$propName])) {
            return null;
        }

        $res = MemberAssignChecker::checkPropertyUnset(
            $objectOrClass,
            $propName,
            $chains,
            $file,
            self::$registry
        );

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($className . '::$' . $propName)) {
                return null;
            }

            /** @var ErrorMessage|null $handled */
            $handled = ViolationCollector::handle($res, 'property', null, $file, $line > 0 ? $line : null, $className . '::$' . $propName, '$' . $propName);

            return $handled;
        }

        return null;
    }

    /**
     * Fast-path for 1-level property dimension assignments ($obj->prop[$key] = $val or $obj->prop[] = $val),
     * avoiding per-write [['dim', $key]] array allocations.
     */
    public static function checkPropertyDimAssign(
        mixed $objectOrClass,
        string $propName,
        mixed $key,
        mixed $value,
        string $file
    ): mixed {
        if (! self::$isEnabled || ! self::$inlinePropertiesEnabled) {
            return $value;
        }

        $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');
        if ($className !== '' && isset(InlineChecker::$nullPropertyCache[$className][$propName])) {
            return $value;
        }

        if (
            self::$arrayValidationHybrid
            && \is_object($objectOrClass)
            && MemberAssignChecker::$fastObjectContractCache !== null
        ) {
            $entry = MemberAssignChecker::$fastObjectContractCache[$objectOrClass] ?? null;
            if ($entry !== null && isset($entry[1][$propName])) {
                $rawBindings = TemplateManager::$instanceTemplateBindings !== null
                    ? (TemplateManager::$instanceTemplateBindings[$objectOrClass] ?? null)
                    : null;

                if ($entry[0] === $rawBindings) {
                    $c = $entry[1][$propName];

                    if (! $c[0] || $key === null) {
                        $keyOk = true;
                        if (! $c[0] && $key !== null && $c[1] !== MemberAssignChecker::OP_NONE) {
                            $kExtra = $c[2];
                            $keyOk = match ($c[1]) {
                                MemberAssignChecker::OP_INT => \is_int($key),
                                MemberAssignChecker::OP_STRING => \is_string($key),
                                MemberAssignChecker::OP_ARRAY_KEY => \is_int($key) || \is_string($key),
                                MemberAssignChecker::OP_NON_EMPTY_STRING => \is_string($key) && $key !== '',
                                MemberAssignChecker::OP_POSITIVE_INT => \is_int($key) && $key > 0,
                                MemberAssignChecker::OP_MASK => \is_int($kExtra) && (
                                    (\is_string($key) && ($kExtra & MemberAssignChecker::BIT_STRING) !== 0)
                                    || (\is_int($key) && ($kExtra & (MemberAssignChecker::BIT_INT | MemberAssignChecker::BIT_FLOAT)) !== 0)
                                    || (\is_object($key) && ($kExtra & MemberAssignChecker::BIT_OBJECT) !== 0)
                                    || (\is_bool($key) && ($kExtra & MemberAssignChecker::BIT_BOOL) !== 0)
                                    || (\is_float($key) && ($kExtra & MemberAssignChecker::BIT_FLOAT) !== 0)
                                    || (\is_array($key) && ($kExtra & MemberAssignChecker::BIT_ARRAY) !== 0)
                                ),
                                MemberAssignChecker::OP_OBJECT => \is_object($key),
                                MemberAssignChecker::OP_CLASS => \is_object($key) && \is_string($kExtra) && ($key instanceof $kExtra),
                                default => false,
                            };
                        }

                        if ($keyOk) {
                            $vOp = $c[3];
                            if ($vOp === MemberAssignChecker::OP_NONE) {
                                return $value;
                            }

                            $vExtra = $c[4];
                            $valOk = match ($vOp) {
                                MemberAssignChecker::OP_INT => \is_int($value),
                                MemberAssignChecker::OP_STRING => \is_string($value),
                                MemberAssignChecker::OP_BOOL => \is_bool($value),
                                MemberAssignChecker::OP_FLOAT => \is_float($value) || \is_int($value),
                                MemberAssignChecker::OP_POSITIVE_INT => \is_int($value) && $value > 0,
                                MemberAssignChecker::OP_NON_EMPTY_STRING => \is_string($value) && $value !== '',
                                MemberAssignChecker::OP_ARRAY_KEY => \is_int($value) || \is_string($value),
                                MemberAssignChecker::OP_ARRAY => \is_array($value),
                                MemberAssignChecker::OP_OBJECT => \is_object($value),
                                MemberAssignChecker::OP_CLASS => \is_object($value) && \is_string($vExtra) && ($value instanceof $vExtra),
                                MemberAssignChecker::OP_MASK => \is_int($vExtra) && (
                                    (\is_string($value) && ($vExtra & MemberAssignChecker::BIT_STRING) !== 0)
                                    || (\is_int($value) && ($vExtra & (MemberAssignChecker::BIT_INT | MemberAssignChecker::BIT_FLOAT)) !== 0)
                                    || (\is_object($value) && ($vExtra & MemberAssignChecker::BIT_OBJECT) !== 0)
                                    || (\is_bool($value) && ($vExtra & MemberAssignChecker::BIT_BOOL) !== 0)
                                    || (\is_float($value) && ($vExtra & MemberAssignChecker::BIT_FLOAT) !== 0)
                                    || ($value === null && ($vExtra & MemberAssignChecker::BIT_NULL) !== 0)
                                    || (\is_array($value) && ($vExtra & MemberAssignChecker::BIT_ARRAY) !== 0)
                                ),
                                default => false,
                            };

                            if ($valOk) {
                                return $value;
                            }
                        }
                    }
                }
            }
        }

        return self::checkPropertyMemberAssign($objectOrClass, $propName, [['dim', $key]], $value, $file);
    }

    /**
     * Pre-binds generic template state on a class before its constructor executes.
     *
     * @param \Closure(mixed ...$args): mixed $factory
     */
    public static function withPendingGeneric(string $typeString, \Closure $factory, string $file = '', mixed ...$args): mixed
    {
        if (! self::$isEnabled || ! Config::isInlineGenericsEnabled()) {
            return $factory(...$args);
        }

        TemplateManager::pushPendingInstantiation($typeString, $file);

        try {
            $result = $factory(...$args);
            if (\is_object($result)) {
                TemplateManager::applyPendingInstantiationIfUnconsumed($result);
            }

            return $result;
        } finally {
            TemplateManager::popPendingInstantiation();
        }
    }

    /**
     * Evaluates inline property validation dynamically based on configuration with zero-allocation 2D caching.
     */
    public static function checkStaticProperty(
        mixed $objectOrClass,
        string $propName,
        mixed $value,
        string $file = '',
        ?int $line = null
    ): mixed {
        if (! self::$isEnabled || ! self::$inlinePropertiesEnabled) {
            return $value;
        }

        $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');

        if ($className !== '' && isset(self::$checkedStaticProperties[$className][$propName])) {
            return $value;
        }

        $res = self::checkProperty($value, $objectOrClass, $propName, $file);
        if ($res instanceof ErrorMessage) {
            $typeError = new \TypePHP\Exception\TypeError($res->getMessage());

            throw ErrorFactory::prepareException($typeError, $line, $file !== '' ? $file : null);
        }

        if ($className !== '') {
            self::$checkedStaticProperties[$className][$propName] = true;
        }

        return $value;
    }

    /**
     * Delegates generic template binding for class instances.
     */
    public static function bindInstanceFromNode(object $instance, GenericTypeNode $typeNode, string $context = '', bool $forceBind = false): ?ErrorMessage
    {
        if (! self::$isEnabled) {
            return null;
        }

        $err = TemplateManager::bindInstanceFromNode($instance, $typeNode, $context, $forceBind);

        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($context)) {
                return null;
            }

            $kind = 'parameter';
            if (str_contains($context, 'Return value')) {
                $kind = 'return';
            } elseif (str_contains($context, 'Property ')) {
                $kind = 'property';
            } elseif (str_contains($context, 'Variable ')) {
                $kind = 'variable';
            }

            /** @var ErrorMessage|null $handled */
            $handled = ViolationCollector::handle($err, $kind, null, function: $context);

            return $handled;
        }

        return null;
    }

    /**
     * Evaluates inline variable validation dynamically based on configuration.
     */
    public static function checkVariable(
        mixed $value,
        string $typeString,
        string $varName,
        string $file,
        ?string $caller = null,
        mixed $thisOrClass = null
    ): mixed {
        if (! self::$isEnabled) {
            return $value;
        }

        $res = InlineChecker::checkVariable(
            $value,
            $typeString,
            $varName,
            $file,
            self::getRegistry(),
            $caller,
            $thisOrClass
        );

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($caller !== null ? $caller : '')) {
                return $value;
            }

            return ViolationCollector::handle($res, 'variable', $value, $file, null, $caller, '$' . $varName);
        }

        return $res;
    }

    /**
     * Evaluates class property validation dynamically based on configuration.
     */
    public static function checkProperty(mixed $value, mixed $objectOrClass, string $propName, string $file): mixed
    {
        $className = \is_object($objectOrClass) ? $objectOrClass::class : (\is_string($objectOrClass) ? $objectOrClass : '');
        if ($className !== '' && isset(InlineChecker::$nullPropertyCache[$className][$propName])) {
            return $value;
        }

        if (! self::$isEnabled) {
            return $value;
        }

        $res = InlineChecker::checkProperty($value, $objectOrClass, $propName, $file, self::getRegistry());

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored() || CallerBoundaryResolver::shouldBypass($className . '::$' . $propName)) {
                return $value;
            }

            return ViolationCollector::handle($res, 'property', $value, $file, null, $className . '::$' . $propName, '$' . $propName);
        }

        return $res;
    }

    /**
     * Initialises generic call frames and returns a ScopeCleaner that pops the call frame on destruction.
     *
     * @param array<string, mixed> $vars
     */
    public static function setupScope(string $function, array $vars, object|string|null $thisOrClass = null): ErrorMessage|ScopeCleaner|null
    {
        if (! self::$isEnabled || ! self::$paramsEnabled) {
            return null;
        }

        if (
            isset(self::$simpleCtorTemplatePlanCache[$function])
            && \is_object($thisOrClass)
            && ! TemplateManager::$hasPendingInstantiations
            && (TemplateManager::$instanceTemplateBindings === null || ! isset(TemplateManager::$instanceTemplateBindings[$thisOrClass]))
        ) {
            $ctorPlan = self::$simpleCtorTemplatePlanCache[$function];
            if ($thisOrClass::class === $ctorPlan['class']) {
                $bindings = [];
                $fastValid = true;

                foreach ($ctorPlan['items'] as $item) {
                    $paramName = $item[0];
                    if (isset($vars[$paramName])) {
                        $val = $vars[$paramName];
                    } elseif (\array_key_exists($paramName, $vars)) {
                        $val = null;
                    } else {
                        $fastValid = false;

                        break;
                    }

                    $mask = $item[2];
                    if ($mask !== 0) {
                        $ok = (
                            (\is_string($val) && ($mask & 2) !== 0)
                            || (\is_int($val) && ($mask & 9) !== 0)
                            || (\is_object($val) && ($mask & 16) !== 0)
                            || (\is_bool($val) && ($mask & 4) !== 0)
                            || (\is_float($val) && ($mask & 8) !== 0)
                            || ($val === null && ($mask & 32) !== 0)
                            || (\is_array($val) && ($mask & 64) !== 0)
                        );
                        if (! $ok) {
                            $fastValid = false;

                            break;
                        }
                    }

                    $tName = $item[1];
                    if (\is_string($val)) {
                        $bindings[$tName] = TemplateManager::$stringNode ??= new IdentifierTypeNode('string');
                    } elseif (\is_int($val)) {
                        $bindings[$tName] = TemplateManager::$intNode ??= new IdentifierTypeNode('int');
                    } else {
                        $bindings[$tName] = TemplateManager::inferTypeFromValue($val);
                    }
                }

                if ($fastValid) {
                    TemplateManager::$instanceTemplateBindings ??= new \WeakMap();
                    TemplateManager::$instanceTemplateBindings[$thisOrClass] = $bindings;

                    return null;
                }
            }
        }

        $classKey = \is_object($thisOrClass) ? $thisOrClass::class : (\is_string($thisOrClass) ? $thisOrClass : '');
        if (isset(self::$simpleClassStringTemplatePlanCache[$function][$classKey])) {
            $csPlan = self::$simpleClassStringTemplatePlanCache[$function][$classKey];
            $pName = $csPlan['paramName'];
            if (isset($vars[$pName])) {
                $valStr = $vars[$pName];
                if (
                    \is_string($valStr)
                    && (ClassNameValidator::$validClassStringCache[$valStr]
                        ?? ClassNameValidator::isValidClassString($valStr))
                ) {
                    $othersOk = true;
                    foreach ($csPlan['otherParams'] as $op) {
                        $opName = $op[0];
                        if (isset($vars[$opName])) {
                            $ov = $vars[$opName];
                            $ok = match ($op[1]) {
                                self::PARAM_CHECK_BOOL => \is_bool($ov),
                                self::PARAM_CHECK_INT => \is_int($ov),
                                self::PARAM_CHECK_STRING => \is_string($ov),
                                self::PARAM_CHECK_FLOAT => \is_float($ov) || \is_int($ov),
                                self::PARAM_CHECK_ARRAY => \is_array($ov),
                                self::PARAM_CHECK_POSITIVE_INT => \is_int($ov) && $ov > 0,
                                self::PARAM_CHECK_NON_EMPTY_STRING => \is_string($ov) && $ov !== '',
                                default => false,
                            };
                            if (! $ok) {
                                $othersOk = false;

                                break;
                            }
                        } elseif (\array_key_exists($opName, $vars)) {
                            $othersOk = false;

                            break;
                        }
                    }

                    if ($othersOk) {
                        $classNode = TemplateManager::$classNodeCache[$valStr] ??= new IdentifierTypeNode($valStr);
                        $eff = $csPlan['effective'];
                        $tName = $csPlan['templateName'];
                        $thisObjVal = \is_object($thisOrClass) ? $thisOrClass : null;

                        if (isset(TemplateManager::$topCallFrame[$eff])) {
                            TemplateManager::pushSingleTemplateFrame($eff, $tName, $classNode, $thisObjVal);
                        } else {
                            TemplateManager::$topCallFrame[$eff] = [$tName => $classNode];
                            TemplateManager::$topCallThis[$eff] = $thisObjVal;
                            TemplateManager::$activeCallOrder[] = $eff;
                        }

                        $cleaner = new ScopeCleaner($eff);
                        $cleaner->boundTemplates = [$tName => $classNode];

                        return $cleaner;
                    }
                }
            }
        }

        if ($thisOrClass === null && isset(self::$simpleGenericIterablePlanCache[$function])) {
            $gaPlan = self::$simpleGenericIterablePlanCache[$function];
            $pName = $gaPlan['primaryParam'];

            if (isset($vars[$pName]) && \is_array($vars[$pName])) {
                $boundTemplates = [];
                $otherOk = true;

                foreach ($gaPlan['otherParams'] as $op) {
                    $opName = $op[0];
                    if (isset($vars[$opName])) {
                        $ov = $vars[$opName];
                    } elseif (\array_key_exists($opName, $vars)) {
                        $ov = null;
                    } else {
                        continue;
                    }

                    $opKind = $op[1];
                    if ($opKind === self::PARAM_CHECK_BIND_TEMPLATE) {
                        /** @var string $btName */
                        $btName = $op[2];
                        $boundTemplates[$btName] = self::fastInferType($ov);

                        continue;
                    }

                    $ok = match ($opKind) {
                        self::PARAM_CHECK_BOOL => \is_bool($ov),
                        self::PARAM_CHECK_INT => \is_int($ov),
                        self::PARAM_CHECK_STRING => \is_string($ov),
                        self::PARAM_CHECK_FLOAT => \is_float($ov) || \is_int($ov),
                        self::PARAM_CHECK_INT_OR_FLOAT => \is_int($ov) || \is_float($ov),
                        self::PARAM_CHECK_NULL_OR_INT => $ov === null || \is_int($ov),
                        self::PARAM_CHECK_NULL_OR_STRING => $ov === null || \is_string($ov),
                        self::PARAM_CHECK_NULL_OR_BOOL => $ov === null || \is_bool($ov),
                        self::PARAM_CHECK_REQUIRE_NULL => $ov === null,
                        self::PARAM_CHECK_ARRAY_KEY => \is_int($ov) || \is_string($ov),
                        self::PARAM_CHECK_ARRAY => \is_array($ov),
                        self::PARAM_CHECK_MIXED => true,
                        default => false,
                    };

                    if (! $ok) {
                        $otherOk = false;

                        break;
                    }
                }

                if ($otherOk) {
                    $arr = $vars[$pName];
                    $count = \count($arr);

                    if ($count > 0 && ($gaPlan['needsKey'] || $gaPlan['needsVal'])) {
                        $needK = $gaPlan['needsKey'];
                        $needV = $gaPlan['needsVal'];
                        $keyMask = 0;
                        $valMask = 0;
                        /** @var array<string, TypeNode> $valTypes */
                        $valTypes = [];

                        if ($count > Config::HYBRID_SAMPLE_THRESHOLD && self::$arrayValidationHybrid) {
                            $keys = array_keys($arr);
                            $sampleKeys = [$keys[0], $keys[$count - 1]];
                            $samplesToTake = min(3, $count - 2);
                            for ($i = 0; $i < $samplesToTake; $i++) {
                                $sampleKeys[] = $keys[mt_rand(1, $count - 2)];
                            }

                            foreach ($sampleKeys as $k) {
                                if ($needK) {
                                    $keyMask |= \is_int($k) ? 1 : 2;
                                }
                                if ($needV) {
                                    self::accumulateInferredSample($arr[$k], $valMask, $valTypes);
                                }
                            }
                        } else {
                            foreach ($arr as $k => $v) {
                                if ($needK) {
                                    $keyMask |= \is_int($k) ? 1 : 2;
                                    if (! $needV && $keyMask === 3) {
                                        break;
                                    }
                                }
                                if ($needV) {
                                    self::accumulateInferredSample($v, $valMask, $valTypes);
                                }
                            }
                        }

                        if ($needK && $keyMask !== 0 && $gaPlan['keyTName'] !== null) {
                            $boundTemplates[$gaPlan['keyTName']] = match ($keyMask) {
                                1 => TemplateManager::$intNode ??= new IdentifierTypeNode('int'),
                                2 => TemplateManager::$stringNode ??= new IdentifierTypeNode('string'),
                                default => self::$arrayKeyNode ??= new IdentifierTypeNode('array-key'),
                            };
                        }

                        if ($needV && ($valMask !== 0 || $valTypes !== []) && $gaPlan['valTName'] !== null) {
                            $boundTemplates[$gaPlan['valTName']] = self::buildFastValueTypeNode($valMask, $valTypes);
                        }
                    }

                    if (isset(TemplateManager::$topCallFrame[$function])) {
                        TemplateManager::pushCallFrame($function, null);
                        TemplateManager::$topCallFrame[$function] = $boundTemplates;
                    } else {
                        TemplateManager::$topCallFrame[$function] = $boundTemplates;
                        TemplateManager::$topCallThis[$function] = null;
                        TemplateManager::$activeCallOrder[] = $function;
                    }

                    $cleaner = new ScopeCleaner($function);
                    $cleaner->boundTemplates = $boundTemplates;

                    return $cleaner;
                }
            }
        }

        $plan = self::$simpleScopePlanCache[$function] ?? null;

        if (
            $plan !== null
            && ($plan['class'] === null
                ? $thisOrClass === null
                : (\is_object($thisOrClass)
                    ? $thisOrClass::class === $plan['class']
                    : (! $plan['requireObject'] && $thisOrClass === $plan['class'])))
        ) {
            return self::executeSimpleScopePlan($plan, $vars);
        }

        if (\is_object($thisOrClass) && isset(self::$traitScopePlanCache[$function][$thisOrClass::class])) {
            return self::executeSimpleScopePlan(self::$traitScopePlanCache[$function][$thisOrClass::class], $vars);
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = $thisOrClass === null
            ? $function
            : ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if ($effectiveFunction !== $function && isset(self::$simpleScopePlanCache[$effectiveFunction])) {
            $effectivePlan = self::$simpleScopePlanCache[$effectiveFunction];
            if (! $effectivePlan['requireObject'] || $thisObj !== null) {
                return self::executeSimpleScopePlan($effectivePlan, $vars);
            }
        }

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return null;
        }

        $functionKind = ParamChecker::$functionKindCache[$effectiveFunction] ??= ParamChecker::detectFunctionKind($effectiveFunction);

        if ($thisObj !== null && $functionKind === ParamChecker::KIND_CONSTRUCT) {
            TemplateManager::applyPendingInstantiation($thisObj);
        }

        if ($functionKind === ParamChecker::KIND_MAGIC_GET) {
            return null;
        }

        $isMagicCall = ($functionKind === ParamChecker::KIND_MAGIC_CALL);

        if (
            ! $isMagicCall
            && isset(ParamChecker::$noParamContractCache[$effectiveFunction])
            && ! (self::$hasMethodTemplatesCache[$effectiveFunction] ?? false)
        ) {
            return null;
        }

        $contract = DocblockParser::parse($effectiveFunction);
        if (! $isMagicCall && ($contract['allParamsUnconstrained'] ?? false)) {
            ParamChecker::$noParamContractCache[$effectiveFunction] = true;

            return null;
        }

        $registry = self::$registry ??= new TypeValidatorRegistry();

        if (
            $functionKind === ParamChecker::KIND_CONSTRUCT
            && $thisObj !== null
            && $effectiveFunction === $function
            && ! TemplateManager::$hasPendingInstantiations
            && $contract['templates'] === []
            && $contract['classTemplates'] !== []
            && $contract['aliases'] === []
            && ! $contract['paramsAreDynamic']
            && ($contract['paramConditionals'] ?? []) === []
            && ! ($contract['hasCrossReferencedBounds'] ?? false)
            && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])
            && TemplateManager::getClassInheritedBindings($thisObj::class) === []
        ) {
            $ctorPlanItems = self::compileSimpleCtorTemplatePlanItems(
                $contract['types'],
                $contract['classTemplates'],
                $effectiveFunction
            );
            if ($ctorPlanItems !== null) {
                self::$simpleCtorTemplatePlanCache[$effectiveFunction] = [
                    'class' => $thisObj::class,
                    'items' => $ctorPlanItems,
                ];
            }
        }

        $paramsAreSimple = $contract['paramsAreSimple'] ?? $contract['isSimple'];
        if (
            ! $isMagicCall
            && $paramsAreSimple
            && ($thisObj !== null || $contract['classTemplates'] === [])
        ) {
            $hasNoConditionals = ($contract['paramConditionals'] ?? null) === null || $contract['paramConditionals'] === [];

            if ($hasNoConditionals && $functionKind !== ParamChecker::KIND_CONSTRUCT && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])) {
                $typesToValidate = $contract['constrainedTypes'] ?? $contract['types'];
                $compiledItems = self::compileSimpleScopePlanItems($typesToValidate, $contract['sensitiveParams']);
                $declaringClass = str_contains($effectiveFunction, '::') ? explode('::', $effectiveFunction, 2)[0] : null;

                $compiledPlan = [
                    'class' => $declaringClass,
                    'requireObject' => $contract['classTemplates'] !== [],
                    'function' => $effectiveFunction,
                    'items' => $compiledItems,
                ];

                self::$simpleScopePlanCache[$effectiveFunction] = $compiledPlan;
                if ($function === $effectiveFunction) {
                    self::$simpleScopePlanCache[$function] = $compiledPlan;
                } elseif ($thisObj !== null && HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                    self::$traitScopePlanCache[$function][$thisObj::class] = $compiledPlan;
                }
            }

            if ($vars !== []) {
                $err = ParamChecker::validateSimpleParams(
                    $contract['constrainedTypes'] ?? $contract['types'],
                    $vars,
                    $effectiveFunction,
                    $registry,
                    $contract['sensitiveParams'],
                    $contract['paramConditionals'] ?? null,
                    ! isset($contract['constrainedTypes'])
                );

                if ($err !== null) {
                    if (IgnoreManager::isCallerIgnored()) {
                        return null;
                    }

                    $handled = ViolationCollector::handle($err, 'parameter', null, function: $effectiveFunction);
                    if ($handled instanceof ErrorMessage) {
                        return $handled;
                    }
                }
            }

            return null;
        }

        if (
            ! $isMagicCall
            && $functionKind !== ParamChecker::KIND_CONSTRUCT
            && \count($contract['templates']) === 1
            && $contract['classTemplates'] === []
            && $contract['aliases'] === []
            && ! $contract['paramsAreDynamic']
            && ($contract['paramConditionals'] ?? []) === []
            && ! ($contract['hasCrossReferencedBounds'] ?? false)
            && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])
            && ($thisObj === null || HierarchyResolver::getTraitAliases($thisObj::class) === [])
        ) {
            $csPlan = self::compileSimpleClassStringTemplatePlan(
                $effectiveFunction,
                $contract['types'],
                $contract['templates'],
                $contract['sensitiveParams']
            );
            if ($csPlan !== null) {
                self::$simpleClassStringTemplatePlanCache[$function][$classKey] = $csPlan;
                self::$hasMethodTemplatesCache[$effectiveFunction] = true;
            }
        }

        if (
            $thisOrClass === null
            && ! $isMagicCall
            && ! str_contains($effectiveFunction, '::')
            && $contract['classTemplates'] === []
            && $contract['templates'] !== []
            && $contract['aliases'] === []
            && ! $contract['paramsAreDynamic']
            && ! $contract['hasParamOutContract']
            && ($contract['paramConditionals'] ?? []) === []
            && ! ($contract['hasCrossReferencedBounds'] ?? true)
            && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])
            && ! isset(self::$simpleGenericIterablePlanCache[$effectiveFunction])
        ) {
            $gaCompiled = self::compileSimpleGenericIterablePlan($contract);
            if ($gaCompiled !== null) {
                self::$simpleGenericIterablePlanCache[$effectiveFunction] = $gaCompiled;
            }
        }

        $err = ParamChecker::checkParams(
            $function,
            $vars,
            $thisOrClass,
            $registry,
            $effectiveFunction,
            $contract
        );

        if ($err !== null) {
            TemplateManager::popCallFrame($effectiveFunction);

            if (IgnoreManager::isCallerIgnored()) {
                return null;
            }

            $handled = ViolationCollector::handle($err, 'parameter', null, function: $effectiveFunction);
            if ($handled instanceof ErrorMessage) {
                return $handled;
            }
        }

        $hasMethodTemplates = self::$hasMethodTemplatesCache[$effectiveFunction] ?? null;
        if ($hasMethodTemplates === null) {
            $isStaticCall = ($thisObj === null);
            $hasMethodTemplates = self::$hasMethodTemplatesCache[$effectiveFunction] = (
                ($contract['templates'] !== [])
                || $contract['returnUsesMethodTemplates']
                || ($isStaticCall && $contract['classTemplates'] !== [])
            );
        }

        if ($hasMethodTemplates) {
            $cleaner = new ScopeCleaner($effectiveFunction);
            if ($thisObj !== null && $contract['classTemplates'] === []) {
                $cleaner->boundTemplates = TemplateManager::$topCallFrame[$effectiveFunction] ?? [];
            } else {
                $allTemplates = [...$contract['classTemplates'], ...$contract['templates']];
                $cleaner->boundTemplates = TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $allTemplates);
            }

            return $cleaner;
        }

        return null;
    }

    private static function fastInferType(mixed $val): TypeNode
    {
        if (\is_string($val)) {
            return TemplateManager::$stringNode ??= new IdentifierTypeNode('string');
        }
        if (\is_int($val)) {
            return TemplateManager::$intNode ??= new IdentifierTypeNode('int');
        }
        if ($val === null) {
            return new IdentifierTypeNode('null');
        }
        if (\is_bool($val)) {
            return TemplateManager::$boolNode ??= new IdentifierTypeNode('bool');
        }
        if (\is_float($val)) {
            return TemplateManager::$floatNode ??= new IdentifierTypeNode('float');
        }
        if (\is_object($val) && (TemplateManager::$instanceTemplateBindings === null || ! isset(TemplateManager::$instanceTemplateBindings[$val]))) {
            $cls = $val::class;

            return TemplateManager::$classNodeCache[$cls] ??= new IdentifierTypeNode($cls);
        }

        return TemplateManager::inferTypeFromValue($val);
    }

    /**
     * @param array<string, TypeNode> $valTypes
     */
    private static function accumulateInferredSample(mixed $item, int &$valMask, array &$valTypes): void
    {
        if (\is_string($item)) {
            $valMask |= 2;
        } elseif (\is_int($item)) {
            $valMask |= 1;
        } elseif (\is_bool($item)) {
            $valMask |= 4;
        } elseif (\is_float($item)) {
            $valMask |= 8;
        } elseif ($item === null) {
            $valMask |= 16;
        } elseif (\is_object($item)) {
            if (TemplateManager::$instanceTemplateBindings !== null && isset(TemplateManager::$instanceTemplateBindings[$item])) {
                $objType = TemplateManager::inferTypeFromValue($item);
                $valTypes[(string) $objType] = $objType;
            } else {
                $cls = $item::class;
                if (! isset($valTypes[$cls])) {
                    $valTypes[$cls] = TemplateManager::$classNodeCache[$cls] ??= new IdentifierTypeNode($cls);
                }
            }
        } else {
            $itemType = TemplateManager::inferTypeFromValue($item);
            $valTypes[(string) $itemType] = $itemType;
        }
    }

    /**
     * @param array<string, TypeNode> $valTypes
     */
    private static function buildFastValueTypeNode(int $valMask, array $valTypes): TypeNode
    {
        if ($valTypes === []) {
            if ($valMask === 2) {
                return TemplateManager::$stringNode ??= new IdentifierTypeNode('string');
            }
            if ($valMask === 1) {
                return TemplateManager::$intNode ??= new IdentifierTypeNode('int');
            }
            if ($valMask === 4) {
                return TemplateManager::$boolNode ??= new IdentifierTypeNode('bool');
            }
            if ($valMask === 8) {
                return TemplateManager::$floatNode ??= new IdentifierTypeNode('float');
            }
        }

        if (($valMask & 1) !== 0 && ! isset($valTypes['int'])) {
            $valTypes['int'] = TemplateManager::$intNode ??= new IdentifierTypeNode('int');
        }
        if (($valMask & 2) !== 0 && ! isset($valTypes['string'])) {
            $valTypes['string'] = TemplateManager::$stringNode ??= new IdentifierTypeNode('string');
        }
        if (($valMask & 4) !== 0 && ! isset($valTypes['bool'])) {
            $valTypes['bool'] = TemplateManager::$boolNode ??= new IdentifierTypeNode('bool');
        }
        if (($valMask & 8) !== 0 && ! isset($valTypes['float'])) {
            $valTypes['float'] = TemplateManager::$floatNode ??= new IdentifierTypeNode('float');
        }
        if (($valMask & 16) !== 0 && ! isset($valTypes['null'])) {
            $valTypes['null'] = new IdentifierTypeNode('null');
        }

        $values = array_values($valTypes);

        return \count($values) === 1 ? $values[0] : new UnionTypeNode($values);
    }

    /**
     * @param array{
     *     types: array<string, TypeNode>,
     *     templates: array<string, TemplateTagValueNode>,
     *     return: ?TypeNode
     * } $contract
     *
     * @return ?array{
     *     primaryParam: string,
     *     keyTName: ?string,
     *     valTName: ?string,
     *     needsKey: bool,
     *     needsVal: bool,
     *     otherParams: list<array{0: string, 1: int, 2: ?string}>
     * }
     */
    private static function compileSimpleGenericIterablePlan(array $contract): ?array
    {
        $templates = $contract['templates'];

        foreach ($templates as $tTag) {
            if ($tTag->default !== null) {
                return null;
            }
            if ($tTag->bound !== null) {
                if (! ($tTag->bound instanceof IdentifierTypeNode) || strtolower($tTag->bound->name) !== 'array-key') {
                    return null;
                }
            }
        }

        $primaryParam = null;
        $keyTName = null;
        $valTName = null;

        foreach ($contract['types'] as $pName => $tNode) {
            $foundGeneric = null;
            if ($tNode instanceof GenericTypeNode) {
                $base = strtolower((string) $tNode->type);
                if ($base === 'array' || $base === 'iterable') {
                    $foundGeneric = $tNode;
                } else {
                    return null;
                }
            } elseif ($tNode instanceof UnionTypeNode) {
                foreach ($tNode->types as $member) {
                    if ($member instanceof ArrayShapeNode || $member instanceof ObjectShapeNode || $member instanceof ArrayTypeNode || $member instanceof CallableTypeNode) {
                        return null;
                    }
                    if ($member instanceof GenericTypeNode) {
                        $mBase = strtolower((string) $member->type);
                        if ($mBase === 'array' || $mBase === 'iterable') {
                            $foundGeneric ??= $member;
                        } else {
                            return null;
                        }
                    }
                }
            }

            if ($foundGeneric !== null) {
                if ($primaryParam !== null) {
                    return null;
                }

                $gtCount = \count($foundGeneric->genericTypes);
                if ($gtCount === 1) {
                    $gt0 = $foundGeneric->genericTypes[0];
                    if (! ($gt0 instanceof IdentifierTypeNode) || ! isset($templates[$gt0->name])) {
                        return null;
                    }
                    $valTName = $gt0->name;
                } elseif ($gtCount === 2) {
                    $gt0 = $foundGeneric->genericTypes[0];
                    $gt1 = $foundGeneric->genericTypes[1];

                    if ($gt0 instanceof IdentifierTypeNode && isset($templates[$gt0->name])) {
                        $keyTName = $gt0->name;
                    } elseif (! ($gt0 instanceof IdentifierTypeNode) || ! \in_array(strtolower($gt0->name), ['array-key', 'mixed'], true)) {
                        return null;
                    }

                    if (! ($gt1 instanceof IdentifierTypeNode) || ! isset($templates[$gt1->name])) {
                        return null;
                    }
                    $valTName = $gt1->name;
                } else {
                    return null;
                }

                $primaryParam = $pName;
            }
        }

        if ($primaryParam === null) {
            return null;
        }

        $otherParams = [];
        $usedElsewhere = [];
        if ($contract['return'] !== null) {
            foreach ($templates as $tName => $_) {
                if (DocblockParser::typeReferencesTemplate($contract['return'], [$tName => true])) {
                    $usedElsewhere[$tName] = true;
                }
            }
        } else {
            foreach ($templates as $tName => $_) {
                $usedElsewhere[$tName] = true;
            }
        }

        foreach ($contract['types'] as $pName => $tNode) {
            if ($pName === $primaryParam) {
                continue;
            }

            if ($tNode instanceof IdentifierTypeNode && isset($templates[$tNode->name])) {
                $btName = $tNode->name;
                if ($btName === $keyTName || $btName === $valTName || $templates[$btName]->bound !== null) {
                    return null;
                }
                $otherParams[] = [$pName, self::PARAM_CHECK_BIND_TEMPLATE, $btName];

                continue;
            }

            if ($tNode instanceof CallableTypeNode) {
                return null;
            }

            if ($tNode instanceof NullableTypeNode) {
                if ($tNode->type instanceof CallableTypeNode) {
                    $otherParams[] = [$pName, self::PARAM_CHECK_REQUIRE_NULL, null];

                    continue;
                }
                if ($tNode->type instanceof IdentifierTypeNode) {
                    $op = match (strtolower($tNode->type->name)) {
                        'int', 'integer' => self::PARAM_CHECK_NULL_OR_INT,
                        'string' => self::PARAM_CHECK_NULL_OR_STRING,
                        'bool', 'boolean' => self::PARAM_CHECK_NULL_OR_BOOL,
                        'closure' => self::PARAM_CHECK_REQUIRE_NULL,
                        default => null,
                    };
                    if ($op === null) {
                        return null;
                    }
                    $otherParams[] = [$pName, $op, null];

                    continue;
                }

                return null;
            }

            if ($tNode instanceof UnionTypeNode) {
                if (\count($tNode->types) === 2) {
                    $t0 = $tNode->types[0];
                    $t1 = $tNode->types[1];
                    if ($t0 instanceof IdentifierTypeNode && $t1 instanceof IdentifierTypeNode) {
                        $n0 = strtolower($t0->name);
                        $n1 = strtolower($t1->name);
                        if (($n0 === 'int' && $n1 === 'float') || ($n0 === 'float' && $n1 === 'int')) {
                            $otherParams[] = [$pName, self::PARAM_CHECK_INT_OR_FLOAT, null];

                            continue;
                        }
                        if ($n1 === 'null' || $n0 === 'null') {
                            $otherName = $n1 === 'null' ? $n0 : $n1;
                            $op = match ($otherName) {
                                'int', 'integer' => self::PARAM_CHECK_NULL_OR_INT,
                                'string' => self::PARAM_CHECK_NULL_OR_STRING,
                                'bool', 'boolean' => self::PARAM_CHECK_NULL_OR_BOOL,
                                'closure' => self::PARAM_CHECK_REQUIRE_NULL,
                                default => null,
                            };
                            if ($op !== null) {
                                $otherParams[] = [$pName, $op, null];

                                continue;
                            }
                        }
                    }
                    if (($t0 instanceof CallableTypeNode && $t1 instanceof IdentifierTypeNode && strtolower($t1->name) === 'null')
                        || ($t1 instanceof CallableTypeNode && $t0 instanceof IdentifierTypeNode && strtolower($t0->name) === 'null')) {
                        $otherParams[] = [$pName, self::PARAM_CHECK_REQUIRE_NULL, null];

                        continue;
                    }
                }

                return null;
            }

            if ($tNode instanceof IdentifierTypeNode) {
                $op = match (strtolower($tNode->name)) {
                    'int', 'integer' => self::PARAM_CHECK_INT,
                    'string' => self::PARAM_CHECK_STRING,
                    'bool', 'boolean' => self::PARAM_CHECK_BOOL,
                    'float', 'double' => self::PARAM_CHECK_FLOAT,
                    'array-key' => self::PARAM_CHECK_ARRAY_KEY,
                    'array' => self::PARAM_CHECK_ARRAY,
                    'mixed' => self::PARAM_CHECK_MIXED,
                    default => null,
                };
                if ($op === null) {
                    return null;
                }
                $otherParams[] = [$pName, $op, null];

                continue;
            }

            return null;
        }

        return [
            'primaryParam' => $primaryParam,
            'keyTName' => $keyTName,
            'valTName' => $valTName,
            'needsKey' => $keyTName !== null && isset($usedElsewhere[$keyTName]),
            'needsVal' => $valTName !== null && isset($usedElsewhere[$valTName]),
            'otherParams' => $otherParams,
        ];
    }

    /**
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $templates
     * @param array<string, bool> $sensitiveParams
     *
     * @return array{effective: string, paramName: string, templateName: string, otherParams: list<array{0: string, 1: int, 2: TypeNode, 3: bool}>}|null
     */
    private static function compileSimpleClassStringTemplatePlan(
        string $effectiveFunction,
        array $types,
        array $templates,
        array $sensitiveParams
    ): ?array {
        if (\count($templates) !== 1 || $types === []) {
            return null;
        }

        $tName = (string) array_key_first($templates);
        $tTag = $templates[$tName];
        if ($tTag->bound !== null) {
            if (! ($tTag->bound instanceof IdentifierTypeNode)) {
                return null;
            }
            $boundLower = strtolower($tTag->bound->name);
            if ($boundLower !== 'object' && $boundLower !== 'mixed') {
                return null;
            }
        }

        $classStringParamName = null;
        $otherParams = [];

        foreach ($types as $paramName => $typeNode) {
            if (
                $typeNode instanceof GenericTypeNode
                && strtolower((string) $typeNode->type) === 'class-string'
                && \count($typeNode->genericTypes) === 1
                && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
                && $typeNode->genericTypes[0]->name === $tName
            ) {
                if ($classStringParamName !== null) {
                    return null;
                }
                $classStringParamName = $paramName;

                continue;
            }

            if (DocblockParser::typeReferencesTemplate($typeNode, $templates)) {
                return null;
            }

            if (! ($typeNode instanceof IdentifierTypeNode)) {
                return null;
            }

            $checkKind = match (strtolower($typeNode->name)) {
                'bool', 'boolean' => self::PARAM_CHECK_BOOL,
                'int', 'integer' => self::PARAM_CHECK_INT,
                'string' => self::PARAM_CHECK_STRING,
                'float', 'double' => self::PARAM_CHECK_FLOAT,
                'array' => self::PARAM_CHECK_ARRAY,
                'positive-int' => self::PARAM_CHECK_POSITIVE_INT,
                'non-empty-string' => self::PARAM_CHECK_NON_EMPTY_STRING,
                'mixed' => self::PARAM_CHECK_MIXED,
                default => null,
            };

            if ($checkKind === null) {
                return null;
            }

            if ($checkKind !== self::PARAM_CHECK_MIXED) {
                $otherParams[] = [$paramName, $checkKind, $typeNode, $sensitiveParams[$paramName] ?? false];
            }
        }

        if ($classStringParamName === null) {
            return null;
        }

        return [
            'effective' => $effectiveFunction,
            'paramName' => $classStringParamName,
            'templateName' => $tName,
            'otherParams' => $otherParams,
        ];
    }

    /**
     * Compiles a simple constructor parameter-to-template plan when all parameters are distinct bare class templates
     * with no defaults or complex/const bounds. Returns null if the constructor is not eligible.
     *
     * @param array<string, TypeNode> $types
     * @param array<string, TemplateTagValueNode> $classTemplates
     *
     * @return list<array{0: string, 1: string, 2: int}>|null
     */
    private static function compileSimpleCtorTemplatePlanItems(array $types, array $classTemplates, string $effectiveFunction): ?array
    {
        if ($types === []) {
            return null;
        }

        foreach ($classTemplates as $tTag) {
            if ($tTag->default !== null && ! TemplateManager::isUnspecializedDefault($tTag)) {
                return null;
            }
        }

        $seenTemplates = [];
        $items = [];

        foreach ($types as $paramName => $typeNode) {
            if (! ($typeNode instanceof IdentifierTypeNode) || ! isset($classTemplates[$typeNode->name])) {
                return null;
            }

            $tName = $typeNode->name;
            if (isset($seenTemplates[$tName])) {
                return null;
            }
            $seenTemplates[$tName] = true;

            $tTag = $classTemplates[$tName];
            $mask = 0;
            if ($tTag->bound !== null) {
                $boundStr = (string) $tTag->bound;
                if (str_contains($boundStr, 'static') || str_contains($boundStr, '$this')) {
                    return null;
                }
                if (DocblockParser::typeReferencesTemplate($tTag->bound, $classTemplates)) {
                    return null;
                }

                $resolvedBound = SpecialTypeResolver::resolve($tTag->bound, $effectiveFunction, null);
                if (self::typeNodeContainsConst($resolvedBound)) {
                    return null;
                }

                $mask = self::compilePrimitiveBoundMask($resolvedBound);
                if ($mask === 0) {
                    return null;
                }
            }

            $items[] = [$paramName, $tName, $mask];
        }

        return $items;
    }

    private static function typeNodeContainsConst(TypeNode $node): bool
    {
        if ($node instanceof ConstTypeNode) {
            return true;
        }

        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $member) {
                if (self::typeNodeContainsConst($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function compilePrimitiveBoundMask(TypeNode $node): int
    {
        if ($node instanceof IdentifierTypeNode) {
            return match (strtolower($node->name)) {
                'int', 'integer' => 1,
                'string' => 2,
                'bool', 'boolean' => 4,
                'float', 'double' => 8,
                'object' => 16,
                'null' => 32,
                'array' => 64,
                'array-key' => 3,
                default => 0,
            };
        }

        if ($node instanceof UnionTypeNode) {
            $mask = 0;
            foreach ($node->types as $member) {
                if (! ($member instanceof IdentifierTypeNode)) {
                    return 0;
                }
                $bit = self::compilePrimitiveBoundMask($member);
                if ($bit === 0) {
                    return 0;
                }
                $mask |= $bit;
            }

            return $mask;
        }

        return 0;
    }

    /**
     * @param array{class: ?string, requireObject: bool, function: string, items: list<array{0: string, 1: int, 2: TypeNode, 3: bool, 4: ?string, 5: bool}>} $plan
     * @param array<string, mixed> $vars
     */
    private static function executeSimpleScopePlan(array $plan, array $vars): ?ErrorMessage
    {
        if ($vars === []) {
            return null;
        }

        $effectiveFunc = $plan['function'];

        foreach ($plan['items'] as $item) {
            $paramName = $item[0];

            if (isset($vars[$paramName])) {
                $val = $vars[$paramName];
            } elseif (\array_key_exists($paramName, $vars)) {
                if ($item[5]) {
                    continue;
                }
                $val = null;
            } else {
                continue;
            }

            $ok = match ($item[1]) {
                self::PARAM_CHECK_POSITIVE_INT => \is_int($val) && $val > 0,
                self::PARAM_CHECK_NON_EMPTY_STRING => \is_string($val) && $val !== '',
                self::PARAM_CHECK_INT => \is_int($val),
                self::PARAM_CHECK_STRING => \is_string($val),
                self::PARAM_CHECK_BOOL => \is_bool($val),
                self::PARAM_CHECK_FLOAT => \is_float($val) || \is_int($val),
                self::PARAM_CHECK_OBJECT => \is_object($val),
                self::PARAM_CHECK_CLASS => \is_object($val) && $item[4] !== null && ($val instanceof $item[4]),
                self::PARAM_CHECK_ARRAY_KEY => \is_int($val) || \is_string($val),
                self::PARAM_CHECK_ARRAY => \is_array($val),
                self::PARAM_CHECK_LIST => \is_array($val) && ($val === [] || array_is_list($val)),
                self::PARAM_CHECK_NON_NEGATIVE_INT => \is_int($val) && $val >= 0,
                self::PARAM_CHECK_NEGATIVE_INT => \is_int($val) && $val < 0,
                self::PARAM_CHECK_NON_POSITIVE_INT => \is_int($val) && $val <= 0,
                self::PARAM_CHECK_NON_ZERO_INT => \is_int($val) && $val !== 0,
                self::PARAM_CHECK_MIXED => true,
                self::PARAM_CHECK_NULL => $val === null,
                self::PARAM_CHECK_TRUE => $val === true,
                self::PARAM_CHECK_FALSE => $val === false,
                self::PARAM_CHECK_SCALAR => \is_scalar($val),
                default => false,
            };

            if ($ok) {
                continue;
            }

            $registry = self::$registry ??= new TypeValidatorRegistry();
            $err = $registry->validate($val, $item[2], '', $item[3]);
            if ($err !== null) {
                $formattedErr = ErrorFactory::createError($effectiveFunc . '(): Argument $' . $paramName . $err->getMessage());

                if (IgnoreManager::isCallerIgnored()) {
                    return null;
                }

                $handled = ViolationCollector::handle($formattedErr, 'parameter', null, function: $effectiveFunc);
                if ($handled instanceof ErrorMessage) {
                    return $handled;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, TypeNode> $types
     * @param array<string, bool> $sensitiveParams
     *
     * @return list<array{0: string, 1: int, 2: TypeNode, 3: bool, 4: ?string, 5: bool}>
     */
    private static function compileSimpleScopePlanItems(array $types, array $sensitiveParams): array
    {
        $items = [];
        foreach ($types as $paramName => $typeNode) {
            $isSensitive = $sensitiveParams[$paramName] ?? false;
            $checkKind = self::PARAM_CHECK_FALLBACK;
            $extra = null;
            $allowsNull = false;
            $inner = $typeNode;

            while ($inner instanceof NullableTypeNode) {
                $allowsNull = true;
                $inner = $inner->type;
            }

            if ($inner instanceof UnionTypeNode) {
                $nonNullTypes = [];
                foreach ($inner->types as $member) {
                    if (self::isNullTypeNode($member)) {
                        $allowsNull = true;
                    } elseif ($member instanceof NullableTypeNode) {
                        $allowsNull = true;
                        $nonNullTypes[] = $member->type;
                    } else {
                        $nonNullTypes[] = $member;
                    }
                }

                if ($allowsNull && \count($nonNullTypes) === 1) {
                    $inner = $nonNullTypes[0];
                    while ($inner instanceof NullableTypeNode) {
                        $inner = $inner->type;
                    }
                }
            }

            if ($inner instanceof IdentifierTypeNode) {
                $name = $inner->name;
                $checkKind = match (strtolower($name)) {
                    'int', 'integer' => self::PARAM_CHECK_INT,
                    'string' => self::PARAM_CHECK_STRING,
                    'positive-int' => self::PARAM_CHECK_POSITIVE_INT,
                    'non-empty-string' => self::PARAM_CHECK_NON_EMPTY_STRING,
                    'bool', 'boolean' => self::PARAM_CHECK_BOOL,
                    'float', 'double' => self::PARAM_CHECK_FLOAT,
                    'object' => self::PARAM_CHECK_OBJECT,
                    'array-key' => self::PARAM_CHECK_ARRAY_KEY,
                    'array' => self::PARAM_CHECK_ARRAY,
                    'list' => self::PARAM_CHECK_LIST,
                    'non-negative-int', 'unsigned-int' => self::PARAM_CHECK_NON_NEGATIVE_INT,
                    'negative-int' => self::PARAM_CHECK_NEGATIVE_INT,
                    'non-positive-int' => self::PARAM_CHECK_NON_POSITIVE_INT,
                    'non-zero-int' => self::PARAM_CHECK_NON_ZERO_INT,
                    'mixed' => self::PARAM_CHECK_MIXED,
                    'null' => self::PARAM_CHECK_NULL,
                    'true' => self::PARAM_CHECK_TRUE,
                    'false' => self::PARAM_CHECK_FALSE,
                    'scalar' => self::PARAM_CHECK_SCALAR,
                    default => self::PARAM_CHECK_FALLBACK,
                };

                if ($checkKind === self::PARAM_CHECK_NULL || $checkKind === self::PARAM_CHECK_MIXED) {
                    $allowsNull = true;
                } elseif (
                    $checkKind === self::PARAM_CHECK_FALLBACK
                    && ! SpecialTypeResolver::isBuiltInTypeKeyword($name)
                    && ClassNameValidator::isValid($name)
                ) {
                    $checkKind = self::PARAM_CHECK_CLASS;
                    $extra = $name;
                }
            }

            $items[] = [$paramName, $checkKind, $typeNode, $isSensitive, $extra, $allowsNull];
        }

        return $items;
    }

    /**
     * Validates all incoming parameters against the function or method's declared contract.
     *
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, object|string|null $thisOrClass = null): ?ErrorMessage
    {
        if (! self::$isEnabled) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return null;
        }

        $err = ParamChecker::checkParams($function, $vars, $thisOrClass, self::getRegistry());

        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored()) {
                return null;
            }

            /** @var ErrorMessage|null $handled */
            $handled = ViolationCollector::handle($err, 'parameter', null, function: $effectiveFunction);

            return $handled;
        }

        return null;
    }

    /**
     * Validates a by-reference parameter's post-condition (@param-out) upon exit.
     *
     * @param array<string, mixed>|null $vars
     */
    public static function checkParamOut(
        string $function,
        string $paramName,
        mixed $value,
        object|string|null $thisOrClass = null,
        ?array $vars = []
    ): ?ErrorMessage {
        if (! self::$isEnabled || ! self::$paramsOutEnabled) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return null;
        }

        $vars = $vars !== null ? $vars : [];

        $res = ParamOutChecker::checkParamOut($function, $paramName, $value, $thisOrClass, self::getRegistry(), $effectiveFunction, $vars);

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored()) {
                return null;
            }

            /** @var ErrorMessage|null $handled */
            $handled = ViolationCollector::handle($res, 'param-out', null, function: $effectiveFunction, target: '$' . $paramName);

            return $handled;
        }

        return null;
    }

    /**
     * Re-types generic template state on $this upon method exit (@self-out, @phpstan-self-out, @psalm-self-out).
     *
     * @param array<int|string, mixed>|null $vars
     */
    public static function checkSelfOut(string $function, object $thisObj, ?array $vars = []): void
    {
        if (! self::$isEnabled || ! self::$selfOutEnabled) {
            return;
        }

        if (isset(SelfOutChecker::$noSelfOutContractCache[$function])) {
            return;
        }

        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisObj, $thisObj);

        if (isset(SelfOutChecker::$noSelfOutContractCache[$effectiveFunction])) {
            return;
        }

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return;
        }

        SelfOutChecker::checkSelfOut($function, $thisObj, $vars ?? [], self::getRegistry(), $effectiveFunction);
    }

    /**
     * Validates a function or method's return value against its declared contract and returns value or ErrorMessage.
     *
     * @param array<string, mixed>|null $vars
     */
    public static function checkReturn(string $function, mixed $value, object|string|null $thisOrClass = null, ?array $vars = []): mixed
    {
        if (! self::$isEnabled || ! self::$returnsEnabled) {
            return $value;
        }

        if (
            \is_object($thisOrClass)
            && $value === $thisOrClass
            && TemplateManager::$instanceTemplateBindings !== null
            && isset(TemplateManager::$instanceTemplateBindings[$thisOrClass])
        ) {
            if (isset(self::$identitySelfReturnCache[$function]) || isset(self::$traitIdentitySelfReturnCache[$function][$thisOrClass::class])) {
                return $value;
            }
        }

        if ($value === null) {
            if (isset(self::$nullableReturnCache[$function])) {
                return null;
            }
            if (\is_object($thisOrClass) && isset(self::$traitNullableReturnCache[$function][$thisOrClass::class])) {
                return null;
            }
        }

        if (\is_object($thisOrClass) && isset(self::$traitReturnPlanCache[$function][$thisOrClass::class])) {
            $tPlan = self::$traitReturnPlanCache[$function][$thisOrClass::class];

            return self::executeSimpleReturnPlan($tPlan['function'], $tPlan['type'], $value, $tPlan['op'], $tPlan['extra']);
        }

        $retPlan = self::$simpleReturnPlanCache[$function] ?? null;
        if (
            $retPlan !== null
            && ($retPlan['class'] === null
                ? $thisOrClass === null
                : (\is_object($thisOrClass) ? $thisOrClass::class === $retPlan['class'] : $thisOrClass === $retPlan['class']))
        ) {
            return self::executeSimpleReturnPlan($function, $retPlan['type'], $value, $retPlan['op'], $retPlan['extra']);
        }

        if (isset(self::$genericReturnPlanCache[$function]) && \is_object($thisOrClass)) {
            $genPlan = self::$genericReturnPlanCache[$function];
            if ($thisOrClass::class === $genPlan['class']) {
                $boundType = TemplateManager::getInstanceBoundTypeFast($thisOrClass, $genPlan['templateName']);
                if ($boundType instanceof IdentifierTypeNode) {
                    $tName = $boundType->name;
                    if (
                        ($tName === 'int' && \is_int($value))
                        || ($tName === 'string' && \is_string($value))
                        || ($tName === 'bool' && \is_bool($value))
                        || ($tName === 'float' && (\is_float($value) || \is_int($value)))
                        || (\is_object($value) && ($value instanceof $tName) && ! ($value instanceof Traversable))
                        || $tName === 'mixed'
                        || $tName === 'array'
                    ) {
                        return $value;
                    }
                }
            }
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = $thisOrClass === null
            ? $function
            : ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (
            $thisObj !== null
            && $value === $thisObj
            && TemplateManager::$instanceTemplateBindings !== null
            && isset(TemplateManager::$instanceTemplateBindings[$thisObj])
            && isset(self::$identitySelfReturnCache[$effectiveFunction])
        ) {
            return $value;
        }

        if ($value === null && isset(self::$nullableReturnCache[$effectiveFunction])) {
            return null;
        }

        if (isset(self::$simpleReturnPlanCache[$effectiveFunction])) {
            $effPlan = self::$simpleReturnPlanCache[$effectiveFunction];

            return self::executeSimpleReturnPlan($effectiveFunction, $effPlan['type'], $value, $effPlan['op'], $effPlan['extra']);
        }

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $value;
        }

        $functionKind = ParamChecker::$functionKindCache[$effectiveFunction] ??= ParamChecker::detectFunctionKind($effectiveFunction);
        $isDynamicDispatch = ($functionKind >= ParamChecker::KIND_MAGIC_CALL);

        if (! $isDynamicDispatch && isset(ReturnChecker::$noReturnContractCache[$effectiveFunction])) {
            return $value;
        }

        $contract = DocblockParser::parse($effectiveFunction);
        $declaringClass = str_contains($effectiveFunction, '::') ? explode('::', $effectiveFunction, 2)[0] : null;
        $returnTypeNode = $contract['return'];

        if (! $isDynamicDispatch && $returnTypeNode === null) {
            ReturnChecker::$noReturnContractCache[$effectiveFunction] = true;

            return $value;
        }

        if (! $isDynamicDispatch && ($contract['returnAllowsNull'] ?? false) && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])) {
            self::$nullableReturnCache[$effectiveFunction] = true;
            if ($function === $effectiveFunction) {
                self::$nullableReturnCache[$function] = true;
            } elseif ($thisObj !== null && HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                self::$traitNullableReturnCache[$function][$thisObj::class] = true;
            }

            if ($value === null) {
                return null;
            }
        }

        if (! $isDynamicDispatch && ($contract['returnUnconstrained'] ?? false)) {
            ReturnChecker::$noReturnContractCache[$effectiveFunction] = true;
            $unconstrainedPlan = [
                'class' => $declaringClass,
                'type' => false,
                'op' => self::PARAM_CHECK_MIXED,
                'extra' => null,
            ];
            self::$simpleReturnPlanCache[$effectiveFunction] = $unconstrainedPlan;
            if ($function === $effectiveFunction) {
                self::$simpleReturnPlanCache[$function] = $unconstrainedPlan;
            } elseif ($thisObj !== null && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                self::$traitReturnPlanCache[$function][$thisObj::class] = [
                    'function' => $effectiveFunction,
                    'type' => false,
                    'op' => self::PARAM_CHECK_MIXED,
                    'extra' => null,
                ];
            }

            return $value;
        }

        $registry = self::$registry ??= new TypeValidatorRegistry();

        if (
            ! $isDynamicDispatch
            && $returnTypeNode !== null
            && ! ($contract['returnUsesGenerics'] ?? true)
            && \count($contract['aliases']) === 0
            && ! ($contract['returnHasConditional'] ?? true)
            && ! $contract['returnIsDynamic']
            && ! ($returnTypeNode instanceof CallableTypeNode)
        ) {
            $resolvedType = ReturnChecker::$resolvedStaticReturnCache[$effectiveFunction]
                ??= SpecialTypeResolver::resolve($returnTypeNode, $effectiveFunction, null);

            if ($resolvedType instanceof IdentifierTypeNode) {
                $lowerName = strtolower($resolvedType->name);
                if ($lowerName === 'mixed' || $lowerName === 'array') {
                    ReturnChecker::$noReturnContractCache[$effectiveFunction] = true;
                    $unconstrainedPlan = [
                        'class' => $declaringClass,
                        'type' => false,
                        'op' => self::PARAM_CHECK_MIXED,
                        'extra' => null,
                    ];
                    self::$simpleReturnPlanCache[$effectiveFunction] = $unconstrainedPlan;
                    if ($function === $effectiveFunction) {
                        self::$simpleReturnPlanCache[$function] = $unconstrainedPlan;
                    } elseif ($thisObj !== null && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                        self::$traitReturnPlanCache[$function][$thisObj::class] = [
                            'function' => $effectiveFunction,
                            'type' => false,
                            'op' => self::PARAM_CHECK_MIXED,
                            'extra' => null,
                        ];
                    }

                    return $value;
                }
            }

            $isTraversableIterable = false;
            if ($resolvedType instanceof IdentifierTypeNode) {
                $baseName = strtolower(ltrim($resolvedType->name, '\\'));
                $isTraversableIterable = isset(ReturnChecker::GENERIC_ITERABLES[$baseName]);
            } elseif ($resolvedType instanceof GenericTypeNode) {
                $baseName = strtolower(ltrim((string) $resolvedType->type, '\\'));
                $isTraversableIterable = isset(ReturnChecker::GENERIC_ITERABLES[$baseName]);
            }

            if (! $isTraversableIterable && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])) {
                [$op, $extra] = self::compileSimpleReturnOp($resolvedType);
                $compiledPlan = [
                    'class' => $declaringClass,
                    'type' => $resolvedType,
                    'op' => $op,
                    'extra' => $extra,
                ];
                self::$simpleReturnPlanCache[$effectiveFunction] = $compiledPlan;
                if ($function === $effectiveFunction) {
                    self::$simpleReturnPlanCache[$function] = $compiledPlan;
                } elseif ($thisObj !== null && HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                    self::$traitReturnPlanCache[$function][$thisObj::class] = [
                        'function' => $effectiveFunction,
                        'type' => $resolvedType,
                        'op' => $op,
                        'extra' => $extra,
                    ];
                }
            }

            $err = $registry->validate($value, $resolvedType, 'Return value');
            if ($err !== null) {
                if (IgnoreManager::isCallerIgnored()) {
                    return $value;
                }

                $formattedErr = ErrorFactory::createError($effectiveFunction . '(): ' . $err->getMessage());

                return ViolationCollector::handle($formattedErr, 'return', $value, function: $effectiveFunction, target: 'return');
            }

            if ($isTraversableIterable && $value instanceof Traversable) {
                return self::wrapIterable($effectiveFunction, 'return', $value);
            }

            return $value;
        }

        if (
            ! $isDynamicDispatch
            && $thisObj !== null
            && $returnTypeNode instanceof IdentifierTypeNode
            && $contract['templates'] === []
            && isset($contract['classTemplates'][$returnTypeNode->name])
            && \count($contract['aliases']) === 0
            && ! ($contract['returnHasConditional'] ?? true)
            && ! $contract['returnIsDynamic']
            && ! ($contract['returnIsThis'] ?? false)
            && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])
        ) {
            if ($declaringClass !== null && $function === $effectiveFunction) {
                self::$genericReturnPlanCache[$function] = [
                    'class' => $declaringClass,
                    'templateName' => $returnTypeNode->name,
                ];
            }
        }

        if (
            ! $isDynamicDispatch
            && $thisObj !== null
            && $declaringClass !== null
            && $returnTypeNode instanceof GenericTypeNode
            && $contract['templates'] === []
            && $contract['classTemplates'] !== []
            && \count($contract['aliases']) === 0
            && ! ($contract['returnHasConditional'] ?? true)
            && ! ($contract['returnIsThis'] ?? false)
            && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction])
            && self::isIdentitySelfGenericReturn($returnTypeNode, $declaringClass, $thisObj, $contract['classTemplates'])
        ) {
            self::$identitySelfReturnCache[$effectiveFunction] = true;
            if ($function === $effectiveFunction) {
                self::$identitySelfReturnCache[$function] = true;
            } elseif (HierarchyResolver::getTraitAliases($thisObj::class) === []) {
                self::$traitIdentitySelfReturnCache[$function][$thisObj::class] = true;
            }

            if (
                $value === $thisObj
                && TemplateManager::$instanceTemplateBindings !== null
                && isset(TemplateManager::$instanceTemplateBindings[$thisObj])
            ) {
                return $value;
            }
        }

        $vars = $vars !== null ? $vars : [];

        $res = ReturnChecker::checkReturn(
            $function,
            $value,
            $thisOrClass,
            $vars,
            $registry,
            [self::class, 'wrapIterable'],
            $effectiveFunction,
            $contract
        );

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            return ViolationCollector::handle($res, 'return', $value, function: $effectiveFunction, target: 'return');
        }

        return $res;
    }

    /**
     * Checks whether a GenericTypeNode return contract is an exact identity self-return
     * (e.g. `@return self<T1, T2>` or `@return static<T1, T2>`) matching the class's declared template order.
     *
     * @param array<string, TemplateTagValueNode> $classTemplates
     */
    private static function isIdentitySelfGenericReturn(
        GenericTypeNode $returnTypeNode,
        string $declaringClass,
        object $thisObj,
        array $classTemplates
    ): bool {
        if ($classTemplates === []) {
            return false;
        }

        $baseName = (string) $returnTypeNode->type;
        $baseLower = strtolower($baseName);
        $runtimeClass = $thisObj::class;

        $isSelfRef = match ($baseLower) {
            'self', 'static', '$this' => true,
            default => ltrim($baseName, '\\') === $declaringClass || ltrim($baseName, '\\') === $runtimeClass,
        };

        if (! $isSelfRef) {
            return false;
        }

        $runtimeDeclaredNames = TemplateManager::getDeclaredClassTemplateNames($runtimeClass);
        if ($runtimeClass !== $declaringClass && $runtimeDeclaredNames !== []) {
            return false;
        }

        $targetNames = $runtimeDeclaredNames !== [] ? $runtimeDeclaredNames : array_keys($classTemplates);
        if (\count($returnTypeNode->genericTypes) !== \count($targetNames)) {
            return false;
        }

        foreach ($targetNames as $idx => $tName) {
            $gt = $returnTypeNode->genericTypes[$idx] ?? null;
            if (! ($gt instanceof IdentifierTypeNode) || $gt->name !== $tName) {
                return false;
            }

            $variance = $returnTypeNode->variances[$idx] ?? GenericTypeNode::VARIANCE_INVARIANT;
            if ($variance === GenericTypeNode::VARIANCE_CONTRAVARIANT) {
                return false;
            }
        }

        return true;
    }

    private static function executeSimpleReturnPlan(
        string $function,
        TypeNode|false $resolvedType,
        mixed $value,
        int $op = self::PARAM_CHECK_FALLBACK,
        ?string $extra = null
    ): mixed {
        if ($resolvedType === false || $op === self::PARAM_CHECK_MIXED) {
            return $value;
        }

        $ok = match ($op) {
            self::PARAM_CHECK_INT => \is_int($value),
            self::PARAM_CHECK_STRING => \is_string($value),
            self::PARAM_CHECK_BOOL => \is_bool($value),
            self::PARAM_CHECK_FLOAT => \is_float($value) || \is_int($value),
            self::PARAM_CHECK_ARRAY => \is_array($value),
            self::PARAM_CHECK_OBJECT => \is_object($value),
            self::PARAM_CHECK_CLASS => \is_object($value) && $extra !== null && ($value instanceof $extra),
            self::PARAM_CHECK_POSITIVE_INT => \is_int($value) && $value > 0,
            self::PARAM_CHECK_NON_EMPTY_STRING => \is_string($value) && $value !== '',
            self::PARAM_CHECK_ARRAY_KEY => \is_int($value) || \is_string($value),
            self::PARAM_CHECK_LIST => \is_array($value) && ($value === [] || array_is_list($value)),
            self::PARAM_CHECK_NON_NEGATIVE_INT => \is_int($value) && $value >= 0,
            self::PARAM_CHECK_NEGATIVE_INT => \is_int($value) && $value < 0,
            self::PARAM_CHECK_NON_POSITIVE_INT => \is_int($value) && $value <= 0,
            self::PARAM_CHECK_NON_ZERO_INT => \is_int($value) && $value !== 0,
            self::PARAM_CHECK_NULL => $value === null,
            self::PARAM_CHECK_TRUE => $value === true,
            self::PARAM_CHECK_FALSE => $value === false,
            self::PARAM_CHECK_SCALAR => \is_scalar($value),
            self::PARAM_CHECK_NULL_OR_INT => $value === null || \is_int($value),
            self::PARAM_CHECK_NULL_OR_STRING => $value === null || \is_string($value),
            self::PARAM_CHECK_NULL_OR_BOOL => $value === null || \is_bool($value),
            default => false,
        };

        if ($ok) {
            return $value;
        }

        $registry = self::$registry ??= new TypeValidatorRegistry();
        $err = $registry->validate($value, $resolvedType, 'Return value');
        if ($err !== null) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            $formattedErr = ErrorFactory::createError($function . '(): ' . $err->getMessage());

            return ViolationCollector::handle($formattedErr, 'return', $value, function: $function, target: 'return');
        }

        return $value;
    }

    /**
     * @return array{0: int, 1: ?string}
     */
    private static function compileSimpleReturnOp(TypeNode $resolvedType): array
    {
        $isNullable = false;
        $inner = $resolvedType;

        while ($inner instanceof NullableTypeNode) {
            $isNullable = true;
            $inner = $inner->type;
        }

        if ($inner instanceof UnionTypeNode && \count($inner->types) === 2) {
            $t0 = $inner->types[0];
            $t1 = $inner->types[1];

            if (self::isNullTypeNode($t0)) {
                $isNullable = true;
                $inner = $t1;
            } elseif (self::isNullTypeNode($t1)) {
                $isNullable = true;
                $inner = $t0;
            }

            while ($inner instanceof NullableTypeNode) {
                $isNullable = true;
                $inner = $inner->type;
            }
        }

        if (! ($inner instanceof IdentifierTypeNode)) {
            return [self::PARAM_CHECK_FALLBACK, null];
        }

        $name = $inner->name;
        $lower = strtolower($name);

        if ($isNullable) {
            $nullableOp = match ($lower) {
                'int', 'integer' => self::PARAM_CHECK_NULL_OR_INT,
                'string' => self::PARAM_CHECK_NULL_OR_STRING,
                'bool', 'boolean' => self::PARAM_CHECK_NULL_OR_BOOL,
                default => null,
            };

            if ($nullableOp !== null) {
                return [$nullableOp, null];
            }
        }

        $op = match ($lower) {
            'int', 'integer' => self::PARAM_CHECK_INT,
            'string' => self::PARAM_CHECK_STRING,
            'bool', 'boolean' => self::PARAM_CHECK_BOOL,
            'float', 'double' => self::PARAM_CHECK_FLOAT,
            'array' => self::PARAM_CHECK_ARRAY,
            'object' => self::PARAM_CHECK_OBJECT,
            'positive-int' => self::PARAM_CHECK_POSITIVE_INT,
            'non-empty-string' => self::PARAM_CHECK_NON_EMPTY_STRING,
            'array-key' => self::PARAM_CHECK_ARRAY_KEY,
            'list' => self::PARAM_CHECK_LIST,
            'non-negative-int', 'unsigned-int' => self::PARAM_CHECK_NON_NEGATIVE_INT,
            'negative-int' => self::PARAM_CHECK_NEGATIVE_INT,
            'non-positive-int' => self::PARAM_CHECK_NON_POSITIVE_INT,
            'non-zero-int' => self::PARAM_CHECK_NON_ZERO_INT,
            'mixed' => self::PARAM_CHECK_MIXED,
            'null', 'void' => self::PARAM_CHECK_NULL,
            'true' => self::PARAM_CHECK_TRUE,
            'false' => self::PARAM_CHECK_FALSE,
            'scalar' => self::PARAM_CHECK_SCALAR,
            default => null,
        };

        if ($op !== null) {
            return [$op, null];
        }

        if (
            ! SpecialTypeResolver::isBuiltInTypeKeyword($name)
            && ClassNameValidator::isValid($name)
            && (class_exists($name, false) || interface_exists($name, false))
        ) {
            return [self::PARAM_CHECK_CLASS, $name];
        }

        return [self::PARAM_CHECK_FALLBACK, null];
    }

    private static function isNullTypeNode(TypeNode $node): bool
    {
        return ($node instanceof IdentifierTypeNode && strtolower($node->name) === 'null')
            || ($node instanceof ConstTypeNode && $node->constExpr instanceof \PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode);
    }

    /**
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(
        string $function,
        mixed $sendValue,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null
    ): mixed {
        if ($sendValue === null || ! self::$isEnabled) {
            return $sendValue;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $sendValue;
        }

        $res = GeneratorChecker::checkSend($function, $sendValue, self::getRegistry(), $thisOrClass, $scopeCleaner, $effectiveFunction);

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored()) {
                return $sendValue;
            }

            return ViolationCollector::handle($res, 'send', $sendValue, function: $effectiveFunction);
        }

        return $res;
    }

    /**
     * Validates a value returned from a generator against TReturn.
     */
    public static function checkGeneratorReturn(
        string $function,
        mixed $value,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null
    ): mixed {
        if (! self::$isEnabled || ! self::$returnsEnabled) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $value;
        }

        $res = GeneratorChecker::checkReturn($function, $value, self::getRegistry(), $thisOrClass, $scopeCleaner, $effectiveFunction);

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            return ViolationCollector::handle($res, 'return', $value, function: $effectiveFunction, target: 'return');
        }

        return $res;
    }

    /**
     * Validates yielded keys and values from a generator function against TKey and TValue.
     */
    public static function checkYield(
        string $function,
        mixed $key,
        mixed $value,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null
    ): mixed {
        if (! self::$isEnabled) {
            return $value;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        if ($thisObj !== null && isset(CallerBoundaryResolver::$nonBypassCalleeCache[$function]) && str_starts_with($function, $thisObj::class . '::')) {
            $effectiveFunction = $function;
        } else {
            $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

            if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
                return $value;
            }
        }

        $res = GeneratorChecker::checkYield($function, $key, $value, self::$registry ??= new TypeValidatorRegistry(), $thisOrClass, $scopeCleaner, $effectiveFunction);

        if ($res instanceof ErrorMessage) {
            if (IgnoreManager::isCallerIgnored()) {
                return $value;
            }

            return ViolationCollector::handle($res, 'yield', $value, function: $effectiveFunction);
        }

        return $res;
    }

    /**
     * Wraps a callable parameter to intercept calls and validate inputs/returns dynamically.
     */
    public static function wrapCallable(string $function, string $paramName, mixed $callable, object|string|null $thisOrClass = null): mixed
    {
        if (! self::$isEnabled) {
            return $callable;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $callable;
        }

        return CallableWrapper::wrap($function, $paramName, $callable, self::getRegistry(), $thisOrClass);
    }

    /**
     * Wraps an iterable or generator parameter to lazily validate items during iteration.
     */
    public static function wrapIterable(string $function, string $paramName, mixed $iterable, object|string|null $thisOrClass = null): mixed
    {
        if (! self::$isEnabled || (\is_array($iterable) && $paramName !== 'return') || ! is_iterable($iterable)) {
            return $iterable;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $iterable;
        }

        return IterableWrapper::wrap($function, $paramName, $iterable, self::$registry ??= new TypeValidatorRegistry(), $thisOrClass);
    }

    /**
     * Registers a pending clone source object before clone execution.
     */
    public static function prepareClone(mixed $original): mixed
    {
        if (\is_object($original)) {
            TemplateManager::$pendingCloneSource = $original;
        }

        return $original;
    }

    /**
     * Copies generic template bindings from an original object to a cloned object instance.
     */
    public static function cloneInstance(mixed $cloned, mixed $original): mixed
    {
        if (\is_object($cloned) && \is_object($original)) {
            TemplateManager::copyInstanceBindings($original, $cloned);
        }

        TemplateManager::$pendingCloneSource = null;

        return $cloned;
    }

    /**
     * PHP 8.5: Copies generic bindings and validates overridden properties on clone($obj, [...]).
     *
     * @param array<string, mixed>|null $overriddenProperties
     */
    public static function cloneWith(
        mixed $cloned,
        mixed $original = null,
        ?array $overriddenProperties = null,
        string $file = '',
        ?int $line = null,
    ): mixed {
        $source = \is_object($original) ? $original : TemplateManager::$pendingCloneSource;
        TemplateManager::$pendingCloneSource = null;

        if (! \is_object($cloned) || ! \is_object($source)) {
            return $cloned;
        }

        TemplateManager::copyInstanceBindings($source, $cloned);

        if ($overriddenProperties !== null && $overriddenProperties !== [] && Config::isInlinePropertiesEnabled()) {
            foreach ($overriddenProperties as $propName => $propValue) {
                $res = self::checkProperty($propValue, $cloned, (string) $propName, $file);
                if ($res instanceof ErrorMessage) {
                    $typeError = new \TypePHP\Exception\TypeError($res->getMessage());

                    throw ErrorFactory::prepareException($typeError, $line, $file !== '' ? $file : null);
                }
            }
        }

        return $cloned;
    }

    /**
     * Infers a TypeNode AST representation from a raw PHP value.
     */
    public static function inferTypeFromValue(mixed $value): TypeNode
    {
        return TemplateManager::inferTypeFromValue($value);
    }

    /**
     * Returns a singleton instance of the TypeValidatorRegistry.
     */
    public static function getRegistry(): TypeValidatorRegistry
    {
        return self::$registry ??= new TypeValidatorRegistry();
    }
}
