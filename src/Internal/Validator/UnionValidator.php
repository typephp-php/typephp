<?php

declare(strict_types=1);

namespace TypePHP\Internal\Validator;

use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNullNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Stringable;
use Traversable;
use TypePHP\Internal\Diagnostic\ErrorFactory;
use TypePHP\Internal\Diagnostic\ErrorMessage;
use TypePHP\Internal\Diagnostic\TypeFormatter;
use TypePHP\Internal\Resolver\SpecialTypeResolver;
use TypePHP\Internal\Util\ClassNameValidator;
use WeakMap;

/**
 * @internal Class for validating union types like int | string.
 */
final class UnionValidator implements TypeValidatorInterface
{
    /**
     * Compiled primitive & literal fast-path sets cached per UnionTypeNode.
     *
     * @var WeakMap<UnionTypeNode, array{
     *     hasMixed: bool,
     *     hasObject: bool,
     *     hasInt: bool,
     *     hasString: bool,
     *     hasBool: bool,
     *     hasFloat: bool,
     *     hasNull: bool,
     *     hasTrue: bool,
     *     hasFalse: bool,
     *     hasArray: bool,
     *     hasIterable: bool,
     *     hasScalar: bool,
     *     hasClassString: bool,
     *     stringLiterals: array<string, true>,
     *     intLiterals: array<int, true>,
     *     classes: list<string>
     * }>|null
     */
    private static ?WeakMap $compiledUnionCache = null;

    public function validate(mixed $value, TypeNode $node, string $context, TypeValidatorRegistry $registry, bool $isSensitive = false): ?ErrorMessage
    {
        /** @var UnionTypeNode $unionNode */
        $unionNode = $node;

        self::$compiledUnionCache ??= new WeakMap();
        $compiled = self::$compiledUnionCache[$unionNode] ??= self::compileUnion($unionNode);

        if ($compiled['hasMixed']) {
            return null;
        }

        if ($value === null && $compiled['hasNull']) {
            return null;
        }

        if (\is_int($value) && ($compiled['hasInt'] || $compiled['hasFloat'] || $compiled['hasScalar'] || isset($compiled['intLiterals'][$value]))) {
            return null;
        }

        if (\is_string($value) && ($compiled['hasString'] || $compiled['hasScalar'] || isset($compiled['stringLiterals'][$value]) || ($compiled['hasClassString'] && ClassNameValidator::isValidClassString($value)))) {
            return null;
        }

        if (\is_bool($value) && ($compiled['hasBool'] || $compiled['hasScalar'] || ($value ? $compiled['hasTrue'] : $compiled['hasFalse']))) {
            return null;
        }

        if (\is_float($value) && ($compiled['hasFloat'] || $compiled['hasScalar'])) {
            return null;
        }

        if (\is_array($value) && ($compiled['hasArray'] || $compiled['hasIterable'])) {
            return null;
        }

        $isObj = \is_object($value);
        if ($isObj) {
            if ($compiled['hasObject'] || ($compiled['hasIterable'] && $value instanceof Traversable)) {
                return null;
            }
            foreach ($compiled['classes'] as $cls) {
                if ($value instanceof $cls) {
                    return null;
                }
            }
        }

        $isArr = \is_array($value);
        $deepErrors = [];
        $discriminatorMatchedErrors = [];
        $hasAnyDiscriminator = false;

        foreach ($unionNode->types as $type) {
            if ($type instanceof IdentifierTypeNode) {
                $tLower = strtolower($type->name);
                if (
                    ($tLower === 'null' && $value !== null)
                    || (($tLower === 'int' || $tLower === 'integer') && ! \is_int($value))
                    || ($tLower === 'string' && ! \is_string($value))
                    || (($tLower === 'bool' || $tLower === 'boolean') && ! \is_bool($value))
                    || ($tLower === 'object' && ! $isObj)
                    || ($tLower === 'array' && ! $isArr)
                    || (! $isObj && ! SpecialTypeResolver::isBuiltInTypeKeyword($type->name) && ClassNameValidator::isValid($type->name) && (class_exists($type->name, false) || interface_exists($type->name, false)))
                ) {
                    continue;
                }
            } elseif (! $isObj && $type instanceof ObjectShapeNode) {
                continue;
            } elseif (! $isArr && $type instanceof ArrayShapeNode) {
                continue;
            }

            $err = $registry->validate($value, $type, $context, $isSensitive);
            if ($err === null) {
                return null;
            }

            $msg = $err->getMessage();

            $isDeep = (
                str_starts_with($msg, $context . '[') ||
                str_starts_with($msg, $context . '->') ||
                str_starts_with($msg, $context . ' is missing required') ||
                str_starts_with($msg, $context . ' contains unsealed') ||
                str_starts_with($msg, $context . ' contains unexpected') ||
                str_starts_with($msg, $context . ' property') ||
                str_starts_with($msg, $context . ' key') ||
                str_starts_with($msg, $context . ' value') ||
                str_starts_with($msg, $context . ' extra key')
            );

            if ($isDeep) {
                $deepErrors[] = $err;

                $discStatus = self::checkDiscriminatorStatus($value, $type);
                if ($discStatus['hasDiscriminator']) {
                    $hasAnyDiscriminator = true;
                    if ($discStatus['isMatched']) {
                        $discriminatorMatchedErrors[] = $err;
                    }
                }
            }
        }

        if (\count($discriminatorMatchedErrors) > 0) {
            return $discriminatorMatchedErrors[0];
        }

        if ($hasAnyDiscriminator) {
            return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
        }

        if ($deepErrors !== []) {
            if (\count($deepErrors) === 1) {
                return $deepErrors[0];
            }

            $bestError = $deepErrors[0];
            $bestDepth = self::extractErrorPathDepth($bestError->getMessage(), $context);

            $deepCount = \count($deepErrors);
            for ($i = 1; $i < $deepCount; $i++) {
                $candidate = $deepErrors[$i];
                $depth = self::extractErrorPathDepth($candidate->getMessage(), $context);
                if ($depth > $bestDepth) {
                    $bestDepth = $depth;
                    $bestError = $candidate;
                }
            }

            return $bestError;
        }

        return ErrorFactory::createError($context . ' must be of type ' . $unionNode . ', ' . TypeFormatter::formatGivenValue($value, $isSensitive) . ' given');
    }

