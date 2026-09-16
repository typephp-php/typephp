<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use TypePHP\Internal\Docblock\DocblockParser;
use TypePHP\Internal\Generics\TemplateManager;
use TypePHP\Internal\Generics\TemplateSubstitutor;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\Config;
use TypePHP\Internal\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates generic state transitions on $this (@self-out, @phpstan-self-out, @psalm-self-out).
 */
final class SelfOutChecker
{
    /**
     * O(1) Fast-path cache for methods determined to have no self-out contracts.
     *
     * @var array<string, true>
     */
    public static array $noSelfOutContractCache = [];

    public static function reset(): void
    {
        self::$noSelfOutContractCache = [];
    }

    /**
     * @param array<int|string, mixed> $vars
     */
    public static function checkSelfOut(
        string $function,
        object $thisObj,
        array $vars,
        TypeValidatorRegistry $registry,
        string $effectiveFunction = ''
    ): void {
        if (! Config::isEnabled()) {
            return;
        }

        if (isset(self::$noSelfOutContractCache[$function])) {
            return;
        }

        if ($effectiveFunction === '') {
            $effectiveFunction = ParamChecker::resolveEffectiveFunction($function, $thisObj, $thisObj);
        }

        if (isset(self::$noSelfOutContractCache[$effectiveFunction])) {
            self::$noSelfOutContractCache[$function] = true;

            return;
        }

        $contract = DocblockParser::parse($effectiveFunction);

        if (! ($contract['hasSelfOutContract'] ?? false) || $contract['selfOut'] === null) {
            self::$noSelfOutContractCache[$effectiveFunction] = true;
            self::$noSelfOutContractCache[$function] = true;

            return;
        }

        $selfOutNode = $contract['selfOut'];
        $allTemplates = [...($contract['classTemplates'] ?? []), ...($contract['templates'] ?? [])];
        $boundTemplates = (\count($allTemplates) > 0)
            ? TemplateManager::getBoundTemplates($effectiveFunction, $thisObj, $allTemplates)
            : [];

        if (\count($boundTemplates) > 0 || \count($allTemplates) > 0) {
            $selfOutNode = TemplateSubstitutor::substitute($selfOutNode, $boundTemplates, $allTemplates);
            $selfOutNode = SpecialTypeResolver::resolve($selfOutNode, $effectiveFunction, $thisObj);
        }

        if (
            $selfOutNode instanceof ConditionalTypeForParameterNode ||
            $selfOutNode instanceof ConditionalTypeNode
        ) {
            $selfOutNode = ConditionalChecker::resolve($selfOutNode, $vars, $boundTemplates, $registry, $effectiveFunction);
        }

        if ($selfOutNode instanceof GenericTypeNode) {
            TemplateManager::bindInstanceFromNode($thisObj, $selfOutNode, forceBind: true);
        }
    }
}
