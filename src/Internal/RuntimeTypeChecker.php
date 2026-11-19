<?php

declare(strict_types=1);

namespace TypePHP\Internal;

use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
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
use TypePHP\Internal\Resolver\SpecialTypeResolver;
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

    public static bool $isEnabled = true;

    public static bool $paramsEnabled = true;

    public static bool $returnsEnabled = true;

    public static bool $paramsOutEnabled = true;

    public static bool $selfOutEnabled = true;

    public static bool $inlinePropertiesEnabled = true;

    public static bool $arrayValidationHybrid = false;

    private static ?TypeValidatorRegistry $registry = null;

    /**
     * @var array<string, array{class: ?string, function: string, items: list<array{0: string, 1: int, 2: TypeNode, 3: bool}>}>
     */
    private static array $simpleScopePlanCache = [];

    /**
     * @var array<string, TypeNode|false>
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
     * Resets runtime caches.
     */
    public static function reset(): void
    {
        self::$simpleScopePlanCache = [];
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
    }

    /**
     * Returns whether TypePHP is globally enabled in configuration.
     */
    public static function isEnabled(): bool
    {
        return Config::isEnabled();
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
            $normalized = DocblockNormalizer::normalize($typeString);
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize($normalized));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
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
        } catch (\Throwable $e) {
            // Silently ignore unexpected parsing errors
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
            $normalized = DocblockNormalizer::normalize($typeString);
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize($normalized));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
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
        } catch (\Throwable $e) {
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
            $normalized = DocblockNormalizer::normalize($typeString);
            [$typeParser, $lexer] = DocblockExtractor::getTypeParserComponents();
            $tokens = new TokenIterator($lexer->tokenize($normalized));
            $typeNode = $typeParser->parse($tokens);

            if ($file !== '') {
                $typeNode = SpecialTypeResolver::resolveForFile($typeNode, $file);
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
        } catch (\Throwable $e) {
        }

        return $value;
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

        $res = MemberAssignChecker::checkProperty(
            $objectOrClass,
            $propName,
            $chain,
            $value,
            $file,
            self::$registry
        );

        if ($res instanceof ErrorMessage) {
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
            return $factory(...$args);
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

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

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
            $allTemplates = [...$contract['classTemplates'], ...$contract['templates']];
            $cleaner->boundTemplates = TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $allTemplates);

            return $cleaner;
        }

        return null;
    }

    /**
     * @param array{class: ?string, requireObject: bool, function: string, items: list<array{0: string, 1: int, 2: TypeNode, 3: bool}>} $plan
     * @param array<string, mixed> $vars
     */
    private static function executeSimpleScopePlan(array $plan, array $vars): ?ErrorMessage
    {
        if ($vars === []) {
            return null;
        }

        $registry = self::$registry ??= new TypeValidatorRegistry();
        $effectiveFunc = $plan['function'];

        foreach ($plan['items'] as $item) {
            $paramName = $item[0];

            if (isset($vars[$paramName])) {
                $val = $vars[$paramName];
            } elseif (\array_key_exists($paramName, $vars)) {
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
     * @return list<array{0: string, 1: int, 2: TypeNode, 3: bool}>
     */
    private static function compileSimpleScopePlanItems(array $types, array $sensitiveParams): array
    {
        $items = [];
        foreach ($types as $paramName => $typeNode) {
            $isSensitive = $sensitiveParams[$paramName] ?? false;
            $checkKind = self::PARAM_CHECK_FALLBACK;

            if ($typeNode instanceof IdentifierTypeNode) {
                $checkKind = match (strtolower($typeNode->name)) {
                    'int', 'integer' => self::PARAM_CHECK_INT,
                    'string' => self::PARAM_CHECK_STRING,
                    'positive-int' => self::PARAM_CHECK_POSITIVE_INT,
                    'non-empty-string' => self::PARAM_CHECK_NON_EMPTY_STRING,
                    'bool', 'boolean' => self::PARAM_CHECK_BOOL,
                    'float', 'double' => self::PARAM_CHECK_FLOAT,
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
            }

            $items[] = [$paramName, $checkKind, $typeNode, $isSensitive];
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

        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisObj, $thisObj);

        if (isset(SelfOutChecker::$noSelfOutContractCache[$effectiveFunction])) {
            return;
        }

        if (CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return;
        }

        if (IgnoreManager::isCallerIgnored()) {
            return;
        }

        $vars = $vars !== null ? $vars : [];

        SelfOutChecker::checkSelfOut($function, $thisObj, $vars, self::getRegistry(), $effectiveFunction);
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

        $retPlan = self::$simpleReturnPlanCache[$function] ?? null;
        if (
            $retPlan !== null
            && ($retPlan['class'] === null
                ? $thisOrClass === null
                : (\is_object($thisOrClass) ? $thisOrClass::class === $retPlan['class'] : $thisOrClass === $retPlan['class']))
        ) {
            return self::executeSimpleReturnPlan($function, $retPlan['type'], $value);
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
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (isset(self::$simpleReturnPlanCache[$effectiveFunction])) {
            return self::executeSimpleReturnPlan($effectiveFunction, self::$simpleReturnPlanCache[$effectiveFunction]['type'], $value);
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

        if (! $isDynamicDispatch && ($contract['returnUnconstrained'] ?? false)) {
            $unconstrainedPlan = ['class' => $declaringClass, 'type' => false];
            self::$simpleReturnPlanCache[$effectiveFunction] = $unconstrainedPlan;
            if ($function === $effectiveFunction) {
                self::$simpleReturnPlanCache[$function] = $unconstrainedPlan;
            }

            return $value;
        }

        $registry = self::$registry ??= new TypeValidatorRegistry();
        $returnTypeNode = $contract['return'];

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
                    $unconstrainedPlan = ['class' => $declaringClass, 'type' => false];
                    self::$simpleReturnPlanCache[$effectiveFunction] = $unconstrainedPlan;
                    if ($function === $effectiveFunction) {
                        self::$simpleReturnPlanCache[$function] = $unconstrainedPlan;
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
                $compiledPlan = ['class' => $declaringClass, 'type' => $resolvedType];
                self::$simpleReturnPlanCache[$effectiveFunction] = $compiledPlan;
                if ($function === $effectiveFunction) {
                    self::$simpleReturnPlanCache[$function] = $compiledPlan;
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

    private static function executeSimpleReturnPlan(string $function, TypeNode|false $resolvedType, mixed $value): mixed
    {
        if ($resolvedType === false) {
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
     * Validates a value sent into a generator via $gen->send() against TSend.
     */
    public static function checkSend(
        string $function,
        mixed $sendValue,
        object|string|null $thisOrClass = null,
        ?ScopeCleaner $scopeCleaner = null
    ): mixed {
        if (! self::$isEnabled) {
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
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (! isset(CallerBoundaryResolver::$nonBypassCalleeCache[$effectiveFunction]) && CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $value;
        }

        $res = GeneratorChecker::checkYield($function, $key, $value, self::getRegistry(), $thisOrClass, $scopeCleaner, $effectiveFunction);

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
        if (! self::$isEnabled) {
            return $iterable;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;
        $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);

        if (CallerBoundaryResolver::shouldBypass($effectiveFunction)) {
            return $iterable;
        }

        return IterableWrapper::wrap($function, $paramName, $iterable, self::getRegistry(), $thisOrClass);
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