    /**
     * Counts the number of nested member/dimension path segments immediately following $context
     * so union error reporting selects the branch that matched deepest into the value.
     */
    private static function extractErrorPathDepth(string $message, string $context): int
    {
        $suffix = $context !== '' && str_starts_with($message, $context)
            ? substr($message, \strlen($context))
            : $message;

        if (preg_match('/^(?:\[[^\]]*\]|->[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)+/', $suffix, $m) !== 1) {
            return 0;
        }

        return (int) preg_match_all('/\[[^\]]*\]|->[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*/', $m[0]);
    }

    /**
     * Inspects a type branch to see if it defines a discriminator field and whether the input value matches it.
     *
     * @return array{hasDiscriminator: bool, isMatched: bool}
     */
    private static function checkDiscriminatorStatus(mixed $value, TypeNode $type): array
    {
        if (\is_array($value) && $type instanceof ArrayShapeNode) {
            foreach ($type->items as $item) {
                if ($item->valueType instanceof ConstTypeNode) {
                    $key = null;
                    if ($item->keyName instanceof ConstExprStringNode) {
                        $key = $item->keyName->value;
                    } elseif ($item->keyName instanceof IdentifierTypeNode) {
                        $key = $item->keyName->name;
                    } elseif ($item->keyName instanceof ConstExprIntegerNode) {
                        $key = (int) $item->keyName->value;
                    }

                    if ($key !== null) {
                        $expectedVal = (string) $item->valueType->constExpr;
                        $expectedVal = trim($expectedVal, '\'"');

                        if (isset($value[$key])) {
                            $actualVal = $value[$key];
                            $actualStr = \is_scalar($actualVal) || $actualVal instanceof Stringable ? (string) $actualVal : null;

                            if ($actualStr === $expectedVal) {
                                return ['hasDiscriminator' => true, 'isMatched' => true];
                            }

                            return ['hasDiscriminator' => true, 'isMatched' => false];
                        }
                    }
                }
            }
        }

        if (\is_object($value) && $type instanceof ObjectShapeNode) {
            foreach ($type->items as $item) {
                if ($item->valueType instanceof ConstTypeNode) {
                    $prop = (string) $item->keyName;
                    $expectedVal = (string) $item->valueType->constExpr;
                    $expectedVal = trim($expectedVal, '\'"');

                    // @phpstan-ignore property.dynamicName
                    if (isset($value->$prop)) {
                        // @phpstan-ignore property.dynamicName
                        $actualVal = $value->$prop;
                        $actualStr = \is_scalar($actualVal) || $actualVal instanceof Stringable ? (string) $actualVal : null;

                        if ($actualStr === $expectedVal) {
                            return ['hasDiscriminator' => true, 'isMatched' => true];
                        }

                        return ['hasDiscriminator' => true, 'isMatched' => false];
                    }
                }
            }
        }

        return ['hasDiscriminator' => false, 'isMatched' => false];
    }

