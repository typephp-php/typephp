<?php

declare(strict_types=1);

namespace TypePHP\Resolver;

use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\CallableTypeParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeForParameterNode;
use PHPStan\PhpDocParser\Ast\Type\ConditionalTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;

/**
 * @internal Recursively substitutes template placeholders (like T) with their bound concrete types (like int).
 */
final class TemplateSubstitutor
{
    /**
     * Recursively substitutes template placeholders (like T) with their bound concrete types (like int).
     *
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    public static function substitute(TypeNode $node, array $boundTemplates, array $declaredTemplates = []): TypeNode
    {
        if (\count($boundTemplates) === 0 && \count($declaredTemplates) === 0) {
            return $node;
        }

        if ($node instanceof IdentifierTypeNode) {
            return self::substituteIdentifier($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof CallableTypeNode) {
            return self::substituteCallable($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof ConditionalTypeNode) {
            return self::substituteConditional($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof ConditionalTypeForParameterNode) {
            return self::substituteParameterConditional($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof ArrayTypeNode) {
            return new ArrayTypeNode(self::substitute($node->type, $boundTemplates, $declaredTemplates));
        }

        if ($node instanceof GenericTypeNode) {
            return self::substituteGeneric($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof NullableTypeNode) {
            return new NullableTypeNode(self::substitute($node->type, $boundTemplates, $declaredTemplates));
        }

        if ($node instanceof UnionTypeNode) {
            return new UnionTypeNode(array_map(
                fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
                $node->types
            ));
        }

        if ($node instanceof IntersectionTypeNode) {
            return new IntersectionTypeNode(array_map(
                fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
                $node->types
            ));
        }

        if ($node instanceof ArrayShapeNode) {
            return self::substituteArrayShape($node, $boundTemplates, $declaredTemplates);
        }

        if ($node instanceof ObjectShapeNode) {
            return self::substituteObjectShape($node, $boundTemplates, $declaredTemplates);
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteIdentifier(
        IdentifierTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): TypeNode {
        if (isset($boundTemplates[$node->name])) {
            return $boundTemplates[$node->name];
        }

        if (isset($declaredTemplates[$node->name])) {
            $templateTag = $declaredTemplates[$node->name];

            return $templateTag->default ?? $templateTag->bound ?? new IdentifierTypeNode('mixed');
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteCallable(
        CallableTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): CallableTypeNode {
        $parameters = array_map(
            fn (CallableTypeParameterNode $param) => new CallableTypeParameterNode(
                self::substitute($param->type, $boundTemplates, $declaredTemplates),
                $param->isReference,
                $param->isVariadic,
                $param->parameterName,
                $param->isOptional
            ),
            $node->parameters
        );

        $returnType = self::substitute($node->returnType, $boundTemplates, $declaredTemplates);

        return new CallableTypeNode(
            $node->identifier,
            $parameters,
            $returnType,
            $node->templateTypes
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteConditional(
        ConditionalTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): ConditionalTypeNode {
        return new ConditionalTypeNode(
            self::substitute($node->subjectType, $boundTemplates, $declaredTemplates),
            self::substitute($node->targetType, $boundTemplates, $declaredTemplates),
            self::substitute($node->if, $boundTemplates, $declaredTemplates),
            self::substitute($node->else, $boundTemplates, $declaredTemplates),
            $node->negated
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteParameterConditional(
        ConditionalTypeForParameterNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): ConditionalTypeForParameterNode {
        return new ConditionalTypeForParameterNode(
            $node->parameterName,
            self::substitute($node->targetType, $boundTemplates, $declaredTemplates),
            self::substitute($node->if, $boundTemplates, $declaredTemplates),
            self::substitute($node->else, $boundTemplates, $declaredTemplates),
            $node->negated
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteGeneric(
        GenericTypeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): GenericTypeNode {
        $type = self::substitute($node->type, $boundTemplates, $declaredTemplates);
        $genericTypes = array_map(
            fn ($t) => self::substitute($t, $boundTemplates, $declaredTemplates),
            $node->genericTypes
        );

        return new GenericTypeNode(
            $type instanceof IdentifierTypeNode ? $type : $node->type,
            $genericTypes,
            $node->variances
        );
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteArrayShape(
        ArrayShapeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): ArrayShapeNode {
        foreach ($node->items as $item) {
            $item->valueType = self::substitute($item->valueType, $boundTemplates, $declaredTemplates);
        }

        if ($node->unsealedType !== null) {
            if ($node->unsealedType->keyType !== null) {
                $node->unsealedType->keyType = self::substitute($node->unsealedType->keyType, $boundTemplates, $declaredTemplates);
            }
            $node->unsealedType->valueType = self::substitute($node->unsealedType->valueType, $boundTemplates, $declaredTemplates);
        }

        return $node;
    }

    /**
     * @param array<string, TypeNode> $boundTemplates
     * @param array<string, TemplateTagValueNode> $declaredTemplates
     */
    private static function substituteObjectShape(
        ObjectShapeNode $node,
        array $boundTemplates,
        array $declaredTemplates
    ): ObjectShapeNode {
        foreach ($node->items as $item) {
            $item->valueType = self::substitute($item->valueType, $boundTemplates, $declaredTemplates);
        }

        return $node;
    }
}
