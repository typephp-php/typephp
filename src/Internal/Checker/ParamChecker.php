<?php

declare(strict_types=1);

namespace TypePHP\Internal\Checker;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use TypePHP\Contract\ContractParser;
use TypePHP\Internal\ClassNameValidator;
use TypePHP\Internal\Config;
use TypePHP\Internal\ErrorFactory;
use TypePHP\Internal\ErrorMessage;
use TypePHP\Internal\TypeFormatter;
use TypePHP\Resolver\SpecialTypeResolver;
use TypePHP\Resolver\TemplateManager;
use TypePHP\Validator\TypeValidatorRegistry;

/**
 * @internal Evaluates function and method parameter contract validations.
 */
final class ParamChecker
{
    /**
     * @param array<string, mixed> $vars
     */
    public static function checkParams(string $function, array $vars, ?object $thisObj, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        if (! (bool) (Config::get()['params'] ?? true)) {
            return null;
        }

        $effectiveFunction = $function;
        if ($thisObj !== null && str_contains($function, '::')) {
            [$classOrTrait, $methodName] = explode('::', $function, 2);
            $actualClassName = \get_class($thisObj);
            if ($actualClassName !== $classOrTrait) {
                $effectiveFunction = $actualClassName . '::' . $methodName;
            }
        }

        $contract = ContractParser::parse($effectiveFunction);
        if (\count($contract['types']) === 0) {
            return null;
        }

        $templates = $contract['templates'];
        $aliases = $contract['aliases'];

        if ($thisObj === null) {
            TemplateManager::clearCallBindings($effectiveFunction, $templates);
        } elseif (str_contains($effectiveFunction, '::')) {
            $declaringClass = explode('::', $effectiveFunction, 2)[0];
            TemplateManager::resolveInheritedTemplates($thisObj, $declaringClass);
        }

        foreach ($contract['types'] as $paramName => $typeNode) {
            if (! \array_key_exists($paramName, $vars)) {
                continue;
            }

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            $typeNode = SpecialTypeResolver::resolve($typeNode, $effectiveFunction, $thisObj);
            $val = $vars[$paramName];

            if ($typeNode instanceof IdentifierTypeNode && isset($aliases[$typeNode->name])) {
                $typeNode = $aliases[$typeNode->name];
            }

            if ($typeNode instanceof GenericTypeNode && self::isClassStringTemplate($typeNode, $templates)) {
                $err = self::resolveClassStringTemplate($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            if (self::getTemplateName($typeNode, $templates) !== null) {
                $err = self::resolveTemplateParam($typeNode, $val, $paramName, $effectiveFunction, $thisObj, $templates, $registry);
                if ($err !== null) {
                    return $err;
                }

                continue;
            }

            $err = $registry->validate($val, $typeNode, $effectiveFunction . '(): Argument $' . $paramName);
            if ($err !== null) {
                return $err;
            }
        }

        return null;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function isClassStringTemplate(GenericTypeNode $typeNode, array $templates): bool
    {
        return isset($typeNode->genericTypes[0])
            && $typeNode->genericTypes[0] instanceof IdentifierTypeNode
            && strtolower($typeNode->type->name) === 'class-string'
            && isset($templates[$typeNode->genericTypes[0]->name]);
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveClassStringTemplate(GenericTypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates): ?ErrorMessage
    {
        /** @var IdentifierTypeNode $innerType */
        $innerType = $typeNode->genericTypes[0];
        $templateName = $innerType->name;
        $templateNode = $templates[$templateName];

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            if (! \is_string($val) || ! ClassNameValidator::isValid($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
            }

            if ($templateNode->bound !== null) {
                $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
                $boundName = $resolvedBound instanceof IdentifierTypeNode ? $resolvedBound->name : (string) $resolvedBound;
                $lowerBound = strtolower($boundName);

                if ($lowerBound === 'object' || $lowerBound === 'mixed') {
                    if (! ClassNameValidator::isValid($val) || (! class_exists($val) && ! interface_exists($val) && ! trait_exists($val) && ! enum_exists($val))) {
                        return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a valid class-string, ' . TypeFormatter::formatGivenValue($val) . ' given');
                    }
                } elseif (! is_a($val, $boundName, true)) {
                    return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' (class-string<' . $templateName . '>) must be a class-string of ' . $boundName . ", '" . $val . "' given");
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, new IdentifierTypeNode($val));
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);
            $targetClass = $expectedTypeNode instanceof IdentifierTypeNode ? $expectedTypeNode->name : (string) $expectedTypeNode;

            if (! \is_string($val) || ! is_a($val, $targetClass, true)) {
                $valStr = TypeFormatter::formatGivenValue($val);

                return ErrorFactory::createError($function . '(): Argument $' . $paramName . ' must be a class-string of ' . $targetClass . ', ' . $valStr . ' given');
            }
        }

        return null;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function getTemplateName(TypeNode $typeNode, array $templates): ?string
    {
        if ($typeNode instanceof IdentifierTypeNode && isset($templates[$typeNode->name])) {
            return $typeNode->name;
        }

        if ($typeNode instanceof ArrayTypeNode && $typeNode->type instanceof IdentifierTypeNode && isset($templates[$typeNode->type->name])) {
            return $typeNode->type->name;
        }

        return null;
    }

    /**
     * @param array<string, TemplateTagValueNode> $templates
     */
    private static function resolveTemplateParam(TypeNode $typeNode, mixed $val, string $paramName, string $function, ?object $thisObj, array $templates, TypeValidatorRegistry $registry): ?ErrorMessage
    {
        $templateName = self::getTemplateName($typeNode, $templates);
        if ($templateName === null || ! isset($templates[$templateName])) {
            return null;
        }

        $templateNode = $templates[$templateName];
        $isVariadic = $typeNode instanceof ArrayTypeNode;

        if (! TemplateManager::isBound($function, $thisObj, $templateName)) {
            $sampleVal = ($isVariadic && \is_array($val)) ? ($val[0] ?? null) : $val;
            $inferredType = TemplateManager::inferTypeFromValue($sampleVal);

            if ($templateNode->bound !== null) {
                $resolvedBound = SpecialTypeResolver::resolve($templateNode->bound, $function, $thisObj);
                $err = $registry->validate($sampleVal, $resolvedBound, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ')');
                if ($err !== null) {
                    return $err;
                }
            }

            TemplateManager::bindTemplate($function, $thisObj, $templateName, $inferredType);

            if ($isVariadic && \is_array($val)) {
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $inferredType, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $inferredType . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            }
        } else {
            $expectedTypeNode = TemplateManager::getBoundType($function, $thisObj, $templateName);
            if ($expectedTypeNode === null) {
                return null;
            }

            if ($isVariadic && \is_array($val)) {
                foreach ($val as $idx => $item) {
                    $err = $registry->validate($item, $expectedTypeNode, $function . '(): Argument $' . $paramName . '[' . $idx . '] (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                    if ($err !== null) {
                        return $err;
                    }
                }
            } else {
                $err = $registry->validate($val, $expectedTypeNode, $function . '(): Argument $' . $paramName . ' (template ' . $templateName . ' = ' . $expectedTypeNode . ')');
                if ($err !== null) {
                    return $err;
                }
            }
        }

        return null;
    }
}
