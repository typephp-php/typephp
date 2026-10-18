<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates by-reference parameter post-conditions (@param-out, @phpstan-param-out, @psalm-param-out).
 */
final class ParamOutChecker
{
    /**
     * O(1) Fast-path cache for methods determined to have no param-out contracts.
     *
     * @var array<string, true>
     */
    public static array $noParamOutContractCache = [];

    public static function reset(): void
    {
        self::$noParamOutContractCache = [];
    }

    public static function checkParamOut(
        string $function,
        string $paramName,
        mixed $value,
        object|string|null $thisOrClass,
        TypeValidatorRegistry $registry,
        string $effectiveFunction = ''
    ): ?ErrorMessage {
        if (! Config::isParamsEnabled()) {
            return null;
        }

        if (isset(self::$noParamOutContractCache[$function])) {
            return null;
        }

        $thisObj = \is_object($thisOrClass) ? $thisOrClass : null;

        if ($effectiveFunction === '') {
            $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisOrClass, $thisObj);
        }

        if (isset(self::$noParamOutContractCache[$effectiveFunction])) {
            self::$noParamOutContractCache[$function] = true;

            return null;
        }

        $contract = DocblockParser::parse($effectiveFunction);

        if (! ($contract['hasParamOutContract'] ?? false)) {
            self::$noParamOutContractCache[$effectiveFunction] = true;
            self::$noParamOutContractCache[$function] = true;

            return null;
        }

        $paramOuts = $contract['paramOuts'] ?? [];

        if (! isset($paramOuts[$paramName])) {
            return null;
        }

        $typeNode = $paramOuts[$paramName];
        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $boundTemplates = (\count($allTemplates) > 0)
            ? TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $allTemplates)
            : [];

        if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
            $typeNode = TemplateSubstitutor::substitute($typeNode, $boundTemplates, $allTemplates);
        }
        $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);

        $context = $effectiveFunction . '(): Argument &$' . $paramName . ' (param-out)';

        $err = $registry->validate($value, $typeNode, $context);
        if ($err !== null) {
            return $err;
        }

        return null;
    }
}