    /**
     * @return array{
     *     hasMixed: bool,
     *     hasObject: bool,
     *     hasInt: bool,
     *     hasString: bool,
     *     hasBool: bool,
     *     hasFloat: bool,
     *     hasNull: bool,
     *     hasTrue: bool,
     *     hasFalse: bool,
     *     hasArray: bool,
     *     hasIterable: bool,
     *     hasScalar: bool,
     *     hasClassString: bool,
     *     stringLiterals: array<string, true>,
     *     intLiterals: array<int, true>,
     *     classes: list<string>
     * }
     */
    private static function compileUnion(UnionTypeNode $unionNode): array
    {
        $hasMixed = false;
        $hasObject = false;
        $hasInt = false;
        $hasString = false;
        $hasBool = false;
        $hasFloat = false;
        $hasNull = false;
        $hasTrue = false;
        $hasFalse = false;
        $hasArray = false;
        $hasIterable = false;
        $hasScalar = false;
        $hasClassString = false;
        $stringLiterals = [];
        $intLiterals = [];
        $classes = [];

        foreach ($unionNode->types as $type) {
            if ($type instanceof IdentifierTypeNode) {
                $name = $type->name;
                switch (strtolower($name)) {
                    case 'mixed':
                        $hasMixed = true;

                        break;
                    case 'object':
                        $hasObject = true;

                        break;
                    case 'int':
                    case 'integer':
                        $hasInt = true;

                        break;
                    case 'string':
                        $hasString = true;

                        break;
                    case 'bool':
                    case 'boolean':
                        $hasBool = true;

                        break;
                    case 'float':
                    case 'double':
                        $hasFloat = true;

                        break;
                    case 'null':
                        $hasNull = true;

                        break;
                    case 'true':
                        $hasTrue = true;

                        break;
                    case 'false':
                        $hasFalse = true;

                        break;
                    case 'array':
                        $hasArray = true;

                        break;
                    case 'iterable':
                        $hasIterable = true;

                        break;
                    case 'scalar':
                        $hasScalar = true;

                        break;
                    case 'class-string':
                        $hasClassString = true;

                        break;
                    default:
                        if ($type->getAttribute('typephp_recursive_alias') === null && ! SpecialTypeResolver::isBuiltInTypeKeyword($name) && ClassNameValidator::isValid($name) && (class_exists($name, false) || interface_exists($name, false))) {
                            $classes[] = $name;
                        }

                        break;
                }
            } elseif ($type instanceof ConstTypeNode) {
                $constExpr = $type->constExpr;
                if ($constExpr instanceof ConstExprStringNode) {
                    $stringLiterals[$constExpr->value] = true;
                } elseif ($constExpr instanceof ConstExprIntegerNode) {
                    $intLiterals[(int) $constExpr->value] = true;
                } elseif ($constExpr instanceof ConstExprTrueNode) {
                    $hasTrue = true;
                } elseif ($constExpr instanceof ConstExprFalseNode) {
                    $hasFalse = true;
                } elseif ($constExpr instanceof ConstExprNullNode) {
                    $hasNull = true;
                }
            }
        }

        return [
            'hasMixed' => $hasMixed,
            'hasObject' => $hasObject,
            'hasInt' => $hasInt,
            'hasString' => $hasString,
            'hasBool' => $hasBool,
            'hasFloat' => $hasFloat,
            'hasNull' => $hasNull,
            'hasTrue' => $hasTrue,
            'hasFalse' => $hasFalse,
            'hasArray' => $hasArray,
            'hasIterable' => $hasIterable,
            'hasScalar' => $hasScalar,
            'hasClassString' => $hasClassString,
            'stringLiterals' => $stringLiterals,
            'intLiterals' => $intLiterals,
            'classes' => $classes,
        ];
    }
}
